<?php

declare(strict_types=1);

namespace app\optional;

use app\optional\Model\User;

if (!defined('APP_ROOT')) exit;

final class Search
{
    /**
     * 搜索结果页：标题/正文/回帖聚合查询，命中上下文展开并标红（SearchIndex 驱动）。
     * GET 与 POST 都接受：GET 让分页变成普通链接，移动端无限滚动才能翻页，
     * 也与 SearchAction 声明的 /search?q= 语义一致；写操作性质的限频只在第 1 页生效。
     */
    public static function page(): void
    {
        if (!uid()) err('请登录后操作');
        $submitted = is_post_request() || array_key_exists('q', $_GET);
        $max = length_limit('search', 'max');
        $query = $submitted
            ? (is_post_request() ? post('q', $max) : mb_substr(trim((string)($_GET['q'] ?? '')), 0, $max, 'UTF-8'))
            : '';
        $page = $submitted ? min(max_pagination_pages(), max(1, (int)($_POST['p'] ?? $_GET['p'] ?? 1))) : 1;
        if ($query !== '') require_search_min_chars($query);
        $results = [];
        $has_prev = false;
        $has_next = false;
        if ($query !== '' && $submitted) {
            if ($page === 1) {
                $seconds = post_interval_seconds();
                if ($seconds > 0) {
                    $wait = $seconds - (time() - (int)(User::whereKey(uid())->value('last_post_at') ?? 0));
                    if ($wait > 0) err('搜索太频繁，请 ' . $wait . ' 秒后再试');
                    User::whereKey(uid())->update(['last_post_at' => time()]);
                }
            }
            $size = max(1, (int)setting('topics_per_page', '30'));
            $data = SearchIndex::page_results($query, $page, $size);
            $results = $data['rows'];
            $has_prev = $page > 1;
            $has_next = $data['has_next'];
        }
        // 搜索词正好命中话题词库时挂话题卡：搜索结果页给聚合页导一个高相关内链（仅中文页）
        $tag_match = null;
        if ($query !== '' && $submitted && current_lang() === DEFAULT_LANG) {
            $tag = TopicTags::find_active($query);
            if ($tag !== null) {
                $tag_match = ['keyword' => (string)$tag['keyword'], 'summary' => (string)$tag['summary'],
                              'url' => route_url('tag', ['kw' => (string)$tag['keyword']]),
                              'total' => TopicTags::topic_count((string)$tag['keyword'])];
            }
        }
        render_page('search.html.twig', [
            'submitted' => $submitted,
            'query' => $query,
            'results' => $results,
            'has_prev' => $has_prev,
            'has_next' => $has_next,
            'page' => $page,
            'tag_match' => $tag_match,
        ], '搜索');
    }
}
