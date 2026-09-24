<?php

declare(strict_types=1);

namespace app\optional;

use app\optional\Model\Forum;
use app\optional\Model\Group;
use app\optional\Model\Setting;
use app\optional\Model\User;
use PDO;
use Throwable;

if (!defined('APP_ROOT')) exit;

/**
 * 首次访问时的自动初始化：建 app/data/、建表建索引、按环境变量播种用户组与管理员。
 * 取代了早期的网页安装向导；已有数据的库只补写初始化标记，不会覆盖。
 */
final class Bootstrap
{
    /**
     * 由 index.php 在 install.lock 不存在时调用，flock 保证并发下只跑一次。
     */
    public static function run(): void
    {
        if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) self::fail('app/data/ 目录无法创建，请检查目录权限。');
        if (!is_writable(DATA_DIR)) self::fail('app/data/ 目录不可写，请赋予 PHP 写入权限。');
        $lock = @fopen(DATA_DIR . '/.bootstrap.lock', 'c+');
        if (!is_resource($lock)) self::fail('app/data/.bootstrap.lock 无法创建，请检查目录权限。');
        flock($lock, LOCK_EX);
        try {
            if (is_file(INSTALL_LOCK_FILE)) return;
            try {
                $db = db();
            } catch (Throwable $e) {
                self::fail('数据库连接失败：' . $e->getMessage());
            }
            [$tables, $indexes] = self::schema();
            foreach ($tables as $table => $sql) if (!app_db_table_exists($db, $table)) $db->exec($sql);
            foreach ($indexes as $index => $sql) {
                if (app_db_index_exists($db, $index, self::index_table($sql))) continue;
                $db->exec($sql);
            }
            $state = self::state($db);
            if ($state === 'partial') self::fail('数据库中已有用户数据，但初始化记录不完整。请恢复原数据或改用空数据库。');
        if ($state === 'empty') {
            Group::upsert([
                ['id' => 1, 'name' => '管理员', 'allow_manage' => 1, 'allow_admin' => 1],
                ['id' => 2, 'name' => '会员', 'allow_manage' => 0, 'allow_admin' => 0],
            ], ['id'], ['name', 'allow_manage', 'allow_admin']);
            Forum::upsert([
                ['id' => 1, 'name' => self::env('FORUM_NAME') ?: '默认版块', 'description' => '欢迎发帖', 'sort' => 0],
            ], ['id'], ['name', 'description', 'sort']);
            $settings = default_settings();
            $settings['site_name'] = self::env('SITE_NAME') ?: 'FORUM';
            $rows = [];
            foreach ($settings as $name => $value) $rows[] = ['name' => (string)$name, 'value' => (string)$value];
            Setting::upsert($rows, ['name'], ['value']);
            $welcome_ts = now();
            User::create([
                'username' => self::env('ADMIN_USERNAME') ?: 'admin',
                'password' => password_hash(self::admin_password(), PASSWORD_DEFAULT),
                'email' => self::env('ADMIN_EMAIL') ?: 'admin@example.com',
                'bio' => '站点管理员',
                'group_id' => 1,
                'last_post_at' => $welcome_ts,
                'created_at' => $welcome_ts,
            ]);
            error_log('[forum] 初始化完成：已创建默认版块和管理员账号。');
        } else {
                error_log('[forum] 检测到已有数据，已补写初始化标记。');
            }
            if (file_put_contents(INSTALL_LOCK_FILE, (string)now(), LOCK_EX) === false) self::fail('初始化标记写入失败，请检查 app/data/ 目录权限。');
            forums_cache(true);
            groups_cache(true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function env(string $name): string
    {
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }

    /**
     * 返回 [表名 => 建表语句, 索引名 => 建索引语句]。
     */
    public static function schema(): array
    {
        $types = app_db_types();
        $id = $types['id'];
        $uint = $types['uint'];
        $short = $types['string'];
        $key = $types['key'];
        $long = $types['text'];
        $tables = [
            'app_groups' => "CREATE TABLE app_groups(id $id,name $key NOT NULL UNIQUE,allow_manage INTEGER NOT NULL DEFAULT 0,allow_admin INTEGER NOT NULL DEFAULT 0)",
            'app_users' => "CREATE TABLE app_users(id $id,username $key NOT NULL UNIQUE,password $short NOT NULL,email $key NOT NULL DEFAULT '',bio $long NOT NULL,group_id $uint NOT NULL DEFAULT 2,is_muted INTEGER NOT NULL DEFAULT 0,unread_notifications $uint NOT NULL DEFAULT 0,last_post_at $uint NOT NULL DEFAULT 0,created_at $uint NOT NULL)",
            'app_notifications' => "CREATE TABLE app_notifications(id $id,recipient_id $uint NOT NULL,sender_id $uint DEFAULT NULL,kind $short NOT NULL DEFAULT 'mention',content $long NOT NULL,topic_id $uint DEFAULT NULL,reply_id $uint DEFAULT NULL,read_at $uint NOT NULL DEFAULT 0,created_at $uint NOT NULL)",
            'app_forums' => "CREATE TABLE app_forums(id $id,name $short NOT NULL,description $long NOT NULL,sort $uint NOT NULL DEFAULT 0,allow_view_groups $short NOT NULL DEFAULT '',allow_post_groups $short NOT NULL DEFAULT '',allow_reply_groups $short NOT NULL DEFAULT '')",
            'app_topics' => "CREATE TABLE app_topics(id $id,forum_id $uint NOT NULL,user_id $uint NOT NULL,title $short NOT NULL,body $long NOT NULL,highlight_style $short NOT NULL DEFAULT '',reply_order INTEGER NOT NULL DEFAULT 0,reply_count $uint NOT NULL DEFAULT 0,view_count $uint NOT NULL DEFAULT 0,last_reply_at $uint NOT NULL DEFAULT 0,last_reply_user_id $uint NOT NULL DEFAULT 0,created_at $uint NOT NULL)",
            'app_topics_del' => "CREATE TABLE app_topics_del(id $id,topic_id $uint NOT NULL,reply_id $uint NOT NULL,created_at $uint NOT NULL)",
            'app_replies' => "CREATE TABLE app_replies(id $id,topic_id $uint NOT NULL,user_id $uint NOT NULL,body $long NOT NULL,created_at $uint NOT NULL,updated_at $uint NOT NULL)",
            'app_settings' => "CREATE TABLE app_settings(name $key PRIMARY KEY,value $long NOT NULL)",
        ];
        $indexes = [
            'idx_users_group' => 'app_users(group_id)', 'idx_users_email' => 'app_users(email)', 'idx_forums_sort' => 'app_forums(sort,id)',
            'idx_replies_topic_time' => 'app_replies(topic_id,created_at,id)',
            'idx_topics_del_topic' => 'app_topics_del(topic_id,created_at,id)',
            'idx_topics_del_reply' => 'app_topics_del(reply_id)',
            'idx_replies_user_time' => 'app_replies(user_id,created_at DESC,id DESC)',
            'idx_notifications_recipient_unread' => 'app_notifications(recipient_id,read_at)',
            'idx_notifications_recipient_time' => 'app_notifications(recipient_id,created_at DESC,id DESC)',
            'idx_notifications_sender_time' => 'app_notifications(sender_id,created_at DESC,id DESC)',
            'idx_topics_created' => 'app_topics(created_at DESC,id DESC)', 'idx_topics_last_reply' => 'app_topics(last_reply_at DESC,id DESC)',
            'idx_topics_user_created' => 'app_topics(user_id,created_at DESC,id DESC)', 'idx_topics_forum_created' => 'app_topics(forum_id,created_at DESC,id DESC)',
            'idx_topics_forum_last_reply' => 'app_topics(forum_id,last_reply_at DESC,id DESC)',
        ];
        foreach ($indexes as $name => &$target) $target = 'CREATE INDEX ' . $name . ' ON ' . $target;
        unset($target);
        return [$tables, $indexes];
    }

    public static function index_table(string $sql): string
    {
        return preg_match('/\bON\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)/i', $sql, $match) ? $match[1] : '';
    }

    /**
     * 未设置 ADMIN_PASSWORD 时生成随机密码，落盘到 app/data/admin-password.txt。
     */
    public static function admin_password(): string
    {
        $password = (string)(getenv('ADMIN_PASSWORD') ?: '');
        if ($password !== '') return $password;
        $password = bin2hex(random_bytes(8));
        @file_put_contents(DATA_DIR . '/admin-password.txt', $password . "\n", LOCK_EX);
        error_log('[forum] 未设置 ADMIN_PASSWORD，已生成随机管理员密码并保存到 app/data/admin-password.txt，请尽快登录后修改。');
        return $password;
    }

    /**
     * empty 需要播种，partial 说明库里有数据但结构不全，installed 可直接放行。
     */
    public static function state(PDO $db): string
    {
        if (!app_db_table_exists($db, 'app_users') || $db->query('SELECT id FROM app_users ORDER BY id LIMIT 1')->fetchColumn() === false) return 'empty';
        foreach (['app_settings', 'app_groups', 'app_forums', 'app_topics', 'app_replies'] as $table) if (!app_db_table_exists($db, $table)) return 'partial';
        $site = $db->query("SELECT value FROM app_settings WHERE name='site_name' LIMIT 1")->fetchColumn();
        return $site === false ? 'partial' : 'installed';
    }

    private static function fail(string $message): never
    {
        error_log('[forum] 初始化失败：' . $message);
        self::render_failure_page('初始化失败', $message);
    }

    /**
     * 失败页用独立文档渲染：此时站点导航、模板继承都不保证可用。
     * 走无缓存渲染——Twig 缓存目录在 app/data/ 下，而该目录不可写正是最常见的失败原因。
     */
    private static function render_failure_page(string $title, string $message): never
    {
        echo template_uncached('setup_layout.html.twig', ['title' => $title, 'message' => $message]);
        exit;
    }
}
