<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class WbImageSearchService
{
    protected ?string $lastQuery = null;

    public function __construct(
        protected WbSearchService $wbSearch,
        protected WbImageRankService $rankService,
        protected ProductCategoryService $productCategories
    ) {}

    public function findLinks(string $imagePath, int $limit = 3, ?callable $progress = null): array
    {
        $analysis = $this->analyzeImage($imagePath);
        $rawQuery = trim((string) ($analysis['search_query'] ?? ''));
        $query = $this->normalizeQuery($rawQuery);
        $query = $this->enforceProductType($query, $rawQuery, $analysis);
        $query = $this->compressQueryCore($query, $rawQuery, $analysis);
        $this->lastQuery = $query;
        if ($query === '') {
            return [];
        }

        $queries = $this->buildQuerySet($analysis, $query, $rawQuery);
        $candidates = $this->collectCandidates($queries, $progress);
        if (empty($candidates)) {
            return [];
        }

        $ranked = $this->rankService->rankByText($analysis, $candidates, $limit);
        if (!empty($ranked)) {
            return $this->productCategories->applyToItems($ranked, $analysis);
        }

        return $this->productCategories->applyToItems(array_slice($candidates, 0, $limit), $analysis);
    }

    public function getLastQuery(): ?string
    {
        return $this->lastQuery;
    }

    protected function analyzeImage(string $imagePath): array
    {
        $prompt = (string) config('bot.wb_minimal_prompt');
        $categoryPrompt = $this->productCategories->buildSelectionPrompt();
        $dataUrl = $this->imageToDataUrl($imagePath);

        $payload = $this->buildResponsePayload([
            'model' => config('bot.vision_model', 'gpt-5-mini'),
            'instructions' => $prompt,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'wb_search_query',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'product_type' => [
                                'type' => 'string',
                            ],
                            'search_query' => [
                                'type' => 'string',
                            ],
                            'category_slug' => [
                                'type' => 'string',
                            ],
                            'subcategory_slug' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => ['product_type', 'search_query', 'category_slug', 'subcategory_slug'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt."\n\nВыбери category_slug и subcategory_slug строго из списка ниже.\nЕсли не подходит ничего, ставь category_slug=\"other\", subcategory_slug=\"\".\n\n".$categoryPrompt."\n\nВерни только JSON: product_type, search_query, category_slug, subcategory_slug."],
                    ['type' => 'input_image', 'image_url' => $dataUrl, 'detail' => 'high'],
                ],
            ]],
            'reasoning' => ['effort' => 'minimal'],
            'max_output_tokens' => 1000,
        ]);

        try {
            $response = OpenAI::responses()->create($payload);
        } catch (\Throwable $e) {
            logger()->warning('WB minimal analysis json_schema failed, fallback to json_object', [
                'error' => $e->getMessage(),
            ]);
            $payload['text']['format'] = ['type' => 'json_object'];
            $response = OpenAI::responses()->create($payload);
        }

        $raw = trim($this->extractOutputText($response));
        logger()->info('WB minimal analysis raw response', [
            'raw' => $raw,
            'output_types' => $this->collectOutputTypes($response),
        ]);
        if ($raw === '') {
            logger()->warning('WB minimal analysis empty output', [
                'model' => config('bot.vision_model', 'gpt-5-mini'),
                'max_output_tokens' => 1000,
                'output_types' => $this->collectOutputTypes($response),
                'raw_outputs' => is_object($response) && method_exists($response, 'toArray')
                    ? $response->toArray()
                    : null,
            ]);
        }
        $data = $this->decodeJson($raw);

        if (!is_array($data)) {
            logger()->warning('WB minimal analysis JSON decode failed', [
                'raw' => $raw,
            ]);
            return [];
        }

        $data = $this->productCategories->normalizeSelection($data);

        logger()->info('WB minimal analysis parsed', [
            'parsed' => $data,
        ]);

        return $data;
    }

    protected function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        if (!preg_match('/[A-Za-z]/', $query)) {
            return $query;
        }

        $prompt = <<<PROMPT
Перепиши поисковый запрос для Wildberries на русском языке.
Сохрани бренд и модель/серию без изменений (латиница допустима).
Убери лишние прилагательные и общие слова.
Ответь одной строкой без кавычек и без пояснений.
Запрос: "{$query}"
PROMPT;

        $response = OpenAI::responses()->create($this->buildResponsePayload([
            'model' => config('bot.text_model', 'gpt-5-mini'),
            'instructions' => 'Отвечай только одной строкой текста на русском языке.',
            'reasoning' => ['effort' => 'minimal'],
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                ],
            ]],
            'max_output_tokens' => 200,
        ]));

        $raw = trim($this->extractOutputText($response));
        if ($raw === '') {
            logger()->warning('WB query normalization failed, keeping original', [
                'original' => $query,
                'output_types' => $this->collectOutputTypes($response),
                'raw_outputs' => is_object($response) && method_exists($response, 'toArray')
                    ? $response->toArray()
                    : null,
            ]);
            return $query;
        }

        logger()->info('WB query normalized', [
            'original' => $query,
            'normalized' => $raw,
        ]);

        return $raw;
    }

    protected function enforceProductType(string $query, string $rawQuery, array $analysis): string
    {
        $q = trim($query);
        $raw = mb_strtolower($rawQuery);
        $cat = mb_strtolower(trim((string) ($analysis['category'] ?? '')));
        $containsCase = str_contains($raw, 'case') || str_contains($raw, 'cover') || str_contains($cat, 'case') || str_contains($cat, 'чехол');
        $containsIphone = str_contains($raw, 'iphone') || str_contains($q, 'iphone') || str_contains($cat, 'iphone');

        if ($containsCase && !preg_match('/\\b(чехол|кейс)\\b/iu', $q)) {
            $q = 'чехол '.$q;
        }

        if ($containsIphone && !preg_match('/\\biphone\\b/iu', $q)) {
            $q .= ' iPhone';
        }

        $q = preg_replace('/\\s+/u', ' ', trim($q));

        return $q;
    }

    protected function compressQueryCore(string $query, string $rawQuery, array $analysis): string
    {
        $tokens = preg_split('/\\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return $query;
        }

        $filtered = [];
        foreach ($tokens as $token) {
            $clean = trim($token, " \t\n\r\0\x0B.,;:()[]{}\"'");
            if ($clean === '') {
                continue;
            }
            if ($this->isYearToken($clean) || $this->isSizeToken($clean)) {
                continue;
            }
            $lower = mb_strtolower($clean);
            if (in_array($lower, ['new', 'original', 'premium', 'modern', 'leather', 'for'], true)) {
                continue;
            }
            if (preg_match('/\\b(черн(ый|ая|ое)|бел(ый|ая|ое)|красн(ый|ая|ое)|син(ий|яя|ее)|сер(ый|ая|ое))\\b/iu', $lower)) {
                continue;
            }
            $filtered[] = $clean;
            if (count($filtered) >= 4) {
                break;
            }
        }

        $result = trim(implode(' ', $filtered));
        return $result !== '' ? $result : $query;
    }


    protected function buildFallbackQuery(string $query): string
    {
        $clean = preg_replace('/\\([^)]*\\)/u', ' ', $query);
        $clean = preg_replace('/\\b(xxs|xs|s|m|l|xl|xxl|xxxl)\\b/iu', ' ', $clean);
        $clean = preg_replace('/\\b(\\d{2,3})\\b/iu', ' ', $clean);
        $clean = preg_replace('/\\b(черн(ый|ая|ое)|бел(ый|ая|ое)|красн(ый|ая|ое)|син(ий|яя|ее)|голуб(ой|ая|ое)|зел(еный|еная|еное)|желт(ый|ая|ое)|сер(ый|ая|ое)|матов(ый|ая|ое)|глянцев(ый|ая|ое))\\b/iu', ' ', $clean);
        $clean = preg_replace('/\\s+/u', ' ', $clean);
        $clean = trim($clean);
        if ($this->isTooGenericQuery($clean)) {
            return '';
        }
        return $clean;
    }

    protected function removeLatinTokens(string $query): string
    {
        $clean = preg_replace('/\\b[A-Za-z0-9\\-]+\\b/u', ' ', $query);
        $clean = preg_replace('/\\s+/u', ' ', $clean);
        return trim($clean);
    }

    protected function buildBroadQuery(string $query): string
    {
        $clean = $this->buildFallbackQuery($query);
        $clean = $this->removeLatinTokens($clean);
        $tokens = preg_split('/\\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            return '';
        }

        if (count($tokens) < 2) {
            return '';
        }

        $keep = array_slice($tokens, 0, 3);
        $result = trim(implode(' ', $keep));
        return $this->isTooGenericQuery($result) ? '' : $result;
    }

    protected function buildQuerySet(array $analysis, string $query, string $rawQuery): array
    {
        $queries = [trim($query)];

        logger()->info('WB query set', [
            'queries' => $queries,
        ]);

        return $queries;
    }

    protected function isTooGenericQuery(string $query): bool
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return true;
        }

        $generic = [
            'черный', 'чёрный', 'белый', 'красный', 'синий', 'серый',
            'матовый', 'глянцевый', 'черная', 'чёрная', 'белая',
            'игровая', 'геймерская', 'набор', 'комплект',
        ];

        return in_array($q, $generic, true);
    }

    protected function isHelmetContext(array $analysis, string $query, string $rawQuery): bool
    {
        $category = mb_strtolower(trim((string) ($analysis['category'] ?? '')));
        $hay = mb_strtolower($query.' '.$rawQuery.' '.$category);
        return (bool) preg_match('/\\b(шлем|мотошлем|helmet)\\b/u', $hay);
    }

    protected function sanitizeQuery(string $query): string
    {
        $clean = preg_replace('/\\b(black|white|matte|glossy|gaming|gamer|e-sports|esports)\\b/iu', ' ', $query);
        $clean = preg_replace('/\\b(черн(ый|ая|ое)|бел(ый|ая|ое)|матов(ый|ая|ое)|глянцев(ый|ая|ое)|геймер(ский|ская|ские)|игров(ой|ая|ые))\\b/iu', ' ', $clean);
        $clean = preg_replace('/\\b(для\\s+геймеров|для\\s+игр)\\b/iu', ' ', $clean);
        $clean = preg_replace('/\\s+/u', ' ', $clean);
        return trim($clean);
    }

    protected function extractModelToken(string ...$queries): string
    {
        foreach ($queries as $query) {
            if (!is_string($query) || $query === '') {
                continue;
            }
            if (preg_match('/\\b[A-Za-z0-9\\-]{2,}\\d{1,}[A-Za-z0-9\\-]*\\b/u', $query, $m)) {
                $token = $m[0];
                if ($this->isYearToken($token) || $this->isSizeToken($token)) {
                    continue;
                }
                return $token;
            }
        }

        return '';
    }

    protected function extractProductType(array $analysis, string $rawQuery, string $query): string
    {
        $category = mb_strtolower(trim((string) ($analysis['product_type'] ?? '')));
        $hay = mb_strtolower($rawQuery.' '.$query.' '.$category);
        if (preg_match('/\\b(гарнитура|headset)\\b/u', $hay)) {
            return 'гарнитура';
        }
        if (preg_match('/\\b(наушник|headphones)\\b/u', $hay)) {
            return 'наушники';
        }
        if (preg_match('/\\b(шлем|мотошлем|helmet)\\b/u', $hay)) {
            return 'шлем';
        }

        return '';
    }

    protected function isYearToken(string $token): bool
    {
        return (bool) preg_match('/^(19|20)\\d{2}$/', $token);
    }

    protected function isSizeToken(string $token): bool
    {
        return (bool) preg_match('/^\\d+(\\.\\d+)?$/', $token);
    }

    protected function extractBrand(array $analysis, string $rawQuery, string $query): string
    {
        $brand = trim((string) ($analysis['brand'] ?? ''));
        if ($brand !== '') {
            return $brand;
        }

        $candidate = $this->extractBrandToken($rawQuery);
        if ($candidate !== '') {
            return $candidate;
        }

        return $this->extractBrandToken($query);
    }

    protected function extractBrandToken(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $stop = [
            'motorcycle', 'helmet', 'full', 'face', 'matte', 'black', 'white', 'size',
            'шлем', 'мотошлем', 'интеграл', 'полноразмерный', 'полно-лицевой', 'полно лицевой',
            'матовый', 'черный', 'чёрный', 'белый',
        ];

        $tokens = preg_split('/\\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $token) {
            $clean = trim($token, " \t\n\r\0\x0B.,;:()[]{}\"'");
            if ($clean === '' || mb_strlen($clean) < 3) {
                continue;
            }
            $lower = mb_strtolower($clean);
            if (in_array($lower, $stop, true)) {
                continue;
            }
            if (preg_match('/[A-Za-z]/', $clean)) {
                return $clean;
            }
        }

        return '';
    }

    protected function extractSizeToken(string $query): string
    {
        if ($query === '') {
            return '';
        }

        if (preg_match('/\\b(XXXL|XXL|XL|XS|XXS|S|M|L)\\b/iu', $query, $m)) {
            return mb_strtoupper($m[1]);
        }

        return '';
    }

    protected function collectCandidates(array $queries, ?callable $progress = null): array
    {
        $candidates = [];
        $slice = array_slice($queries, 0, 8);
        $total = count($slice);
        $index = 0;
        $deadline = microtime(true) + 300;
        foreach ($slice as $q) {
            if (microtime(true) >= $deadline) {
                logger()->warning('WB candidates collection timed out', [
                    'queries_total' => $total,
                    'processed' => $index,
                ]);
                break;
            }
            $index++;
            if ($progress) {
                $progress($index, $total, $q);
            }
            $results = $this->wbSearch->search($q);
            foreach ($results as $item) {
                $key = ($item['url'] ?? '').'|'.($item['title'] ?? '');
                $candidates[$key] = $item;
            }
        }

        $list = array_values($candidates);

        logger()->info('WB candidates collected', [
            'count' => count($list),
            'top' => array_slice($list, 0, 5),
        ]);

        return $list;
    }

    protected function rankCandidates(array $analysis, array $candidates, int $limit): array
    {
        $payload = json_encode([
            'analysis' => $analysis,
            'candidates' => array_values($candidates),
            'limit' => $limit,
        ], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Return JSON only.
Select the top {$limit} most relevant candidates for the product based on analysis.
Respond strictly in JSON:
{"top_indices":[0,1,2]}
PROMPT;

        $response = OpenAI::responses()->create($this->buildResponsePayload([
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
        ]));

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
            $candidate = $candidates[(int) $idx] ?? null;
            if (is_array($candidate)) {
                $items[] = $candidate;
            }
        }

        return $items;
    }

    protected function selectBestCandidate(string $imagePath, array $analysis, array $queries, array $candidates): array
    {
        $dataUrl = $this->imageToDataUrl($imagePath);
        $prompt = <<<PROMPT
Сравни исходное фото товара с кандидатами Wildberries и выбери самый похожий.
Ответь строго JSON:
{
  "best_index": 0,
  "reason": ""
}
PROMPT;

        $content = [
            ['type' => 'input_text', 'text' => $prompt],
            ['type' => 'input_image', 'image_url' => $dataUrl, 'detail' => 'high'],
            ['type' => 'input_text', 'text' => 'Атрибуты: '.json_encode($analysis, JSON_UNESCAPED_UNICODE)],
            ['type' => 'input_text', 'text' => 'Запросы: '.json_encode($queries, JSON_UNESCAPED_UNICODE)],
        ];

        foreach (array_slice($candidates, 0, 8) as $idx => $candidate) {
            $content[] = ['type' => 'input_text', 'text' => "Кандидат {$idx}: ".json_encode($candidate, JSON_UNESCAPED_UNICODE)];
            if (!empty($candidate['image']) && is_string($candidate['image'])) {
                $content[] = ['type' => 'input_image', 'image_url' => $candidate['image'], 'detail' => 'low'];
            }
        }

        $response = OpenAI::responses()->create($this->buildResponsePayload([
            'model' => config('bot.vision_model', 'gpt-5-mini'),
            'text' => [
                'format' => ['type' => 'json_object'],
            ],
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'reasoning' => ['effort' => 'minimal'],
            'max_output_tokens' => 400,
        ]));

        $raw = trim($this->extractOutputText($response));
        if ($raw === '') {
            return [];
        }

        $parsed = $this->decodeJson($raw);
        $index = is_array($parsed) ? ($parsed['best_index'] ?? null) : null;

        if (!is_numeric($index) || !isset($candidates[(int) $index])) {
            return [];
        }

        return $candidates[(int) $index];
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

        if (empty($chunks)) {
            logger()->warning('WB minimal analysis missing output_text', [
                'output' => is_object($response) && method_exists($response, 'toArray')
                    ? $response->toArray()
                    : null,
            ]);
        }

        return trim(implode("\n", $chunks));
    }

    protected function collectOutputTypes(object $response): array
    {
        $types = [];
        foreach ($response->output ?? [] as $output) {
            $types[] = is_object($output) ? ($output->type ?? get_class($output)) : ($output['type'] ?? gettype($output));
        }
        return $types;
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

    protected function buildResponsePayload(array $payload): array
    {
        $model = strtolower(trim((string) ($payload['model'] ?? '')));
        if (array_key_exists('temperature', $payload) && $model !== '') {
            unset($payload['temperature']);
        }

        return $payload;
    }
}
