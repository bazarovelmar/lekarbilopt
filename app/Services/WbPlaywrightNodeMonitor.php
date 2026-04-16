<?php

namespace App\Services;

use App\Models\WbPlaywrightNodeLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WbPlaywrightNodeMonitor
{
    public function log(string $node, string $status, ?string $query = null, array $data = []): void
    {
        WbPlaywrightNodeLog::create([
            'node' => $node,
            'query' => $query,
            'status' => $status,
            'data' => $data ?: null,
        ]);
    }

    public function getNodeStats(array $nodes, int $busyTtlSeconds): array
    {
        $nodes = array_values(array_filter(array_map('trim', $nodes)));
        if (empty($nodes)) {
            return [
                'total' => 0,
                'busy' => 0,
                'free' => 0,
                'busy_nodes' => [],
                'free_nodes' => [],
            ];
        }

        $cutoff = Carbon::now()->subSeconds($busyTtlSeconds);

        /** @var Collection<int, WbPlaywrightNodeLog> $latest */
        $latest = WbPlaywrightNodeLog::query()
            ->select('node')
            ->selectRaw('MAX(created_at) as last_at')
            ->whereIn('node', $nodes)
            ->groupBy('node')
            ->get()
            ->keyBy('node');

        $busyNodes = [];
        $freeNodes = [];
        foreach ($nodes as $node) {
            $last = $latest->get($node);
            if ($last && $last->last_at && Carbon::parse($last->last_at)->gte($cutoff)) {
                $busyNodes[] = $node;
            } else {
                $freeNodes[] = $node;
            }
        }

        return [
            'total' => count($nodes),
            'busy' => count($busyNodes),
            'free' => count($freeNodes),
            'busy_nodes' => $busyNodes,
            'free_nodes' => $freeNodes,
        ];
    }
}
