<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class WbSearchService
{
    public function __construct(
        protected WbPlaywrightService $playwright,
        protected WbPlaywrightRemoteService $remotePlaywright,
        protected WbPlaywrightNodeMonitor $nodeMonitor
    ) {}

    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $cacheKey = 'wb_search:' . md5($query);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            logger()->info('WB search cache hit', [
                'query' => $query,
                'count' => count($cached),
            ]);
            return $cached;
        }

        $urlTemplate = (string) config('bot.wb.search_api_url');
        if ($urlTemplate === '') {
            return [];
        }

        $url = sprintf($urlTemplate, rawurlencode($query));

        $deadline = microtime(true) + 110;
        while (RateLimiter::tooManyAttempts('wb-search', 5)) {
            if (microtime(true) >= $deadline) {
                return [];
            }
            usleep(500000);
        }
        RateLimiter::hit('wb-search', 120);

        $timeout = (int) config('bot.wb.timeout', 15);
        $usePlaywright = (bool) config('bot.wb.use_playwright', true);
        $delayMs = (int) config('bot.wb.request_delay_ms', 0);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
        $headers = [
            'User-Agent' => (string) config('bot.wb.user_agent'),
            'Accept' => 'application/json,text/plain,*/*',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
            'Origin' => 'https://www.wildberries.ru',
            'Referer' => 'https://www.wildberries.ru/',
        ];

        $proxyUrl = (string) config('bot.wb.proxy_url', '');
        $proxyList = $this->parseProxyList((string) config('bot.wb.proxy_list', ''));
        $proxyHost = (string) config('bot.wb.proxy_host', '');
        $proxyPool = $this->parseProxyList((string) config('bot.wb.proxy_pool', ''));
        $proxyPorts = (string) config('bot.wb.proxy_ports', '10000-10999');
        $proxySchemes = $this->parseProxyList((string) config('bot.wb.proxy_schemes', 'socks5,http'));
        $proxySample = (int) config('bot.wb.proxy_sample', 6);
        $useFallback = (bool) config('bot.wb.proxy_fallback', true);

        $response = null;
        $proxies = $this->buildProxyCandidates(
            $proxyUrl,
            $proxyList,
            $proxyHost,
            $proxyPool,
            $proxyPorts,
            $proxySchemes,
            $proxySample
        );

        $attemptedProxy = false;
        $payload = null;

        if ($usePlaywright) {
            $timeoutMs = (int) config('bot.wb.playwright_timeout_ms', 15000);
            $timeoutMs = max(1000, $timeoutMs);
            $remoteNodes = (array) config('bot.wb.playwright_remote_nodes', []);
            $remoteTimeoutMs = (int) config('bot.wb.playwright_remote_timeout_ms', 60000);
            $remoteTimeoutMs = max(1000, $remoteTimeoutMs);
            $remoteBusyTtl = (int) config('bot.wb.playwright_remote_busy_ttl', 45);
            $remoteCooldown = (int) config('bot.wb.playwright_remote_cooldown_sec', 2);
            $remoteProxyPool = $this->parseConfiguredProxyPool(config('bot.wb.proxy_test_pool', []));

            if (!$payload && !empty($remoteNodes)) {
                $tried = 0;
                $busyCount = 0;
                foreach ($remoteNodes as $node) {
                    $node = trim((string) $node);
                    if ($node === '') {
                        continue;
                    }
                    $tried++;
                    $lockKey = 'wb_remote_busy:' . sha1($node);
                    $acquired = \Illuminate\Support\Facades\Cache::add($lockKey, time(), $remoteBusyTtl);
                    if (!$acquired) {
                        $busyCount++;
                        logger()->info('WB playwright remote busy', [
                            'query' => $query,
                            'node' => $node,
                        ]);
                        $this->nodeMonitor->log($node, 'busy', $query);
                        continue;
                    }

                    $nodeAttempts = $remoteProxyPool;
                    $nodeAttempts[] = [
                        'id' => 'no-proxy',
                        'url' => null,
                    ];

                    $this->nodeMonitor->log($node, 'attempt', $query);
                    logger()->info('WB playwright remote node attempts started', [
                        'query' => $query,
                        'node' => $node,
                        'proxy_ids' => array_map(fn (array $item) => $item['id'] ?? 'no-proxy', $nodeAttempts),
                        'attempts_total' => count($nodeAttempts),
                        'url' => $url,
                    ]);
                    $nodeAttemptResults = [];
                    foreach ($nodeAttempts as $attemptIndex => $remoteProxy) {
                        $proxyId = $remoteProxy['id'] ?? 'no-proxy';
                        $proxyUrl = $remoteProxy['url'] ?? null;

                        $result = $this->remotePlaywright->fetchJson(
                            $node,
                            $url,
                            $headers['User-Agent'],
                            $headers,
                            $remoteTimeoutMs,
                            $proxyUrl
                        );

                        $nodeAttemptResults[] = [
                            'proxy_id' => $proxyId,
                            'without_proxy' => $proxyUrl === null,
                            'status' => $result['status'] ?? null,
                            'error' => $result['error'] ?? null,
                        ];

                        if (($result['ok'] ?? false) === true && is_array($result['payload'] ?? null)) {
                            $payload = $result['payload'];
                            \Illuminate\Support\Facades\Cache::forget($lockKey);
                            $this->nodeMonitor->log($node, 'ok', $query, [
                                'status' => $result['status'] ?? null,
                                'proxy_id' => $proxyId,
                            ]);
                            logger()->info('WB playwright remote response ok', [
                                'query' => $query,
                                'node' => $node,
                                'playwright_node' => $node,
                                'proxy_id' => $proxyId,
                                'proxy' => is_string($proxyUrl) ? $this->maskProxyUrl($proxyUrl) : null,
                                'without_proxy' => $proxyUrl === null,
                                'status' => $result['status'] ?? null,
                                'products_count' => $this->countPayloadProducts($result['payload']),
                                'attempt' => $attemptIndex + 1,
                                'attempts_total' => count($nodeAttempts),
                            ]);
                            break 2;
                        }

                        if ($remoteCooldown > 0) {
                            sleep($remoteCooldown);
                        }
                    }

                    \Illuminate\Support\Facades\Cache::forget($lockKey);
                    logger()->warning('WB playwright remote node attempts failed', [
                        'query' => $query,
                        'node' => $node,
                        'attempts_total' => count($nodeAttempts),
                        'results' => $nodeAttemptResults,
                    ]);
                    $this->nodeMonitor->log($node, 'error', $query, [
                        'error' => 'all proxy attempts failed',
                        'proxy_attempts' => count($nodeAttempts),
                    ]);
                }
                if (!$payload && $tried > 0 && $busyCount === $tried) {
                    logger()->warning('WB playwright remote all busy', [
                        'query' => $query,
                        'nodes' => $remoteNodes,
                    ]);
                }
            }

            if (!$payload && !empty($proxies)) {
                foreach ($proxies as $proxy) {
                    $attemptedProxy = true;
                    logger()->info('WB playwright proxy attempt', [
                        'query' => $query,
                        'playwright_node' => 'local',
                        'proxy' => $this->maskProxyUrl($proxy),
                        'url' => $url,
                    ]);
                    $result = $this->playwright->fetchJson($url, $headers['User-Agent'], $headers, $proxy, $timeoutMs);
                    if (($result['ok'] ?? false) === true && is_array($result['payload'] ?? null)) {
                        $payload = $result['payload'];
                        logger()->info('WB playwright local proxy response ok', [
                            'query' => $query,
                            'playwright_node' => 'local',
                            'proxy_id' => 'local-proxy',
                            'proxy' => $this->maskProxyUrl($proxy),
                            'without_proxy' => false,
                            'status' => $result['status'] ?? null,
                            'products_count' => $this->countPayloadProducts($result['payload']),
                        ]);
                        break;
                    }
                    logger()->warning('WB proxy response not ok, trying next', [
                        'query' => $query,
                        'playwright_node' => 'local',
                        'proxy' => $this->maskProxyUrl($proxy),
                        'status' => $result['status'] ?? null,
                        'error' => $result['error'] ?? null,
                    ]);
                }
            }

            if (!$payload) {
                if (!$attemptedProxy || $useFallback) {
                    logger()->info('WB playwright direct attempt', [
                        'query' => $query,
                        'playwright_node' => 'local',
                        'proxy_id' => 'no-proxy',
                        'url' => $url,
                    ]);
                    $result = $this->playwright->fetchJson($url, $headers['User-Agent'], $headers, null, $timeoutMs);
                    if (($result['ok'] ?? false) === true && is_array($result['payload'] ?? null)) {
                        $payload = $result['payload'];
                        logger()->info('WB playwright direct response ok', [
                            'query' => $query,
                            'playwright_node' => 'local',
                            'proxy_id' => 'no-proxy',
                            'proxy' => null,
                            'without_proxy' => true,
                            'status' => $result['status'] ?? null,
                            'products_count' => $this->countPayloadProducts($result['payload']),
                        ]);
                    } else {
                        logger()->warning('WB direct response not ok', [
                            'query' => $query,
                            'playwright_node' => 'local',
                            'proxy_id' => 'no-proxy',
                            'status' => $result['status'] ?? null,
                            'error' => $result['error'] ?? null,
                        ]);
                    }
                }
            }
        } else {
            if (!empty($proxies)) {
                foreach ($proxies as $proxy) {
                    $attemptedProxy = true;
                    try {
                        $response = Http::timeout($timeout)
                            ->withHeaders($headers)
                            ->withOptions(['proxy' => $proxy])
                            ->get($url);
                        if ($response->ok()) {
                            break;
                        }
                        logger()->warning('WB proxy response not ok, trying next', [
                            'query' => $query,
                            'proxy' => $proxy,
                            'status' => $response->status(),
                        ]);
                    } catch (\Throwable $e) {
                        logger()->warning('WB proxy request failed, trying next', [
                            'query' => $query,
                            'proxy' => $proxy,
                            'error' => $e->getMessage(),
                        ]);
                        $response = null;
                    }
                }
            }

            if (!$response) {
                if (!$attemptedProxy || $useFallback) {
                    try {
                        $response = Http::timeout($timeout)
                            ->withHeaders($headers)
                            ->get($url);
                    } catch (\Throwable $e) {
                        logger()->warning('WB direct request failed', [
                            'query' => $query,
                            'error' => $e->getMessage(),
                        ]);
                        return [];
                    }
                } else {
                    return [];
                }
            }

            if (! $response->ok()) {
                if (!$attemptedProxy) {
                    logger()->warning('WB direct response not ok', [
                        'query' => $query,
                        'status' => $response->status(),
                    ]);
                }
                if (($attemptedProxy || $proxyUrl !== '') && $useFallback) {
                    logger()->warning('WB proxy response not ok, fallback to direct', [
                        'query' => $query,
                        'status' => $response->status(),
                    ]);
                    try {
                        $response = Http::timeout($timeout)
                            ->withHeaders($headers)
                            ->get($url);
                    } catch (\Throwable) {
                        return [];
                    }
                }
                if ($response && in_array($response->status(), [403, 429], true) && microtime(true) < $deadline) {
                    usleep(1500000);
                    return $this->search($query);
                }
                return [];
            }

            $payload = $response->json();
        }

        if (! is_array($payload)) {
            return [];
        }

        $this->logFirstProduct($query, $payload);

        $results = $this->extractProducts($payload);
        if (empty($results)) {
            logger()->warning('WB search returned no products', [
                'query' => $query,
                'url' => $url,
                'status' => $response?->status(),
            ]);
        }
        Cache::put($cacheKey, $results, now()->addMinutes(10));

        return $results;
    }

    protected function parseProxyList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts));
    }

    protected function parseConfiguredProxyPool(mixed $value): array
    {
        if (is_string($value)) {
            $value = $this->parseProxyList($value);
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach (array_values($value) as $index => $item) {
            $id = 'proxy-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $url = '';

            if (is_array($item)) {
                $id = (string) ($item['id'] ?? $id);
                $url = (string) ($item['url'] ?? '');
            } else {
                $url = (string) $item;
            }

            $url = $this->normalizeProxyUrl($url);
            if ($url === '') {
                continue;
            }

            $items[] = [
                'id' => $id,
                'url' => $url,
            ];
        }

        return $items;
    }

    protected function normalizeProxyUrl(string $proxy): string
    {
        $proxy = trim($proxy);
        if ($proxy === '') {
            return '';
        }
        if (str_contains($proxy, '://')) {
            return $proxy;
        }
        if (str_contains($proxy, '@')) {
            [$host, $auth] = explode('@', $proxy, 2);
            return 'http://'.$auth.'@'.$host;
        }
        return 'http://'.$proxy;
    }

    protected function maskProxyUrl(string $proxy): string
    {
        return preg_replace('#//([^:@/]+):([^@/]+)@#', '//***:***@', $proxy) ?? $proxy;
    }

    protected function buildProxyCandidates(
        string $proxyUrl,
        array $proxyList,
        string $proxyHost,
        array $proxyPool,
        string $proxyPorts,
        array $proxySchemes,
        int $proxySample
    ): array {
        $proxies = [];
        if ($proxyUrl !== '') {
            $proxies[] = $proxyUrl;
        }
        foreach ($proxyList as $item) {
            $proxies[] = $item;
        }

        if ($proxyHost !== '' && !empty($proxyPool)) {
            [$minPort, $maxPort] = $this->parsePortRange($proxyPorts);
            $schemes = array_values(array_filter($proxySchemes));
            $sample = max(1, $proxySample);
            $ports = $this->pickRandomPorts($minPort, $maxPort, $sample);
            foreach ($proxyPool as $auth) {
                foreach ($schemes as $scheme) {
                    foreach ($ports as $port) {
                        $proxies[] = $scheme.'://'.$auth.'@'.$proxyHost.':'.$port;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($proxies)));
    }

    protected function parsePortRange(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [10000, 10999];
        }
        if (preg_match('/^(\\d{2,5})\\s*-\\s*(\\d{2,5})$/', $value, $m)) {
            $min = (int) $m[1];
            $max = (int) $m[2];
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }
            return [$min, $max];
        }
        $port = (int) $value;
        return [$port, $port];
    }

    protected function pickRandomPorts(int $min, int $max, int $count): array
    {
        $ports = [];
        $count = min($count, max(1, $max - $min + 1));
        while (count($ports) < $count) {
            $ports[] = random_int($min, $max);
            $ports = array_values(array_unique($ports));
        }
        return $ports;
    }

    protected function countPayloadProducts(array $payload): int
    {
        $products = $payload['data']['products'] ?? $payload['products'] ?? null;

        return is_array($products) ? count($products) : 0;
    }

    protected function logFirstProduct(string $query, array $payload): void
    {
        $products = $payload['data']['products'] ?? $payload['products'] ?? null;
        if (!is_array($products) || empty($products)) {
            return;
        }

        $first = $products[0] ?? null;
        if (!is_array($first)) {
            return;
        }

        logger()->info('WB raw first product', [
            'query' => $query,
            'product' => $first,
        ]);
    }

    protected function extractProducts(array $payload): array
    {
        $products = $payload['data']['products'] ?? $payload['products'] ?? null;
        if (! is_array($products)) {
            return [];
        }

        $results = [];
        foreach ($products as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $item['name'] ?? $item['title'] ?? null;
            $id = $item['id'] ?? null;

            if (! is_string($title) || $title === '' || !is_numeric($id)) {
                continue;
            }

            $results[] = [
                'id' => (int) $id,
                'title' => $title,
                'price' => $this->extractPrice($item),
                'url' => $this->buildUrl((int) $id),
                'brand' => $item['brand'] ?? null,
                'subject' => $item['subjectId'] ?? null,
                'raw' => $item,
            ];
        }

        return $results;
    }

    protected function extractPrice(array $item): ?string
    {
        $priceU = $item['salePriceU'] ?? $item['priceU'] ?? null;
        if (!is_numeric($priceU) && isset($item['sizes'][0]['price']['product'])) {
            $priceU = $item['sizes'][0]['price']['product'];
        }
        if (! is_numeric($priceU)) {
            return null;
        }

        $rub = ((float) $priceU) / 100;

        return number_format($rub, 0, ',', ' ');
    }

    protected function buildUrl(int $id): string
    {
        return 'https://www.wildberries.ru/catalog/'.$id.'/detail.aspx';
    }
}
