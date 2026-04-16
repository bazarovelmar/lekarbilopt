<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WbCardService
{
    public function fetchCard(int $nmId): ?array
    {
        if ($nmId <= 0) {
            return null;
        }

        $cacheKey = 'wb_card:' . $nmId;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $url = 'https://card.wb.ru/cards/detail?appType=1&curr=rub&dest=-1257786&nm=' . $nmId;
        try {
            $resp = Http::timeout(10)->get($url);
        } catch (\Throwable $e) {
            logger()->warning('WB card request failed', [
                'nmId' => $nmId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (! $resp->ok()) {
            logger()->warning('WB card response not ok', [
                'nmId' => $nmId,
                'status' => $resp->status(),
            ]);
            return null;
        }

        $data = $resp->json();
        if (!is_array($data)) {
            logger()->warning('WB card invalid JSON', [
                'nmId' => $nmId,
            ]);
            return null;
        }

        Cache::put($cacheKey, $data, now()->addMinutes(10));
        return $data;
    }

    public function buildImageUrls(int $nmId): array
    {
        $card = $this->fetchCard($nmId);
        if (!is_array($card)) {
            return [];
        }

        $product = $card['data']['products'][0] ?? null;
        if (!is_array($product)) {
            return [];
        }

        $img = $product['img'] ?? null;
        if (!is_string($img) || $img === '') {
            logger()->warning('WB card missing img', [
                'nmId' => $nmId,
                'product' => $product,
            ]);
            return [];
        }

        $base = str_starts_with($img, '//') ? 'https:' . $img : $img;
        $urls = [
            'big_webp' => $base . '/images/big/1.webp',
            'medium_jpg' => $base . '/images/c246x328/1.jpg',
            'base' => $base,
        ];
        logger()->info('WB card image urls', [
            'nmId' => $nmId,
            'urls' => $urls,
        ]);
        return $urls;
    }
}
