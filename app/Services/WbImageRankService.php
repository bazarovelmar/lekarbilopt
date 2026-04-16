<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;

class WbImageRankService
{
    public function rankByText(array $analysis, array $candidates, int $limit): array
    {
        $slice = array_slice($candidates, 0, 10);
        if (empty($slice)) {
            return [];
        }

        $payload = json_encode([
            'analysis' => $analysis,
            'candidates' => array_values($slice),
            'limit' => $limit,
        ], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Return JSON only.
Select the top {$limit} most relevant candidates for the product based on analysis.
Respond strictly in JSON:
{"top_indices":[0,1,2,3,4]}
PROMPT;

        $response = OpenAI::responses()->create([
            'model' => config('bot.text_model', 'gpt-5-mini'),
            'instructions' => 'Return JSON only. Do not include any extra text.',
            'text' => [
                'format' => ['type' => 'json_object'],
            ],
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt."\n\nJSON:\n".$payload],
                ],
            ]],
            'reasoning' => ['effort' => 'minimal'],
            'max_output_tokens' => 800,
        ]);

        $raw = trim($this->extractOutputText($response));
        if ($raw === '') {
            return [];
        }

        $parsed = $this->decodeJson($raw);
        $indices = is_array($parsed) ? ($parsed['top_indices'] ?? null) : null;
        if (!is_array($indices)) {
            return [];
        }

        $items = [];
        foreach ($indices as $idx) {
            if (!is_numeric($idx)) {
                continue;
            }
            $candidate = $slice[(int) $idx] ?? null;
            if (is_array($candidate)) {
                $items[] = $candidate;
            }
        }

        return $items;
    }

    protected function extractOutputText($response): string
    {
        if (is_object($response) && method_exists($response, 'outputText')) {
            return (string) $response->outputText();
        }
        if (is_object($response) && method_exists($response, 'toArray')) {
            $arr = $response->toArray();
            $text = $arr['output_text'] ?? null;
            return is_string($text) ? $text : '';
        }
        return '';
    }

    protected function decodeJson(string $raw): ?array
    {
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
