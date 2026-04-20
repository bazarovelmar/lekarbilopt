<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WbPlaywrightRemoteService
{
    public function fetchJson(
        string $baseUrl,
        string $url,
        string $userAgent,
        array $headers,
        int $timeoutMs,
        ?string $proxy = null
    ): array {
        $endpoint = rtrim($baseUrl, '/').'/fetch';

        $payload = [
            'url' => $url,
            'userAgent' => $userAgent,
            'headers' => $headers,
            'timeoutMs' => $timeoutMs,
        ];
        if ($proxy !== null && $proxy !== '') {
            $payload['proxy'] = $proxy;
        }

        try {
            $response = Http::timeout(max(5, (int) ceil($timeoutMs / 1000)))
                ->acceptJson()
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }

        $json = $response->json();
        if (!is_array($json)) {
            return [
                'ok' => false,
                'error' => 'invalid remote response',
                'status' => $response->status(),
            ];
        }

        return $json;
    }
}
