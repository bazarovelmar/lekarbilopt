<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AidentikaService
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('bot.aidentika.base_url'), '/');
    }

    protected function headers(): array
    {
        $key = (string) config('bot.aidentika.api_key');
        if ($key === '') {
            throw new RuntimeException('Aidentika API key is missing.');
        }

        return [
            'Authorization' => 'Bearer '.$key,
            'Content-Type' => 'application/json',
        ];
    }

    public function generateCard(string $imagePath, string $productName, ?string $categoryId = null, ?string $conceptId = null): array
    {
        $categoryId = $categoryId ?: (string) config('bot.aidentika.category_id', 'default');
        $conceptId = $conceptId ?: (string) config('bot.aidentika.concept_id', 'default');

        $payload = [
            'images' => [
                [
                    'data' => base64_encode(file_get_contents($imagePath)),
                    'media_type' => $this->guessMediaType($imagePath),
                ],
            ],
            'category_id' => $categoryId,
            'concept_id' => $conceptId,
            'product_name' => $productName,
        ];

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post($this->baseUrl().'/generate/card', $payload);

        if (!$response->ok()) {
            throw new RuntimeException('Aidentika generate failed: '.$response->body());
        }

        return $response->json();
    }

    public function getStatus(int $actionId): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($this->baseUrl().'/status/'.$actionId);

        if (!$response->ok()) {
            throw new RuntimeException('Aidentika status failed: '.$response->body());
        }

        return $response->json();
    }

    public function downloadResult(int $actionId, string $targetPath): void
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->withOptions(['allow_redirects' => true])
            ->get($this->baseUrl().'/results/'.$actionId.'/download');

        if (!$response->ok()) {
            throw new RuntimeException('Aidentika download failed: '.$response->body());
        }

        @mkdir(dirname($targetPath), 0777, true);
        file_put_contents($targetPath, $response->body());
    }

    protected function guessMediaType(string $path): string
    {
        $info = @getimagesize($path);
        return $info['mime'] ?? 'image/jpeg';
    }
}
