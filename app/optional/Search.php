<?php
declare(strict_types=1);

namespace app\optional;

use app\optional\Model\User;

if (!defined('APP_ROOT')) exit;

final class Search
{
    public static function page(): void
    {
        if (!uid()) err('请登录后操作');
        $submitted = is_post_request();
        $query = $submitted ? post('q', length_limit('search', 'max')) : '';
        $field = topic_search_field($submitted ? (string)($_POST['field'] ?? 'title') : 'title');
        $page = $submitted ? min(max_pagination_pages(), max(1, (int)($_POST['p'] ?? 1))) : 1;
        if ($query !== '') require_search_min_chars($query);
        $rows = [];
        $has_prev = false;
        $has_next = false;
        if ($query !== '') {
            if ($page === 1) {
                $seconds = post_interval_seconds();
                if ($seconds > 0) {
                    $wait = $seconds - (time() - (int)(User::whereKey(uid())->value('last_post_at') ?? 0));
                    if ($wait > 0) err('搜索太频繁，请 ' . $wait . ' 秒后再试');
                    User::whereKey(uid())->update(['last_post_at' => time()]);
                }
            }
            $size = max(1, (int)setting('topics_per_page', '30'));
            $data = topic_index_data(0, null, 'topics', $query, $field, 'comment', $page, $size);
            foreach ($data['rows'] as $row) {
                $row['time'] = (int)($row['list_time'] ?? $row['my_reply_at'] ?? ($row['last_reply_at'] ?: $row['created_at']));
                $row['forum'] = forum_by_id((int)$row['forum_id']) ?: ['id' => 0, 'name' => ''];
                $rows[] = $row;
            }
            $has_prev = $page > 1;
            $has_next = (bool)$data['has_next_page'];
        }
        render_page('search.html.twig', [
            'submitted' => $submitted,
            'query' => $query,
            'field' => $field,
            'field_options' => ['title' => '标题', 'body' => '内容', 'reply' => '回帖'],
            'rows' => $rows,
            'has_prev' => $has_prev,
            'has_next' => $has_next,
            'page' => $page,
        ], '搜索');
    }
}
