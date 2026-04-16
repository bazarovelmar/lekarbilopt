<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class InfographicService
{
    public function generateImage(string $imagePath, array $wbItem, ?float $userPrice): array
    {
        $dataUrl = $this->imageToDataUrl($imagePath);
        $payload = json_encode([
            'wb_item' => $wbItem,
            'user_price' => $userPrice,
        ], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Create a dark-themed Telegram infographic image.
Use the provided product photo as the main visual.
Include brand/logo as text (wordmark) if available.
Add a title, 3 short bullets, and two prices: WB price and "Ваша цена".
Layout: portrait, clean grid, high contrast, readable.
PROMPT;

        $response = OpenAI::responses()->create([
            'model' => config('bot.image_model', 'gpt-5'),
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt."\n\nJSON:\n".$payload],
                    ['type' => 'input_image', 'image_url' => $dataUrl, 'detail' => 'high'],
                ],
            ]],
            'tools' => [[
                'type' => 'image_generation',
                'size' => '1024x1536',
                'quality' => 'high',
                'background' => 'opaque',
            ]],
            'max_output_tokens' => 300,
        ]);

        $base64 = $this->extractImageBase64($response);
        if ($base64 === '') {
            throw new RuntimeException('Infographic generation failed.');
        }

        $filename = 'infographics/'.now()->format('Ymd_His').'_'.uniqid().'.png';
        $path = storage_path('app/'.$filename);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, base64_decode($base64));

        return [
            'image_path' => $path,
        ];
    }

    protected function imageToDataUrl(string $imagePath): string
    {
        $binary = @file_get_contents($imagePath);
        if ($binary === false) {
            throw new RuntimeException('Failed to read image.');
        }

        $info = @getimagesize($imagePath);
        $mime = $info['mime'] ?? 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    protected function extractOutputText(object $response): string
    {
        if (isset($response->outputText) && is_string($response->outputText) && $response->outputText !== '') {
            return $response->outputText;
        }

        $chunks = [];
        foreach ($response->output ?? [] as $output) {
            $type = is_object($output) ? ($output->type ?? null) : ($output['type'] ?? null);
            if ($type === 'message') {
                $content = is_object($output) ? ($output->content ?? null) : ($output['content'] ?? null);
                if (is_array($content)) {
                    foreach ($content as $part) {
                        $partType = is_object($part) ? ($part->type ?? null) : ($part['type'] ?? null);
                        $text = is_object($part) ? ($part->text ?? null) : ($part['text'] ?? null);
                        if ($partType === 'output_text' && is_string($text)) {
                            $chunks[] = $text;
                        }
                    }
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    protected function extractImageBase64(object $response): string
    {
        foreach ($response->output ?? [] as $output) {
            $type = is_object($output) ? ($output->type ?? null) : ($output['type'] ?? null);
            if ($type === 'image_generation_call') {
                $result = is_object($output) ? ($output->result ?? null) : ($output['result'] ?? null);
                if (is_string($result) && $result !== '') {
                    return $result;
                }
            }
        }

        return '';
    }

    protected function decodeJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
