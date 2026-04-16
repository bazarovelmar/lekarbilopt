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
        int $timeoutMs
    ): array {
        $endpoint = rtrim($baseUrl, '/').'/fetch';

        try {
            $response = Http::timeout(max(5, (int) ceil($timeoutMs / 1000)))
                ->acceptJson()
                ->post($endpoint, [
                    'url' => $url,
                    'userAgent' => $userAgent,
                    'headers' => $headers,
                    'timeoutMs' => $timeoutMs,
                ]);
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
