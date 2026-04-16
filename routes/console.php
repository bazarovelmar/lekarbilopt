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
