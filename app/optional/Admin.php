<?php
declare(strict_types=1);

namespace app\optional;

use app\optional\Model\Forum;
use app\optional\Model\Group;
use app\optional\Model\Reply;
use app\optional\Model\Topic;
use app\optional\Model\User;
use app\optional\Model\ViewStat;

if (!defined('APP_ROOT')) exit;

final class Admin
{
    public static function clear_opcache_cache(): bool
    {
        if (!function_exists('opcache_reset')) return false;
        try { return (bool)opcache_reset(); } catch (\Throwable) { return false; }
    }

    public static function save_settings(): void
    {
        $site_name = post('site_name', DB_STRING_MAX_LENGTH);
        if ($site_name === '') err('网站名不能为空');
        $gid = max(1, (int)($_POST['default_group_id'] ?? 2));
        if (!group_by_id($gid)) err('默认用户组不存在');
        $values = ['site_name' => $site_name, 'site_base_url' => clean_site_base_url((string)($_POST['site_base_url'] ?? '')), 'pinned_topic_ids' => preg_replace('/[^\d,]/', '', (string)($_POST['pinned_topic_ids'] ?? '')) ?: '', 'default_group_id' => (string)$gid];
        foreach (['site_name_title', 'site_keywords'] as $key) $values[$key] = post($key, DB_STRING_MAX_LENGTH);
        $values['site_description'] = post('site_description', DB_TEXT_MAX_LENGTH);
        foreach (['site_closed', 'debug_mode', 'allow_register'] as $key) $values[$key] = isset($_POST[$key]) ? '1' : '0';
        foreach (['pc_nav_forum_count' => [0, 20, 6], 'topics_per_page' => [1, 200, 30], 'replies_per_page' => [1, 200, 50], 'max_pagination_pages' => [1, 1000, 50], 'post_interval_seconds' => [0, 3600, 5]] as $key => [$min, $max, $default]) $values[$key] = (string)min($max, max($min, (int)($_POST[$key] ?? $default)));
        $length_fields = [
            'username' => [1, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH], 'email' => [0, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'bio' => [0, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH], 'search' => [2, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH],
            'title' => [1, DB_STRING_MAX_LENGTH, DB_STRING_MAX_LENGTH], 'topic_body' => [1, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
            'reply_body' => [1, DB_TEXT_MAX_LENGTH, DB_TEXT_MAX_LENGTH],
        ];
        foreach ($length_fields as $key => [$default_min, $default_max, $hard_max]) {
            $min = min($hard_max, max(0, (int)($_POST[$key . '_min_length'] ?? $default_min)));
            $max = min($hard_max, max($min, (int)($_POST[$key . '_max_length'] ?? $default_max)));
            $values[$key . '_min_length'] = (string)$min;
            $values[$key . '_max_length'] = (string)$max;
        }
        $values['excerpt_length'] = (string)min(DB_TEXT_MAX_LENGTH, max(0, (int)($_POST['excerpt_length'] ?? 200)));
        save_settings_values($values);
    }

    public static function save_forum(): void
    {
        $name = post('name', DB_STRING_MAX_LENGTH);
        if ($name === '') err('版块名不能为空');
        $description = post('description', DB_TEXT_MAX_LENGTH);
        $sort = (int)$_POST['sort'];
        $values = ['name' => $name, 'description' => $description, 'sort' => $sort];
        foreach (['allow_view_groups', 'allow_post_groups', 'allow_reply_groups'] as $field) $values[$field] = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$field] ?? []))))));
        if (id()) Forum::whereKey(id())->update($values);
        else Forum::create($values);
        forums_cache(true);
    }

    public static function save_group(): void
    {
        $name = post('name', DB_STRING_MAX_LENGTH);
        if ($name === '') err('组名不能为空');
        $values = ['name' => $name, 'allow_manage' => isset($_POST['allow_manage']) ? 1 : 0, 'allow_admin' => isset($_POST['allow_admin']) ? 1 : 0];
        if (id()) Group::whereKey(id())->update($values);
        else Group::create($values);
        groups_cache(true);
    }

    public static function deletable_post_row(string $type, int $id): ?array
    {
        if ($type === 'topics') return Topic::find($id)?->toArray();
        if ($type === 'replies') return Reply::find($id)?->toArray();
        return null;
    }

    public static function can_delete(string $type, int $id): bool
    {
        if ($type === 'users') return can_manage() && $id !== uid() && ($id !== 1 || is_super_user());
        if (in_array($type, ['groups', 'forums'], true)) return can_manage() && is_super_user();
        $row = self::deletable_post_row($type, $id);
        if ($type === 'topics') return $row && can_manage_topic($row);
        if ($type === 'replies') return $row && can_manage_reply($row);
        return false;
    }

    public static function settings_handle_post(): never
    {
        if ((string)($_POST['debug_log_action'] ?? '') === 'clear') {
            if (!is_dir(dirname(DEBUG_LOG_FILE))) mkdir(dirname(DEBUG_LOG_FILE), 0755, true);
            file_put_contents(DEBUG_LOG_FILE, '', LOCK_EX);
            set_flash('Debug日志已清空');
            go(admin_url(['tab' => 'settings']));
        }
        if (isset($_POST['clear_opcache'])) {
            self::clear_opcache_cache();
            set_flash('OPcache已清理');
            go(admin_url(['tab' => 'settings']));
        }
        self::save_settings();
        go(admin_url(['tab' => 'settings']));
    }

    public static function settings_html(): array
    {
        $settings = settings_cache();
        $fields = [
            'site_name' => ['label' => '网站名', 'required' => true],
            'site_name_title' => ['label' => '网站名title', 'help' => '为空时使用网站名。'],
            'site_base_url' => ['label' => '网站固定地址', 'type' => 'url', 'help' => '填写以 https:// 开头的网站域名。'],
            'site_keywords' => ['label' => '关键字'], 'site_description' => ['label' => '网站介绍', 'type' => 'textarea'],
            'pinned_topic_ids' => ['label' => '置顶主题ID'], 'pc_nav_forum_count' => ['label' => 'PC顶部版块数量', 'type' => 'number', 'min' => 0, 'max' => 20, 'help' => 'PC端顶部默认展示的版块数量，默认6个；设为0仅显示“全部版块”。'],
            'topics_per_page' => ['label' => '列表单页数量', 'type' => 'number', 'min' => 1, 'max' => 200], 'replies_per_page' => ['label' => '回帖单页数量', 'type' => 'number', 'min' => 1, 'max' => 200],
            'max_pagination_pages' => ['label' => '最大分页数', 'type' => 'number', 'min' => 1, 'max' => 1000, 'help' => '限制除主题回帖外的所有分页，默认50。'],
            'username_min_length' => ['label' => '用户名最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH], 'username_max_length' => ['label' => '用户名最大长度', 'type' => 'number', 'min' => 1, 'max' => DB_STRING_MAX_LENGTH],
            'email_min_length' => ['label' => '邮箱最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH], 'email_max_length' => ['label' => '邮箱最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'bio_min_length' => ['label' => '个人简介最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH], 'bio_max_length' => ['label' => '个人简介最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'search_min_length' => ['label' => '搜索关键词最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH], 'search_max_length' => ['label' => '搜索关键词最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'title_min_length' => ['label' => '标题最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH], 'title_max_length' => ['label' => '标题最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_STRING_MAX_LENGTH],
            'topic_body_min_length' => ['label' => '主题内容最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH], 'topic_body_max_length' => ['label' => '主题内容最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'reply_body_min_length' => ['label' => '回帖内容最小长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH], 'reply_body_max_length' => ['label' => '回帖内容最大长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'excerpt_length' => ['label' => '摘要长度', 'type' => 'number', 'min' => 0, 'max' => DB_TEXT_MAX_LENGTH],
            'site_closed' => ['label' => '是否关闭站点进行维护', 'type' => 'checkbox'], 'debug_mode' => ['label' => 'Debug模式', 'type' => 'checkbox'],
            'allow_register' => ['label' => '是否允许注册', 'type' => 'checkbox'], 'default_group_id' => ['label' => '新用户默认用户组', 'type' => 'select', 'options' => array_column(groups_cache(), 'name', 'id')], 'post_interval_seconds' => ['label' => '发帖/回复间隔（秒）', 'type' => 'number', 'min' => 0, 'max' => 3600, 'help' => '发帖/回复间隔设置为 0 可关闭限制，默认 5 秒一次。'],
        ];
        return [
            'fields' => $fields,
            'settings' => $settings,
            'debug_mode' => (string)($settings['debug_mode'] ?? '0') === '1',
            'debug_log_file' => DEBUG_LOG_FILE,
        ];
    }

    public static function groups_view(): array
    {
        return ['groups' => groups_cache()];
    }

    public static function forums_view(): array
    {
        $forums = [];
        foreach (forums_cache() as $forum) {
            $permissions = [];
            foreach (['allow_view_groups' => '浏览', 'allow_post_groups' => '发帖', 'allow_reply_groups' => '回帖'] as $field => $label) {
                $count = count(forum_group_ids($forum, $field));
                $permissions[] = $label . ':' . ($count ? $count . '组' : '不限');
            }
            $forum['permissions'] = implode(' / ', $permissions);
            $forums[] = $forum;
        }
        return ['forums' => $forums];
    }

    public static function page(): void
    {
        need_admin();
        $tab = (string)($_GET['tab'] ?? 'settings');
        if ($tab === 'settings' && (string)($_GET['debug_log'] ?? '') === 'view') { header('Content-Type: text/plain; charset=utf-8'); echo is_file(DEBUG_LOG_FILE) ? (string)file_get_contents(DEBUG_LOG_FILE) : ''; exit; }
        if ($tab === 'settings' && is_post_request()) self::settings_handle_post();
        if ($tab === 'topics' && is_post_request()) self::topics_handle_post();
        if ($tab === 'users' && is_post_request()) self::users_handle_post();
        $template = match ($tab) {
            'settings' => 'admin/settings.html.twig', 'groups' => 'admin/groups.html.twig', 'forums' => 'admin/forums.html.twig',
            'topics' => 'admin/topics.html.twig', 'users' => 'admin/users.html.twig', 'report' => 'admin/report.html.twig',
            default => '',
        };
        if ($template === '') err('你访问的页面不存在', 404);
        $view = match ($tab) {
            'settings' => self::settings_html(), 'groups' => self::groups_view(), 'forums' => self::forums_view(),
            'topics' => self::topics_view(), 'users' => self::users_view(), 'report' => self::report_view(),
            default => [],
        };
        render_page($template, $view + ['tab' => $tab], '后台');
    }

    public static function edit_page(): void
    {
        need_admin();
        $type = $_GET['type'] ?? $_POST['type'] ?? '';
        if (is_post_request()) {
            if ($type === 'group') self::save_group();
            elseif ($type === 'forum') self::save_forum();
            elseif ($type === 'user') self::save_user_admin();
            else err('参数错误');
            go(admin_url(['tab' => $type . 's']));
        }
        $view = ['type' => $type, 'edit_id' => id()];
        if ($type === 'group') {
            $view += ['tab' => 'groups', 'group' => id() ? (group_by_id(id()) ?: err('用户组不存在')) : ['id' => 0, 'name' => '', 'allow_manage' => 0, 'allow_admin' => 0]];
        } elseif ($type === 'forum') {
            $f = id() ? forum_by_id(id()) : ['id' => 0, 'name' => '', 'description' => '', 'sort' => 0, 'allow_view_groups' => '', 'allow_post_groups' => '', 'allow_reply_groups' => ''];
            if (!$f) err('版块不存在');
            $selected = [];
            foreach (['allow_view_groups', 'allow_post_groups', 'allow_reply_groups'] as $field) $selected[$field] = forum_group_ids($f, $field);
            $view += ['tab' => 'forums', 'forum' => $f, 'groups' => groups_cache(), 'forum_selected' => $selected];
        } elseif ($type === 'user') {
            $u = User::find(id()) ?: err('用户不存在');
            $view += ['tab' => 'users', 'user' => $u->toArray() + ['topics_count' => $u->topics()->count(), 'replies_count' => $u->replies()->count()], 'group_options' => array_column(groups_cache(), 'name', 'id'), 'registered_at' => date('Y-m-d H:i', (int)$u->created_at)];
        } else {
            err('参数错误');
        }
        render_page($type === 'user' ? 'admin/user_edit.html.twig' : 'admin/edit.html.twig', $view, '编辑');
    }

    public static function route(): void
    {
        $do = (string)($_GET['do'] ?? '');
        if ($do === 'edit') { self::edit_page(); return; }
        if ($do === '') { self::page(); return; }
        if ($do !== 'delete') err('你访问的页面不存在', 404);
        require_post(); need_admin();
        $type = ['group' => 'groups', 'groups' => 'groups', 'forum' => 'forums', 'forums' => 'forums', 'user' => 'users', 'users' => 'users', 'topic' => 'topics', 'topics' => 'topics'][$_POST['type'] ?? ''] ?? '';
        if (!in_array($type, ['groups', 'forums', 'users', 'topics'], true)) err('参数错误');
        if (!self::can_delete($type, id())) err('无权限');
        if ($type === 'topics') del('topics', id(), true);
        else del($type, id());
        go(admin_url(['tab' => $type, 'q' => trim((string)($_POST['q'] ?? '')) ?: null, 'p' => max(1, (int)($_POST['p'] ?? 1))]));
    }

    /** 后台帖子管理列表：标题搜索 + 版块筛选 + 分页，带浏览量/回复数与高亮样式 */
    public static function topics_view(): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $fid = max(0, (int)($_GET['fid'] ?? 0));
        $p = max(1, (int)($_GET['p'] ?? 1));
        $size = max(1, (int)setting('topics_per_page', '30'));
        $base = static function () use ($q, $fid) {
            $builder = Topic::query();
            if ($fid > 0) $builder->where('forum_id', $fid);
            if ($q !== '') {
                [$condition, $params] = content_search_condition($q, 'title');
                $builder->whereRaw('(' . $condition . ')', $params);
            }
            return $builder;
        };
        $total = $base()->count();
        $rows = $base()->orderByDesc('created_at')->orderByDesc('id')
            ->limit($size)->offset(($p - 1) * $size)
            ->get(['id', 'title', 'highlight_style', 'view_count', 'reply_count', 'forum_id', 'user_id', 'created_at'])
            ->map->toArray()->all();
        $rows = attach_topic_list_users($rows);
        $forums = [];
        foreach (forums_cache() as $forum) $forums[(int)$forum['id']] = (string)$forum['name'];
        $pinned_ids = pinned_topic_ids();
        foreach ($rows as &$row) {
            $row['forum_name'] = (string)($forums[(int)$row['forum_id']] ?? '—');
            $row['is_pinned'] = in_array((int)$row['id'], $pinned_ids, true) ? 1 : 0;
            $row['created_time'] = date('Y-m-d H:i', (int)$row['created_at']);
            $row['current_color'] = topic_title_color((string)$row['highlight_style']);
            $row['is_bold'] = topic_title_is_bold((string)$row['highlight_style']);
        }
        unset($row);
        return [
            'rows' => $rows,
            'total' => $total,
            'q' => $q,
            'fid' => $fid,
            'forums' => $forums,
            'page' => $p,
            'pagination' => pagination_data(false, $total, $p, $size, admin_url(['tab' => 'topics', 'q' => $q !== '' ? $q : null, 'fid' => $fid > 0 ? $fid : null])),
        ];
    }

    /** 后台帖子管理的行内操作：置顶/取消置顶、高亮样式（颜色 + 加粗 + 清除） */
    public static function topics_handle_post(): void
    {
        require_post();
        if (!can_manage()) err('无权限');
        $t = Topic::find(id())?->toArray() ?: err('主题不存在');
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'pin' || $do === 'unpin') {
            set_pinned_topic((int)$t['id'], $do === 'pin');
            set_flash($do === 'pin' ? '主题已置顶' : '主题已取消置顶');
        } elseif ($do === 'highlight') {
            $style = '';
            if ((string)($_POST['clear'] ?? '') !== '1') {
                $color = topic_title_color((string)$t['highlight_style']);
                $raw_color = trim((string)($_POST['color'] ?? ''));
                if ($raw_color !== '') $color = preg_match('/^#[0-9a-fA-F]{6}$/', $raw_color, $m) ? $m[0] : '#d94b4b';
                $style = topic_title_style($color, isset($_POST['bold']));
            }
            Topic::whereKey((int)$t['id'])->update(['highlight_style' => $style]);
            set_flash('标题样式已更新');
        } else {
            err('参数错误');
        }
        go(self::list_back_url('topics'));
    }

    /** 后台用户管理列表：用户名/邮箱搜索 + 分页，带主题/回帖计数 */
    public static function users_view(): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $p = max(1, (int)($_GET['p'] ?? 1));
        $size = 30;
        $base = static function () use ($q) {
            $builder = User::query()->select(['id', 'username', 'email', 'group_id', 'is_muted', 'created_at', 'last_post_at'])->withCount(['topics', 'replies']);
            if ($q !== '') {
                $pattern = search_like_pattern($q);
                $builder->where(static fn($sub) => $sub->where('username', 'like', $pattern)->orWhere('email', 'like', $pattern));
            }
            return $builder;
        };
        $total = $base()->count();
        $rows = $base()->orderByDesc('created_at')->orderByDesc('id')
            ->limit($size)->offset(($p - 1) * $size)->get()->map->toArray()->all();
        foreach ($rows as &$row) {
            $row['group_name'] = (string)(group_by_id((int)$row['group_id'])['name'] ?? '—');
            $row['created_time'] = date('Y-m-d H:i', (int)$row['created_at']);
            $row['last_post_time'] = (int)$row['last_post_at'] > 0 ? date('Y-m-d H:i', (int)$row['last_post_at']) : '—';
        }
        unset($row);
        return [
            'rows' => $rows,
            'total' => $total,
            'q' => $q,
            'page' => $p,
            'pagination' => pagination_data(false, $total, $p, $size, admin_url(['tab' => 'users', 'q' => $q !== '' ? $q : null])),
        ];
    }

    /** 后台用户管理的行内操作：禁言/解禁（不能操作自己与超级管理员） */
    public static function users_handle_post(): void
    {
        require_post();
        if (!can_manage()) err('无权限');
        $target = User::find(id()) ?: err('用户不存在');
        $target_id = (int)$target->id;
        if ($target_id === uid()) err('不能操作自己的账号');
        if ($target_id === 1) err('不能操作超级管理员');
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'mute' || $do === 'unmute') {
            User::whereKey($target_id)->update(['is_muted' => $do === 'mute' ? 1 : 0]);
            set_flash($do === 'mute' ? '用户已禁言' : '用户已解除禁言');
        } else {
            err('参数错误');
        }
        go(self::list_back_url('users'));
    }

    /** 管理员编辑用户：调整用户组、重置密码（改密后该用户的登录态自动失效）、禁言 */
    public static function save_user_admin(): void
    {
        if (!can_manage()) err('无权限');
        $target = User::find(id()) ?: err('用户不存在');
        $target_id = (int)$target->id;
        if ($target_id === 1) err('不能修改超级管理员');
        if ($target_id === uid()) err('请在个人设置中修改自己的资料');
        $gid = (int)($_POST['group_id'] ?? 0);
        if (!group_by_id($gid)) err('用户组不存在');
        $values = ['group_id' => $gid, 'is_muted' => isset($_POST['is_muted']) ? 1 : 0];
        $pwd = (string)($_POST['password'] ?? '');
        if ($pwd !== '') {
            require_password_length($pwd);
            $values['password'] = password_hash($pwd, PASSWORD_DEFAULT);
        }
        User::whereKey($target_id)->update($values);
        set_flash('用户资料已更新');
    }

    /** 数据报表：日期范围筛选，汇总卡片 + 每日新增帖子/新增用户/浏览量 */
    public static function report_view(): array
    {
        $today = date('Y-m-d');
        $to = self::report_date_param((string)($_GET['to'] ?? '')) ?: $today;
        $from = self::report_date_param((string)($_GET['from'] ?? '')) ?: date('Y-m-d', strtotime($to . ' -6 days'));
        if ($from > $to) [$from, $to] = [$to, $from];
        if ($from < date('Y-m-d', strtotime($to . ' -365 days'))) $from = date('Y-m-d', strtotime($to . ' -365 days'));
        $from_ts = (int)strtotime($from . ' 00:00:00');
        $to_ts = (int)strtotime($to . ' 23:59:59');
        $views = ViewStat::whereBetween('view_date', [$from, $to])->pluck('views', 'view_date');
        $posts_by_day = self::count_by_day(Topic::whereBetween('created_at', [$from_ts, $to_ts])->pluck('created_at')->all());
        $users_by_day = self::count_by_day(User::whereBetween('created_at', [$from_ts, $to_ts])->pluck('created_at')->all());
        $rows = [];
        $totals = ['posts' => 0, 'users' => 0, 'views' => 0];
        for ($date = $from; $date <= $to; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
            $row = ['date' => $date, 'posts' => $posts_by_day[$date] ?? 0, 'users' => $users_by_day[$date] ?? 0, 'views' => (int)($views[$date] ?? 0)];
            $totals['posts'] += $row['posts'];
            $totals['users'] += $row['users'];
            $totals['views'] += $row['views'];
            $rows[] = $row;
        }
        $max = 1;
        foreach ($rows as $row) $max = max($max, $row['posts'], $row['users'], $row['views']);
        foreach ($rows as &$row) {
            $row['posts_pct'] = (int)round($row['posts'] / $max * 100);
            $row['users_pct'] = (int)round($row['users'] / $max * 100);
            $row['views_pct'] = (int)round($row['views'] / $max * 100);
        }
        unset($row);
        $quick = [];
        foreach (['今天' => 0, '最近7天' => 6, '最近30天' => 29] as $label => $days) {
            $quick_from = date('Y-m-d', strtotime($today . ' -' . $days . ' days'));
            $quick[] = ['label' => $label, 'href' => admin_url(['tab' => 'report', 'from' => $quick_from, 'to' => $today]), 'active' => $from === $quick_from && $to === $today];
        }
        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => $totals,
            'posts_total' => Topic::count(),
            'users_total' => User::count(),
            'quick' => $quick,
        ];
    }

    /** 校验 Y-m-d 日期参数，非法返回空串 */
    private static function report_date_param(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        $ts = strtotime($value);
        return $ts > 0 ? date('Y-m-d', $ts) : '';
    }

    /** 把一组 unix 秒按站点时区归到 Y-m-d 计数 */
    private static function count_by_day(array $timestamps): array
    {
        $counts = [];
        foreach ($timestamps as $ts) {
            $date = date('Y-m-d', (int)$ts);
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }
        return $counts;
    }

    /** 行内操作后回到原列表位置（保留搜索词与页码，隐藏字段随表单提交） */
    private static function list_back_url(string $tab): string
    {
        return admin_url(['tab' => $tab, 'q' => trim((string)($_POST['q'] ?? '')) ?: null, 'p' => max(1, (int)($_POST['p'] ?? 1))]);
    }
}
