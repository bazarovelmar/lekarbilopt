<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class WbPlaywrightService
{
    public function fetchJson(string $url, string $userAgent, array $headers, ?string $proxy, int $timeoutMs): array
    {
        $script = base_path('scripts/wb_playwright.cjs');
        $args = [
            'node',
            $script,
            '--url',
            $url,
            '--userAgent',
            $userAgent,
            '--timeout',
            (string) $timeoutMs,
            '--headers',
            json_encode($headers, JSON_UNESCAPED_UNICODE),
        ];

        if (is_string($proxy) && $proxy !== '') {
            $args[] = '--proxy';
            $args[] = $proxy;
        }

        $process = new Process($args, base_path(), [
            'NODE_PATH' => '/usr/local/lib/node_modules',
            'PLAYWRIGHT_BROWSERS_PATH' => '/ms-playwright',
        ]);
        $process->setTimeout(max(10, (int) ceil($timeoutMs / 1000) + 10));
        $process->run();

        $output = trim($process->getOutput());
        if (! $process->isSuccessful()) {
            return [
                'ok' => false,
                'error' => trim($process->getErrorOutput()) ?: ($output !== '' ? $output : 'playwright failed'),
            ];
        }

        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'invalid playwright response',
            ];
        }

        return $decoded;
    }
}
