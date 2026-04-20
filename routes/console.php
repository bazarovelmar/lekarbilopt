<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use App\Services\WbSearchService;
use App\Services\WbCardService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram:webhook:set {--drop=1}', function () {
    $defaultBot = config('telegram.default', 'mybot');
    $token = (string) config("telegram.bots.{$defaultBot}.token", '');
    $url = (string) env('TELEGRAM_WEBHOOK_URL', '');
    $drop = (string) $this->option('drop');

    if ($token === '' || $token === 'YOUR-BOT-TOKEN') {
        $this->error('TELEGRAM_BOT_TOKEN не задан.');
        return 1;
    }
    if ($url === '' || $url === 'YOUR-BOT-WEBHOOK-URL') {
        $this->error('TELEGRAM_WEBHOOK_URL не задан.');
        return 1;
    }

    $endpoint = "https://api.telegram.org/bot{$token}/setWebhook";
    $resp = Http::asForm()->post($endpoint, [
        'url' => $url,
        'drop_pending_updates' => $drop,
    ]);

    $this->line($resp->body());
    return $resp->successful() ? 0 : 1;
})->purpose('Установить Telegram webhook');

Artisan::command('telegram:webhook:delete {--drop=1}', function () {
    $defaultBot = config('telegram.default', 'mybot');
    $token = (string) config("telegram.bots.{$defaultBot}.token", '');
    $drop = (string) $this->option('drop');

    if ($token === '' || $token === 'YOUR-BOT-TOKEN') {
        $this->error('TELEGRAM_BOT_TOKEN не задан.');
        return 1;
    }

    $endpoint = "https://api.telegram.org/bot{$token}/deleteWebhook";
    $resp = Http::asForm()->post($endpoint, [
        'drop_pending_updates' => $drop,
    ]);

    $this->line($resp->body());
    return $resp->successful() ? 0 : 1;
})->purpose('Удалить Telegram webhook');

Artisan::command('telegram:webhook:info', function () {
    $defaultBot = config('telegram.default', 'mybot');
    $token = (string) config("telegram.bots.{$defaultBot}.token", '');

    if ($token === '' || $token === 'YOUR-BOT-TOKEN') {
        $this->error('TELEGRAM_BOT_TOKEN не задан.');
        return 1;
    }

    $resp = Http::get("https://api.telegram.org/bot{$token}/getWebhookInfo");
    $this->line($resp->body());
    return $resp->successful() ? 0 : 1;
})->purpose('Показать текущее состояние Telegram webhook');

Artisan::command('aidentika:webhook:list', function () {
    $base = rtrim((string) config('bot.aidentika.base_url', 'https://api.aidentika.com/api/v1/public'), '/');
    $apiKey = (string) config('bot.aidentika.api_key', '');

    if ($apiKey === '') {
        $this->error('AIDENTIKA_API_KEY не задан.');
        return 1;
    }

    $resp = Http::withHeaders([
        'Authorization' => 'Bearer '.$apiKey,
    ])->get("{$base}/webhooks");

    $this->line($resp->body());
    return $resp->successful() ? 0 : 1;
})->purpose('Список всех webhook\'ов Aidentika');

Artisan::command('aidentika:webhook:delete {id}', function () {
    $base = rtrim((string) config('bot.aidentika.base_url', 'https://api.aidentika.com/api/v1/public'), '/');
    $apiKey = (string) config('bot.aidentika.api_key', '');
    $id = (string) $this->argument('id');

    if ($apiKey === '') {
        $this->error('AIDENTIKA_API_KEY не задан.');
        return 1;
    }
    if ($id === '') {
        $this->error('Укажите id webhook\'а.');
        return 1;
    }

    $resp = Http::withHeaders([
        'Authorization' => 'Bearer '.$apiKey,
    ])->delete("{$base}/webhooks/{$id}");

    $this->line($resp->body() ?: 'deleted');
    return $resp->successful() ? 0 : 1;
})->purpose('Удалить webhook Aidentika по id');

Artisan::command('aidentika:webhook:set {--events=}', function () {
    $base = rtrim((string) config('bot.aidentika.base_url', 'https://api.aidentika.com/api/v1/public'), '/');
    $apiKey = (string) config('bot.aidentika.api_key', '');
    $url = (string) env('AIDENTIKA_WEBHOOK_URL', '');
    $eventsRaw = (string) ($this->option('events') ?: 'generation.completed,generation.failed');

    if ($apiKey === '') {
        $this->error('AIDENTIKA_API_KEY не задан.');
        return 1;
    }
    if ($url === '') {
        $this->error('AIDENTIKA_WEBHOOK_URL не задан.');
        return 1;
    }

    $events = array_values(array_filter(array_map('trim', explode(',', $eventsRaw))));
    $payload = ['url' => $url];
    if (!empty($events)) {
        $payload['events'] = $events;
    }

    $resp = Http::withHeaders([
        'Authorization' => 'Bearer '.$apiKey,
        'Content-Type' => 'application/json',
    ])->post("{$base}/webhooks", $payload);

    $this->line($resp->body());
    return $resp->successful() ? 0 : 1;
})->purpose('Установить Aidentika webhook');

Artisan::command('wb:search {query}', function (WbSearchService $wbSearch) {
    $query = (string) $this->argument('query');
    if (trim($query) === '') {
        $this->error('Пустой query.');
        return 1;
    }

    $results = $wbSearch->search($query);
    $this->line(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return 0;
})->purpose('Поиск WB по тексту, вывод JSON');

Artisan::command('wb:proxy-test {--node=https://playwrite3.lekarbil.ru} {--proxy=} {--pool} {--query=дисковод PS5} {--timeout=60000} {--ip}', function () {
    $node = rtrim((string) $this->option('node'), '/');
    $proxyRaw = trim((string) $this->option('proxy'));
    $query = trim((string) $this->option('query'));
    $timeoutMs = max(1000, (int) $this->option('timeout'));

    if ($node === '') {
        $this->error('Укажите --node.');
        return 1;
    }
    if ($query === '') {
        $this->error('Укажите --query.');
        return 1;
    }

    $proxyList = [];
    if ((bool) $this->option('pool')) {
        $pool = config('bot.wb.proxy_test_pool', []);
        if (!is_array($pool)) {
            $this->error('config bot.wb.proxy_test_pool должен быть массивом.');
            return 1;
        }
        $proxyList = array_values(array_filter($pool));
    } elseif ($proxyRaw !== '') {
        $proxyList = [['id' => 'single', 'url' => $proxyRaw]];
    }

    if (empty($proxyList)) {
        $this->error('Укажите --proxy или заполните config bot.wb.proxy_test_pool и передайте --pool.');
        return 1;
    }

    $headers = [
        'User-Agent' => (string) config('bot.wb.user_agent'),
        'Accept' => 'application/json,text/plain,*/*',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
        'Origin' => 'https://www.wildberries.ru',
        'Referer' => 'https://www.wildberries.ru/',
    ];

    $endpoint = $node.'/fetch';
    $this->line('Node: '.$node);

    $urlTemplate = (string) config('bot.wb.search_api_url');
    $url = sprintf($urlTemplate, rawurlencode($query));

    $this->line('WB URL: '.$url);

    $okCount = 0;
    foreach ($proxyList as $index => $proxyItem) {
        [$proxyId, $proxyRawItem] = parseWbProxyTestItem($proxyItem, $index + 1);
        $proxy = normalizeWbProxyForTest($proxyRawItem);
        $this->newLine();
        $this->line('['.($index + 1).'/'.count($proxyList).'] Proxy ID: '.$proxyId);
        $this->line('Proxy: '.maskWbProxyForTest($proxy));

        $ipPayload = null;
        if ((bool) $this->option('ip')) {
            $ipResp = Http::timeout(max(5, (int) ceil($timeoutMs / 1000)))
                ->acceptJson()
                ->post($endpoint, [
                    'url' => 'https://api.ipify.org?format=json',
                    'proxy' => $proxy,
                    'headers' => $headers,
                    'timeoutMs' => $timeoutMs,
                ]);
            $ipPayload = $ipResp->json();
            $this->line('IP test: '.$ipResp->body());
        }

        $resp = Http::timeout(max(5, (int) ceil($timeoutMs / 1000)))
            ->acceptJson()
            ->post($endpoint, [
                'url' => $url,
                'proxy' => $proxy,
                'userAgent' => $headers['User-Agent'],
                'headers' => $headers,
                'timeoutMs' => $timeoutMs,
            ]);

        $json = $resp->json();
        if (!is_array($json)) {
            $this->line('WB response: '.$resp->body());
            logger()->warning('WB proxy test invalid response', [
                'proxy_id' => $proxyId,
                'proxy' => maskWbProxyForTest($proxy),
                'node' => $node,
                'query' => $query,
                'http_status' => $resp->status(),
            ]);
            continue;
        }

        $count = count($json['payload']['data']['products'] ?? []);
        if (($json['ok'] ?? false) === true) {
            $okCount++;
        }
        logger()->info('WB proxy test result', [
            'proxy_id' => $proxyId,
            'proxy' => maskWbProxyForTest($proxy),
            'proxy_ip' => is_array($ipPayload) ? ($ipPayload['payload']['ip'] ?? null) : null,
            'node' => $node,
            'query' => $query,
            'ok' => $json['ok'] ?? null,
            'status' => $json['status'] ?? null,
            'error' => $json['error'] ?? null,
            'products_count' => $count,
        ]);
        $this->line('WB response:');
        $this->line(json_encode([
            'proxy_id' => $proxyId,
            'ok' => $json['ok'] ?? null,
            'status' => $json['status'] ?? null,
            'error' => $json['error'] ?? null,
            'products_count' => $count,
            'body_snippet' => $json['body_snippet'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    $this->newLine();
    $this->line("Done. OK responses: {$okCount}/".count($proxyList));

    return $okCount > 0 ? 0 : 1;
})->purpose('WB: тест remote Playwright-ноды через proxy');

if (! function_exists('parseWbProxyTestItem')) {
    function parseWbProxyTestItem(mixed $item, int $fallbackIndex): array
    {
        if (is_array($item)) {
            $id = (string) ($item['id'] ?? 'proxy-'.str_pad((string) $fallbackIndex, 2, '0', STR_PAD_LEFT));
            $url = (string) ($item['url'] ?? '');
            return [$id, $url];
        }

        return ['proxy-'.str_pad((string) $fallbackIndex, 2, '0', STR_PAD_LEFT), (string) $item];
    }
}

if (! function_exists('normalizeWbProxyForTest')) {
    function normalizeWbProxyForTest(string $proxy): string
    {
        $proxy = trim($proxy);
        if (str_contains($proxy, '://')) {
            return $proxy;
        }
        if (str_contains($proxy, '@')) {
            [$host, $auth] = explode('@', $proxy, 2);
            return 'http://'.$auth.'@'.$host;
        }
        return 'http://'.$proxy;
    }
}

if (! function_exists('maskWbProxyForTest')) {
    function maskWbProxyForTest(string $proxy): string
    {
        return preg_replace('#//([^:@/]+):([^@/]+)@#', '//***:***@', $proxy) ?? $proxy;
    }
}

Artisan::command('wb:image-url {nmId}', function (WbCardService $cards) {
    $nmId = (int) $this->argument('nmId');
    if ($nmId <= 0) {
        $this->error('Неверный nmId.');
        return 1;
    }
    $urls = $cards->buildImageUrls($nmId);
    $this->line(json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return 0;
})->purpose('WB: получить ссылку на картинку по nmId');
