<?php

namespace App\Services;

use RuntimeException;

class WbPlaywrightSearchService
{
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $script = base_path('scripts/wb_search.mjs');
        if (!is_file($script)) {
            throw new RuntimeException('WB search script not found.');
        }

        $nodePath = $this->resolveNodePath();
        $nodeBin = '/usr/bin/node';
        $command = sprintf(
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin NODE_PATH=%s PLAYWRIGHT_PATH=%s PLAYWRIGHT_BROWSERS_PATH=/ms-playwright %s %s %s %d 2>&1',
            escapeshellarg($nodePath),
            escapeshellarg($nodePath.'/playwright'),
            escapeshellarg($nodeBin),
            escapeshellarg($script),
            escapeshellarg($query),
            $limit
        );

        if (!file_exists($nodeBin)) {
            logger()->warning('WB Playwright node binary missing', [
                'node_bin' => $nodeBin,
                'which_node' => trim((string) shell_exec('which node 2>/dev/null')),
            ]);
        }

        exec($command, $output, $code);

        if ($code !== 0) {
            logger()->warning('WB Playwright search failed', [
                'query' => $query,
                'command' => $command,
                'exit_code' => $code,
                'output' => $output,
            ]);
            return [];
        }

        $raw = trim(implode("\n", $output));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            logger()->warning('WB Playwright search returned invalid JSON', [
                'query' => $query,
                'raw' => $raw,
            ]);
            return [];
        }

        return $decoded;
    }

    protected function resolveNodePath(): string
    {
        $npmRoot = trim((string) shell_exec('npm root -g 2>/dev/null'));
        if ($npmRoot !== '' && is_dir($npmRoot)) {
            return $npmRoot;
        }

        $candidates = [
            '/usr/local/lib/node_modules',
            '/usr/lib/node_modules',
            '/usr/local/lib/node',
            '/usr/lib/node',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
