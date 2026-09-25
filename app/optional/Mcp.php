<?php

declare(strict_types=1);

namespace app\optional;

use app\optional\Db\Database;
use app\optional\Model\ApiLog;
use app\optional\Model\ApiToken;
use app\optional\Model\Reply;
use app\optional\Model\Topic;
use app\optional\Model\User;
use RuntimeException;
use Throwable;

if (!defined('APP_ROOT')) exit;

/**
 * 论坛 MCP 服务：/mcp 单端点提供 JSON-RPC（Streamable HTTP 的无会话实现）。
 *
 * - 登录与鉴权分离：登录仍是网页端的 Cookie 会话；MCP 调用只认 Authorization: Bearer 令牌。
 *   令牌在「个人设置」里创建（必须先登录网页端），库里只存 SHA-256 摘要，明文只在创建时展示一次。
 * - 令牌鉴权通过后把用户注入全局会话（$GLOBALS['__me_cache']），禁言、版块用户组限制、
 *   发帖间隔等与网页端完全共用一套权限模型。
 * - 每一次调用（含鉴权失败与被拒绝）都写一条 app_api_logs 审计记录：
 *   谁、用什么令牌、调了什么工具、参数与结果摘要、来源 IP、耗时；后台「MCP日志」可查。
 */
final class Mcp
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18'];
    private const TOKEN_PREFIX = 'bbs1_';
    private const TOKEN_HEX_LENGTH = 48;
    private const LOG_STATUSES = ['ok', 'denied', 'unauthorized', 'error'];
    private const LOG_ARGS_MAX = 8000;
    private const LOG_RESULT_MAX = 4000;
    // last_used_at 按分钟节流写：高频调用不产生逐次磁盘写
    private const LAST_USED_WRITE_INTERVAL = 60;

    public static function route(): void
    {
        $started = microtime(true);
        if (!is_post_request()) self::rpc_fail(null, -32600, 'MCP 端点只接受 POST（Streamable HTTP）', 405);
        $message = json_decode((string)file_get_contents('php://input'), true);
        // 批量请求在 MCP 2025-06-18 已移除，统一按不支持的请求拒绝
        if (!is_array($message) || array_is_list($message)) {
            self::rpc_fail(null, is_array($message) ? -32600 : -32700, is_array($message) ? '不支持批量请求' : '请求不是合法的 JSON', 400);
        }
        $method = (string)($message['method'] ?? '');
        $msg_id = array_key_exists('id', $message) ? $message['id'] : null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $auth = self::authenticate();
        } catch (McpAuthException $e) {
            self::log_call([
                'tool' => self::audit_tool_name($method, $params),
                'status' => 'unauthorized',
                'args_json' => self::encode_for_log($params),
                'message' => $e->getMessage(),
            ]);
            self::rpc_fail($msg_id, -32001, '鉴权失败：' . $e->getMessage(), 401);
        }
        [$token_row, $user] = $auth;

        try {
            if (setting('site_closed') === '1' && !can_access_admin()) {
                self::log_call([
                    'user_id' => (int)$user->id, 'token_id' => (int)$token_row->id,
                    'tool' => self::audit_tool_name($method, $params),
                    'status' => 'denied', 'args_json' => self::encode_for_log($params),
                    'message' => '网站已关闭', 'duration_ms' => self::duration_ms($started),
                ]);
                self::rpc_fail($msg_id, -32000, '网站已关闭', 403);
            }
            self::dispatch($method, $msg_id, $params, $token_row, $user, $started);
        } catch (Throwable $e) {
            debug_log_write('MCP 未捕获异常', $e);
            self::log_call([
                'user_id' => (int)$user->id, 'token_id' => (int)$token_row->id,
                'tool' => self::audit_tool_name($method, $params),
                'status' => 'error', 'args_json' => self::encode_for_log($params),
                'message' => '未预期异常：' . $e->getMessage(), 'duration_ms' => self::duration_ms($started),
            ]);
            self::rpc_fail($msg_id, -32603, '服务器内部错误');
        }
    }

    private static function dispatch(string $method, mixed $msg_id, array $params, ApiToken $token_row, User $user, float $started): void
    {
        $base_log = static fn(): array => [
            'user_id' => (int)$user->id, 'token_id' => (int)$token_row->id,
            'tool' => self::audit_tool_name($method, $params),
            'args_json' => self::encode_for_log($params),
            'duration_ms' => self::duration_ms($started),
        ];
        if ($method === 'initialize') {
            $requested = (string)($params['protocolVersion'] ?? '');
            $version = in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSION;
            self::log_call($base_log() + ['status' => 'ok', 'result_json' => '{"protocolVersion":"' . $version . '"}']);
            self::respond(['jsonrpc' => '2.0', 'id' => $msg_id, 'result' => [
                'protocolVersion' => $version,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'bbs1org', 'version' => APP_VERSION],
                'instructions' => '论坛 MCP：可浏览主题、按关键词检索、按网页端同款权限发表主题与回帖。所有调用都会记录审计日志。',
            ]]);
        }
        if ($method === 'ping') {
            self::log_call($base_log() + ['status' => 'ok']);
            self::respond(['jsonrpc' => '2.0', 'id' => $msg_id, 'result' => []]);
        }
        if ($method === 'tools/list') {
            $tools_json = self::json_text(['tools' => array_map(static fn(array $tool): array => $tool + ['annotations' => ['readOnlyHint' => !in_array($tool['name'], ['create_topic', 'create_reply'], true)]], self::tools())]);
            self::log_call($base_log() + ['status' => 'ok', 'result_json' => cut($tools_json, self::LOG_RESULT_MAX)]);
            self::respond(['jsonrpc' => '2.0', 'id' => $msg_id, 'result' => ['tools' => self::tools()]]);
        }
        if ($method === 'tools/call') {
            self::handle_tools_call($params, $token_row, $user, $started, $msg_id);
        }
        if (str_starts_with($method, 'notifications/')) {
            self::log_call($base_log() + ['status' => 'ok']);
            self::respond_status(202);
        }
        self::log_call($base_log() + ['status' => 'error', 'message' => '方法不存在']);
        self::rpc_fail($msg_id, -32601, '方法不存在：' . $method);
    }

    private static function handle_tools_call(array $params, ApiToken $token_row, User $user, float $started, mixed $msg_id): void
    {
        $name = trim((string)($params['name'] ?? ''));
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $common = [
            'user_id' => (int)$user->id, 'token_id' => (int)$token_row->id,
            'tool' => $name !== '' ? $name : 'tools/call',
            'args_json' => self::encode_for_log($args),
        ];
        $known = in_array($name, array_column(self::tools(), 'name'), true);
        if (!$known) {
            self::log_call($common + ['status' => 'error', 'message' => '工具不存在', 'duration_ms' => self::duration_ms($started)]);
            self::rpc_fail($msg_id, -32602, '工具不存在：' . $name);
        }
        try {
            $text = self::call_tool($name, $args, $token_row);
            self::log_call($common + ['status' => 'ok', 'result_json' => cut($text, self::LOG_RESULT_MAX), 'duration_ms' => self::duration_ms($started)]);
            self::respond(['jsonrpc' => '2.0', 'id' => $msg_id, 'result' => ['content' => [['type' => 'text', 'text' => $text]]]]);
        } catch (McpToolException $e) {
            self::log_call($common + ['status' => $e->audit_status, 'message' => $e->getMessage(), 'duration_ms' => self::duration_ms($started)]);
            self::respond(['jsonrpc' => '2.0', 'id' => $msg_id, 'result' => ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]]);
        } catch (Throwable $e) {
            debug_log_write('MCP 工具执行异常 ' . $name, $e);
            self::log_call($common + ['status' => 'error', 'message' => '执行异常：' . $e->getMessage(), 'duration_ms' => self::duration_ms($started)]);
            self::respond(['jsonrpc' => '2.0', 'id' => $msg_id, 'result' => ['content' => [['type' => 'text', 'text' => '工具执行失败，请稍后重试']], 'isError' => true]]);
        }
    }

    /** 校验 Bearer 令牌并注入会话；任何失败抛 McpAuthException */
    private static function authenticate(): array
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^\s*bearer\s+(\S+)\s*$/i', $header, $m)) throw new McpAuthException('缺少 Authorization: Bearer 令牌');
        $plain = trim($m[1]);
        $hex = str_starts_with($plain, self::TOKEN_PREFIX) ? substr($plain, strlen(self::TOKEN_PREFIX)) : '';
        if (!preg_match('/^[a-f0-9]{' . self::TOKEN_HEX_LENGTH . '}$/', $hex)) throw new McpAuthException('令牌格式无效');
        $row = ApiToken::where('token_hash', hash('sha256', $plain))->where('revoked_at', 0)->first();
        if (!$row) throw new McpAuthException('令牌无效或已吊销');
        $user = User::find((int)$row->user_id);
        if (!$user) throw new McpAuthException('令牌对应的用户不存在');
        if (now() - (int)$row->last_used_at >= self::LAST_USED_WRITE_INTERVAL) ApiToken::whereKey((int)$row->id)->update(['last_used_at' => now()]);
        // 与 me() 同款的用户组兜底：组被删掉时落到默认组，避免令牌用户彻底不可用
        $group = group_by_id((int)$user->group_id);
        if (!$group) {
            $fallback_id = (int)setting('default_group_id', '2');
            $group = group_by_id($fallback_id);
            if (!$group) throw new McpAuthException('用户组配置缺失');
            User::whereKey((int)$user->id)->where('group_id', $user->group_id)->update(['group_id' => $fallback_id]);
            $user->group_id = $fallback_id;
        }
        $GLOBALS['__request_uid'] = (int)$user->id;
        $GLOBALS['__me_cache'] = $user->toArray() + ['group_name' => (string)$group['name'], 'group_id' => (int)$user->group_id, 'is_muted' => (int)($user->is_muted ?? 0), 'allow_manage' => (int)($group['allow_manage'] ?? 0), 'allow_admin' => (int)($group['allow_admin'] ?? 0)];
        return [$row, $user];
    }

    // ---------- 工具定义 ----------

    private static function tools(): array
    {
        $schema = static fn(array $properties, array $required = []): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        return [
            ['name' => 'site_info', 'description' => '获取论坛概况：站点名称、当前账号、版块列表（含当前账号在各版块的浏览/发帖/回帖权限）。', 'inputSchema' => $schema([])],
            ['name' => 'list_topics', 'description' => '主题列表：可按版块、用户、标题关键词筛选，返回标题/作者/浏览量/回复数/时间，支持分页。', 'inputSchema' => $schema([
                'forum_id' => ['type' => 'integer', 'description' => '版块 ID，不传查全部版块'],
                'user_id' => ['type' => 'integer', 'description' => '只看某用户的主题'],
                'q' => ['type' => 'string', 'description' => '标题关键词'],
                'sort' => ['type' => 'string', 'enum' => ['newest', 'last_reply'], 'description' => 'newest 按发帖时间倒序（默认），last_reply 按最后回帖时间倒序'],
                'page' => ['type' => 'integer', 'description' => '页码，默认 1'],
                'per_page' => ['type' => 'integer', 'description' => '每页条数，默认 20，最大 50'],
            ])],
            ['name' => 'get_topic', 'description' => '读取单个主题：Markdown 原文 + 分页回帖（带楼层号）。不累计浏览量。', 'inputSchema' => $schema([
                'id' => ['type' => 'integer', 'description' => '主题 ID'],
                'page' => ['type' => 'integer', 'description' => '回帖页码，默认 1'],
                'per_page' => ['type' => 'integer', 'description' => '回帖每页条数，默认 20，最大 100'],
            ], ['id'])],
            ['name' => 'create_topic', 'description' => '发表主题：与网页端同权限（版块发帖用户组、禁言、发帖间隔）。返回新主题 ID 与链接。', 'inputSchema' => $schema([
                'forum_id' => ['type' => 'integer', 'description' => '版块 ID，不传用第一个可发帖版块'],
                'title' => ['type' => 'string', 'description' => '标题'],
                'body' => ['type' => 'string', 'description' => '正文，支持 Markdown'],
            ], ['title', 'body'])],
            ['name' => 'create_reply', 'description' => '回帖：与网页端同权限（版块回帖用户组、禁言、发帖间隔）。返回回帖 ID 与定位链接。', 'inputSchema' => $schema([
                'topic_id' => ['type' => 'integer', 'description' => '主题 ID'],
                'body' => ['type' => 'string', 'description' => '回帖内容，支持 Markdown'],
            ], ['topic_id', 'body'])],
            ['name' => 'my_info', 'description' => '查看当前令牌对应的账号：用户名、用户组、禁言状态、令牌名称与最后使用时间。', 'inputSchema' => $schema([])],
        ];
    }

    private static function call_tool(string $name, array $args, ApiToken $token): string
    {
        return match ($name) {
            'site_info' => self::tool_site_info(),
            'list_topics' => self::tool_list_topics($args),
            'get_topic' => self::tool_get_topic($args),
            'create_topic' => self::tool_create_topic($args),
            'create_reply' => self::tool_create_reply($args),
            'my_info' => self::tool_my_info($token),
            default => throw new McpToolException('工具不存在：' . $name),
        };
    }

    private static function tool_site_info(): string
    {
        $forums = [];
        foreach (forums_cache() as $forum) {
            $forums[] = [
                'id' => (int)$forum['id'],
                'name' => (string)$forum['name'],
                'description' => (string)$forum['description'],
                'can_view' => forum_group_allowed($forum, 'allow_view_groups'),
                'can_post' => forum_group_allowed($forum, 'allow_post_groups'),
                'can_reply' => forum_group_allowed($forum, 'allow_reply_groups'),
            ];
        }
        $me = me();
        return self::json_text([
            'site_name' => setting('site_name', 'FORUM'),
            'site_url' => rtrim(base_url(), '/'),
            'account' => ['id' => (int)$me['id'], 'username' => (string)$me['username'], 'group' => (string)$me['group_name'], 'is_muted' => (int)$me['is_muted'] === 1],
            'forums' => $forums,
        ]);
    }

    private static function tool_list_topics(array $args): string
    {
        $fid = self::arg_int($args, 'forum_id', 0, 0, PHP_INT_MAX);
        $target_uid = self::arg_int($args, 'user_id', 0, 0, PHP_INT_MAX);
        $q = self::arg_str($args, 'q');
        $sort = self::arg_str($args, 'sort') ?: 'newest';
        if (!in_array($sort, ['newest', 'last_reply'], true)) throw new McpToolException('参数 sort 只支持 newest / last_reply');
        $page = self::arg_int($args, 'page', 1, 1, max_pagination_pages());
        $size = self::arg_int($args, 'per_page', 20, 1, 50);
        if ($fid > 0) {
            $forum = forum_by_id($fid) ?: throw new McpToolException('版块不存在');
            if (!forum_group_allowed($forum, 'allow_view_groups')) throw new McpToolException('没有浏览权限', 'denied');
        }
        $base = static function () use ($fid, $target_uid, $q) {
            $builder = Topic::query();
            if ($fid > 0) $builder->where('forum_id', $fid);
            if ($target_uid > 0) $builder->where('user_id', $target_uid);
            if ($q !== '') {
                [$condition, $params] = content_search_condition($q, 'title');
                $builder->whereRaw('(' . $condition . ')', $params);
            }
            return $builder;
        };
        $total = $base()->count();
        $list = $base();
        if ($sort === 'last_reply') $list->orderByDesc('last_reply_at')->orderByDesc('id');
        else $list->orderByDesc('created_at')->orderByDesc('id');
        $rows = $list->limit($size)->offset(($page - 1) * $size)->get(array_merge(topic_list_columns(), ['view_count']))->map->toArray()->all();
        $rows = attach_topic_list_users($rows);
        $pinned = pinned_topic_ids();
        foreach ($rows as &$row) {
            $row['forum_name'] = (string)(forum_by_id((int)$row['forum_id'])['name'] ?? '');
            $row['is_pinned'] = in_array((int)$row['id'], $pinned, true);
            $row['url'] = absolute_url(route_url('topic', ['id' => (int)$row['id']]));
            $row['created_time'] = date('Y-m-d H:i', (int)$row['created_at']);
            unset($row['highlight_style'], $row['group_id'], $row['is_muted'], $row['last_reply_user_id']);
        }
        unset($row);
        return self::json_text(['total' => $total, 'page' => $page, 'per_page' => $size, 'topics' => $rows]);
    }

    private static function tool_get_topic(array $args): string
    {
        $tid = self::arg_int($args, 'id', 0, 1, PHP_INT_MAX);
        $t = Topic::find($tid)?->toArray() ?: throw new McpToolException('主题不存在');
        $forum = forum_by_id((int)$t['forum_id']);
        if ($forum && !forum_group_allowed($forum, 'allow_view_groups')) throw new McpToolException('没有浏览权限', 'denied');
        $page = self::arg_int($args, 'page', 1, 1, max_pagination_pages());
        $size = self::arg_int($args, 'per_page', 20, 1, 100);
        $reply_desc = (int)($t['reply_order'] ?? 0) === 1;
        $reply_query = Reply::where('topic_id', $tid);
        $reply_query = $reply_desc ? $reply_query->orderByDesc('created_at')->orderByDesc('id') : $reply_query->orderBy('created_at')->orderBy('id');
        $replies = $reply_query->limit($size)->offset(($page - 1) * $size)->get(['id', 'user_id', 'body', 'created_at'])->map->toArray()->all();
        $replies = attach_users(apply_reply_floors($replies, $t, $page, $size, $reply_desc));
        foreach ($replies as &$r) {
            $r['created_time'] = date('Y-m-d H:i', (int)$r['created_at']);
            unset($r['group_id'], $r['is_muted']);
        }
        unset($r);
        $topic = [
            'id' => (int)$t['id'],
            'title' => (string)$t['title'],
            'body' => (string)$t['body'],
            'forum' => ['id' => (int)($forum['id'] ?? 0), 'name' => (string)($forum['name'] ?? '')],
            'author' => (string)(User::whereKey((int)$t['user_id'])->value('username') ?? ''),
            'view_count' => (int)$t['view_count'],
            'reply_count' => (int)$t['reply_count'],
            'is_pinned' => in_array($tid, pinned_topic_ids(), true),
            'created_time' => date('Y-m-d H:i', (int)$t['created_at']),
            'url' => absolute_url(route_url('topic', ['id' => $tid])),
        ];
        return self::json_text(['topic' => $topic, 'page' => $page, 'per_page' => $size, 'replies' => $replies]);
    }

    private static function tool_create_topic(array $args): string
    {
        if (!can_speak()) throw new McpToolException('当前账号被禁言，无法发帖', 'denied');
        $fid = self::arg_int($args, 'forum_id', 0, 0, PHP_INT_MAX);
        if ($fid <= 0) {
            $fid = default_post_forum_id();
            if (!$fid) throw new McpToolException('没有可发帖的版块', 'denied');
        }
        $forum = forum_by_id($fid) ?: throw new McpToolException('版块不存在');
        if (!forum_group_allowed($forum, 'allow_post_groups')) throw new McpToolException('没有在该版块发帖的权限', 'denied');
        $title = cut(self::arg_str($args, 'title'), length_limit('title', 'max'));
        $body = cut(self::arg_str($args, 'body'), length_limit('topic_body', 'max'));
        if ($title === '' || $body === '') throw new McpToolException('标题和内容不能为空');
        self::check_min_length($title, length_limit('title', 'min'), '标题');
        self::check_min_length($body, length_limit('topic_body', 'min'), '主题内容');
        self::check_post_interval();
        $author_id = uid();
        $ts = now();
        $tid = Database::connection()->transaction(static function () use ($fid, $author_id, $title, $body, $ts): int {
            $saved = Topic::create(['forum_id' => $fid, 'user_id' => $author_id, 'title' => $title, 'body' => $body, 'created_at' => $ts, 'last_reply_at' => $ts]);
            $tid = (int)$saved->id;
            User::whereKey($author_id)->update(['last_post_at' => $ts]);
            create_topic_notifications($tid, $body, $author_id);
            return $tid;
        });
        return self::json_text(['topic_id' => $tid, 'url' => absolute_url(route_url('topic', ['id' => $tid])), 'message' => '主题已发表']);
    }

    private static function tool_create_reply(array $args): string
    {
        if (!can_speak()) throw new McpToolException('当前账号被禁言，无法回帖', 'denied');
        $tid = self::arg_int($args, 'topic_id', 0, 1, PHP_INT_MAX);
        $t = Topic::find($tid)?->toArray() ?: throw new McpToolException('主题不存在');
        $forum = forum_by_id((int)$t['forum_id']) ?: throw new McpToolException('版块不存在');
        if (!forum_group_allowed($forum, 'allow_reply_groups')) throw new McpToolException('没有在该版块回帖的权限', 'denied');
        $body = cut(self::arg_str($args, 'body'), length_limit('reply_body', 'max'));
        if ($body === '') throw new McpToolException('回复不能为空');
        self::check_min_length($body, length_limit('reply_body', 'min'), '回帖内容');
        self::check_post_interval();
        $author_id = uid();
        $ts = now();
        $rid = Database::connection()->transaction(static function () use ($tid, $author_id, $body, $ts): int {
            $saved = Reply::create(['topic_id' => $tid, 'user_id' => $author_id, 'body' => $body, 'created_at' => $ts, 'updated_at' => $ts]);
            $rid = (int)$saved->id;
            User::whereKey($author_id)->update(['last_post_at' => $ts]);
            Topic::whereKey($tid)->increment('reply_count', 1, ['last_reply_at' => $ts, 'last_reply_user_id' => $author_id]);
            create_reply_notifications($tid, $rid, $body, $author_id);
            return $rid;
        });
        return self::json_text(['topic_id' => $tid, 'reply_id' => $rid, 'url' => absolute_url(route_url('topic', ['id' => $tid, 'replyid' => $rid])), 'message' => '回帖成功']);
    }

    private static function tool_my_info(ApiToken $token): string
    {
        $me = me();
        return self::json_text([
            'user_id' => (int)$me['id'],
            'username' => (string)$me['username'],
            'group' => (string)$me['group_name'],
            'is_muted' => (int)$me['is_muted'] === 1,
            'token_name' => (string)$token?->name,
            'token_created_at' => $token ? date('Y-m-d H:i', (int)$token->created_at) : '',
            'token_last_used_at' => $token && (int)$token->last_used_at > 0 ? date('Y-m-d H:i', (int)$token->last_used_at) : '',
        ]);
    }

    // ---------- 令牌管理（个人设置页调用） ----------

    /** 创建令牌并返回明文；明文只在这一次响应里出现，不落库不重复展示 */
    public static function create_token(int $user_id, string $name): string
    {
        $name = cut(trim($name), 50) ?: 'MCP 接入';
        $plain = self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_HEX_LENGTH / 2));
        ApiToken::create(['user_id' => $user_id, 'name' => $name, 'token_hash' => hash('sha256', $plain), 'created_at' => now(), 'last_used_at' => 0, 'revoked_at' => 0]);
        self::log_call(['user_id' => $user_id, 'tool' => 'token.create', 'status' => 'ok', 'message' => '创建令牌「' . $name . '」']);
        return $plain;
    }

    public static function revoke_token(int $user_id, int $token_id): void
    {
        $updated = ApiToken::whereKey($token_id)->where('user_id', $user_id)->where('revoked_at', 0)->update(['revoked_at' => now()]);
        if (!$updated) err('令牌不存在或已吊销');
        self::log_call(['user_id' => $user_id, 'tool' => 'token.revoke', 'status' => 'ok', 'message' => '吊销令牌 #' . $token_id]);
    }

    public static function tokens_for_user(int $user_id): array
    {
        $rows = ApiToken::where('user_id', $user_id)->orderByDesc('created_at')->orderByDesc('id')->get()->map->toArray()->all();
        foreach ($rows as &$row) {
            $row['is_active'] = (int)$row['revoked_at'] === 0;
            $row['created_time'] = date('Y-m-d H:i', (int)$row['created_at']);
            $row['last_used_time'] = (int)$row['last_used_at'] > 0 ? date('Y-m-d H:i', (int)$row['last_used_at']) : '从未使用';
        }
        unset($row);
        return $rows;
    }

    // ---------- 审计 ----------

    /** 审计落库：令牌创建/吊销与每一次 MCP 调用（含未授权）都各记一条；写失败降级到调试日志 */
    public static function log_call(array $entry): void
    {
        try {
            $status = (string)($entry['status'] ?? 'ok');
            ApiLog::create([
                'user_id' => isset($entry['user_id']) ? (int)$entry['user_id'] : null,
                'token_id' => isset($entry['token_id']) ? (int)$entry['token_id'] : null,
                'tool' => cut((string)($entry['tool'] ?? ''), 100),
                'status' => in_array($status, self::LOG_STATUSES, true) ? $status : 'error',
                'args_json' => cut((string)($entry['args_json'] ?? ''), self::LOG_ARGS_MAX),
                'result_json' => cut((string)($entry['result_json'] ?? ''), self::LOG_RESULT_MAX),
                'message' => cut((string)($entry['message'] ?? ''), 500),
                'ip' => ip_addr(),
                'duration_ms' => max(0, (int)($entry['duration_ms'] ?? 0)),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            debug_log_write('MCP 审计日志写入失败：' . $e->getMessage());
        }
    }

    // ---------- 小工具 ----------

    private static function audit_tool_name(string $method, array $params): string
    {
        return $method === 'tools/call' ? (string)($params['name'] ?? 'tools/call') : $method;
    }

    private static function encode_for_log(array $data): string
    {
        return cut(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', self::LOG_ARGS_MAX);
    }

    private static function json_text(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT) ?: '{}';
    }

    private static function duration_ms(float $started): int
    {
        return max(0, (int)round((microtime(true) - $started) * 1000));
    }

    private static function arg_int(array $args, string $key, int $default, int $min, int $max): int
    {
        $value = $args[$key] ?? null;
        if ($value === null || $value === '') return $default;
        if (!is_numeric($value) || (int)$value != $value) throw new McpToolException('参数 ' . $key . ' 必须是整数');
        return min($max, max($min, (int)$value));
    }

    private static function arg_str(array $args, string $key): string
    {
        $value = $args[$key] ?? '';
        if (!is_scalar($value)) throw new McpToolException('参数 ' . $key . ' 必须是字符串');
        return trim((string)$value);
    }

    private static function check_min_length(string $value, int $minimum, string $label): void
    {
        if ($minimum > 0 && preg_match_all('/./us', $value) < $minimum) throw new McpToolException($label . '至少' . $minimum . '个字符');
    }

    /** 与 check_post_interval() 同口径，但用异常代替 err()，让 MCP 客户端拿到结构化错误 */
    private static function check_post_interval(): void
    {
        $seconds = post_interval_seconds();
        if ($seconds <= 0 || !uid()) return;
        $wait = $seconds - (now() - (int)(User::whereKey(uid())->value('last_post_at') ?? 0));
        if ($wait > 0) throw new McpToolException('操作太频繁，请 ' . $wait . ' 秒后再试');
    }

    private static function rpc_fail(mixed $id, int $code, string $message, int $status = 200): never
    {
        self::respond(['jsonrpc' => '2.0', 'id' => $id ?? null, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    private static function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private static function respond_status(int $status): never
    {
        http_response_code($status);
        exit;
    }
}

/** MCP 鉴权失败（缺令牌/令牌无效/用户不存在），转换成 401 的 JSON-RPC 错误 */
final class McpAuthException extends RuntimeException
{
}

/** MCP 工具层错误：权限不足（denied）或参数/校验失败（error），以 isError 结果返回并计入审计 */
final class McpToolException extends RuntimeException
{
    public function __construct(string $message, public readonly string $audit_status = 'error')
    {
        parent::__construct($message);
    }
}
