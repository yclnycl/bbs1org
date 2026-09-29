<?php
declare(strict_types=1);

namespace app\optional;

use PDO;

/**
 * Google Search Console 客户端（service account，无 composer 依赖）。
 *
 * 凭证：服务账号 JSON 放数据卷 app/data/gsc-service-account.json（web 拦截已覆盖 app/data，
 * 与 db.php 同一先例：密钥属数据卷不属代码库，永不进 commit）。可用环境变量
 * GSC_SERVICE_ACCOUNT_FILE 覆盖路径。
 *
 * 链路：私钥签 RS256 JWT -> oauth2 换 access_token -> webmasters v3
 * （sites 列表自动发现本站 property，searchAnalytics 拉查询词）。
 * 服务账号需要在 GSC 后台被添加为对应资源（property）的委托用户才有数据。
 */
final class Gsc
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    /** @var string|null 每请求内缓存的 access_token，JWT 换 token 一次即可 */
    private static ?string $token_cache = null;

    public static function configured(): bool
    {
        return is_file(self::key_file());
    }

    private static function key_file(): string
    {
        $override = trim((string)getenv('GSC_SERVICE_ACCOUNT_FILE'));
        if ($override !== '') return $override;
        return DATA_DIR . '/gsc-service-account.json';
    }

    /** @return array{client_email:string, private_key:string}|null */
    private static function credentials(): ?array
    {
        static $creds = null;
        if ($creds !== null) return $creds;
        $creds = false;
        $raw = is_file(self::key_file()) ? (string)file_get_contents(self::key_file()) : '';
        $obj = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($obj) && !empty($obj['client_email']) && !empty($obj['private_key'])) {
            $creds = ['client_email' => (string)$obj['client_email'], 'private_key' => (string)$obj['private_key']];
        }
        return $creds ?: null;
    }

    /** RS256 JWT：header.payload.signature，openssl 原生签名，不引第三方库 */
    private static function jwt(array $creds): string
    {
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $now = time();
        $claims = $b64(json_encode([
            'iss' => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($creds['private_key']);
        if ($key === false) throw new \RuntimeException('GSC 私钥无法解析');
        openssl_sign($header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256);
        return $header . '.' . $claims . '.' . $b64((string)$signature);
    }

    private static function access_token(): string
    {
        if (self::$token_cache !== null) return self::$token_cache;
        $creds = self::credentials();
        if ($creds === null) throw new \RuntimeException('GSC 凭证未配置（app/data/gsc-service-account.json）');
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => self::jwt($creds),
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($code !== 200 || !is_string($resp)) throw new \RuntimeException("GSC 换取 token 失败 HTTP {$code} {$err}");
        $token = (string)(json_decode($resp, true)['access_token'] ?? '');
        if ($token === '') throw new \RuntimeException('GSC token 响应缺少 access_token');
        self::$token_cache = $token;
        return $token;
    }

    private static function api_get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::access_token()],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200 || !is_string($resp)) {
            $detail = is_string($resp) ? mb_substr($resp, 0, 200) : '';
            throw new \RuntimeException("GSC 接口 HTTP {$code} {$detail}");
        }
        return (array)json_decode($resp, true);
    }

    private static function api_post(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::access_token(), 'Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 25,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200 || !is_string($resp)) {
            $detail = is_string($resp) ? mb_substr($resp, 0, 200) : '';
            throw new \RuntimeException("GSC 接口 HTTP {$code} {$detail}");
        }
        return (array)json_decode($resp, true);
    }

    /** 账号可见的 GSC 资源列表；空数组通常意味着服务账号还没被加进任何 property */
    public static function sites(): array
    {
        $data = self::api_get('https://www.googleapis.com/webmasters/v3/sites');
        $out = [];
        foreach ((array)($data['siteEntry'] ?? []) as $site) {
            if (!is_array($site) || empty($site['siteUrl'])) continue;
            $out[] = ['url' => (string)$site['siteUrl'], 'permission' => (string)($site['permissionLevel'] ?? '')];
        }
        return $out;
    }

    /** 本站资源：优先站点设置 gsc_site_url，否则从可见资源里按域名匹配（sc-domain 或 URL 形式） */
    public static function site_url(): ?string
    {
        $configured = trim((string)setting('gsc_site_url', ''));
        if ($configured !== '') return $configured;
        $host = (string)parse_url(base_url(), PHP_URL_HOST);
        // 域名型资源是 sc-domain:cncttc.com（无 www 前缀），匹配时把 www 剥掉
        $bare = preg_replace('/^www\./', '', $host) ?: $host;
        foreach (self::sites() as $site) {
            $url = $site['url'];
            if (str_contains($url, "sc-domain:{$bare}") || str_contains($url, "sc-domain:{$host}")
                || str_contains($url, "//{$host}")) {
                return $url;
            }
        }
        return null;
    }

    /**
     * 最近 N 天的真实查询词（GSC 数据有 2-3 天滞后，窗口尾部再退 2 天）。
     * @return list<array{query:string,clicks:int,impressions:int,ctr:float,position:float}>
     */
    public static function queries(int $days, int $limit = 200): array
    {
        $site = self::site_url() ?: throw new \RuntimeException('GSC 里没有本站资源：请把服务账号加为 Search Console 资源的用户');
        $end = (new \DateTimeImmutable('-2 days'));
        $start = $end->modify('-' . max(7, $days) . ' days');
        $data = self::api_post(
            'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query',
            ['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'),
             'dimensions' => ['query'], 'rowLimit' => min(500, max(1, $limit))]);
        $out = [];
        foreach ((array)($data['rows'] ?? []) as $row) {
            if (!is_array($row) || empty($row['keys'][0])) continue;
            $out[] = [
                'query' => mb_substr((string)$row['keys'][0], 0, 60),
                'clicks' => (int)round((float)($row['clicks'] ?? 0)),
                'impressions' => (int)round((float)($row['impressions'] ?? 0)),
                'ctr' => (float)($row['ctr'] ?? 0),
                'position' => (float)($row['position'] ?? 0),
            ];
        }
        return $out;
    }

    /** 拉取结果落库（整表镜像最近一次拉取），供后台候选词面板使用 */
    public static function refresh_queries(int $days, int $limit = 200): int
    {
        self::ensure_schema();
        $rows = self::queries($days, $limit);
        $db = db();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM plugin_gsc_queries');
            $st = $db->prepare('INSERT INTO plugin_gsc_queries (query, clicks, impressions, ctr, position, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?)');
            $ts = now();
            foreach ($rows as $r) {
                $st->execute([$r['query'], $r['clicks'], $r['impressions'], $r['ctr'], $r['position'], $ts]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        save_settings_values(['gsc_queries_fetched_at' => (string)now()]);
        return count($rows);
    }

    /** 后台候选词面板：最近一次拉取的查询词，按展示量降序 */
    public static function stored_queries(int $limit = 50): array
    {
        return db()->query('SELECT * FROM plugin_gsc_queries ORDER BY impressions DESC, clicks DESC LIMIT ' . max(1, $limit))
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 收录监控：全站总量 + /tag/ 话题页表现（按展示量降序，后台看板用）。
     * @return array{totals:array,tag_pages:array,tag_reached:int}
     */
    public static function performance(int $days): array
    {
        $site = self::site_url() ?: throw new \RuntimeException('GSC 里没有本站资源：请把服务账号加为 Search Console 资源的用户');
        $end = (new \DateTimeImmutable('-2 days'));
        $start = $end->modify('-' . max(7, $days) . ' days');
        $window = ['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d')];
        $base = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query';
        $totals_row = self::api_post($base, $window + ['rowLimit' => 1])['rows'][0] ?? [];
        $rows = self::api_post($base, $window + ['dimensions' => ['page'], 'rowLimit' => 1000])['rows'] ?? [];
        $tag_pages = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['keys'][0])) continue;
            $url = (string)$row['keys'][0];
            if (!str_contains($url, '/tag/')) continue;
            $tag_pages[] = [
                'url' => $url,
                'clicks' => (int)round((float)($row['clicks'] ?? 0)),
                'impressions' => (int)round((float)($row['impressions'] ?? 0)),
                'position' => (float)($row['position'] ?? 0),
            ];
        }
        usort($tag_pages, static fn(array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        return [
            'totals' => [
                'clicks' => (int)round((float)($totals_row['clicks'] ?? 0)),
                'impressions' => (int)round((float)($totals_row['impressions'] ?? 0)),
                'ctr' => (float)($totals_row['ctr'] ?? 0),
                'position' => (float)($totals_row['position'] ?? 0),
            ],
            'tag_pages' => array_slice($tag_pages, 0, 40),
            'tag_reached' => count($tag_pages),
        ];
    }

    /** 看板结果缓存进站点设置（后台打开不每次打 API，按钮刷新） */
    public static function refresh_performance(int $days): array
    {
        $data = self::performance($days);
        save_settings_values(['gsc_perf_cache' => json_encode($data + ['days' => $days], JSON_UNESCAPED_UNICODE),
                              'gsc_perf_fetched_at' => (string)now()]);
        return $data;
    }

    public static function cached_performance(): array
    {
        $raw = setting('gsc_perf_cache', '');
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function ensure_schema(): void
    {
        if ((int)setting('gsc_schema_version', '0') >= 1) return;
        db()->exec('CREATE TABLE IF NOT EXISTS plugin_gsc_queries (
            query TEXT PRIMARY KEY,
            clicks INTEGER NOT NULL DEFAULT 0,
            impressions INTEGER NOT NULL DEFAULT 0,
            ctr REAL NOT NULL DEFAULT 0,
            position REAL NOT NULL DEFAULT 0,
            fetched_at INTEGER NOT NULL
        )');
        save_settings_values(['gsc_schema_version' => '1']);
    }
}
