<?php

namespace App\Http\Controllers;

use App\Models\DialogSession;
use App\Models\GeneratedPost;
use App\Models\WbCategory;
use App\Models\WbPriceQuote;
use App\Models\WbProduct;
use App\Jobs\ProcessAidentikaGeneration;
use App\Services\AidentikaService;
use App\Services\InfographicService;
use App\Services\TelegramBotService;
use App\Services\WbCardService;
use App\Services\WbImageSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\Message;

class TelegramWebhookController extends Controller
{
    public function handle(
        Request $request,
        TelegramBotService $telegram,
        WbImageSearchService $wbSearch,
        InfographicService $infographic,
        AidentikaService $aidentika,
        WbCardService $wbCards
    ): JsonResponse {
        if (! config('bot.wb.enabled')) {
            return response()->json(['ok' => true]);
        }

        $update = Telegram::getWebhookUpdate();
        $updateId = is_numeric($update->updateId ?? null) ? (int) $update->updateId : null;
        $callback = $update->callbackQuery ?? null;
        $message = $update->message
            ?? $update->editedMessage
            ?? $update->channelPost
            ?? $update->editedChannelPost;

        if ($callback && !$message instanceof Message) {
            $message = $callback->message ?? null;
        }

        if (! $message instanceof Message) {
            return response()->json(['ok' => true]);
        }

        $chatId = (int) $message->chat->id;
        $telegramUserId = $message->from?->id ? (int) $message->from->id : null;
        $text = is_string($message->text) ? trim($message->text) : null;

        $session = DialogSession::firstOrNew(['chat_id' => $chatId]);
        $session->telegram_user_id = $telegramUserId;
        if (!is_string($session->state) || $session->state === '') {
            $session->state = 'await_photo';
        }
        $session->save();

        $lastUpdateId = $this->getLastUpdateId($session);
        if (is_int($updateId) && is_int($lastUpdateId) && $updateId <= $lastUpdateId) {
            return response()->json(['ok' => true]);
        }

        if (is_string($text) && str_starts_with($text, '/')) {
            $this->resetSession($session);
            $startTimestamp = is_numeric($message->date ?? null) ? (int) $message->date : time();
            $this->setAwaitPhotoAfter($session, $startTimestamp);
            if (is_int($updateId)) {
                $this->setAwaitUpdateId($session, $updateId);
            }
            $messageId = $this->replaceServiceMessage(
                $telegram,
                $session,
                $chatId,
                'Привет! Пришлите фото товара.'
            );

            return response()->json(['ok' => true, 'message_id' => $messageId]);
        }

        if ($callback && is_string($callback->data ?? null)) {
            $handled = $this->handleCallback(
                $callback->data,
                $callback->id ?? null,
                $session,
                $telegram,
                $chatId
            );

            if (is_int($updateId)) {
                $this->setLastUpdateId($session, $updateId);
            }

            return response()->json(['ok' => true, 'handled' => $handled]);
        }

        $awaitAfter = $this->getAwaitPhotoAfter($session);
        $awaitUpdateId = $this->getAwaitUpdateId($session);
        if (is_int($updateId) && is_int($awaitUpdateId) && $updateId <= $awaitUpdateId) {
            return response()->json(['ok' => true]);
        }
        if ($awaitAfter && is_numeric($message->date ?? null) && (int) $message->date < $awaitAfter) {
            return response()->json(['ok' => true]);
        }

        if ($this->isAwaitingCustomLink($session) && is_string($text) && $text !== '') {
            $nmId = $this->extractWbIdFromUrl($text);
            if ($nmId === null) {
                $this->replaceServiceMessage(
                    $telegram, $session, $chatId,
                    'Не удалось распознать ссылку WB. Пришлите ссылку вида https://www.wildberries.ru/catalog/123456789/detail.aspx'
                );
                if (is_int($updateId)) {
                    $this->setLastUpdateId($session, $updateId);
                }
                return response()->json(['ok' => true]);
            }

            $card = $wbCards->fetchCard($nmId);
            $rawProduct = $card['data']['products'][0] ?? null;
            if (!is_array($rawProduct)) {
                $this->replaceServiceMessage(
                    $telegram, $session, $chatId,
                    'Не получилось загрузить данные товара по этой ссылке. Попробуйте ещё раз.'
                );
                if (is_int($updateId)) {
                    $this->setLastUpdateId($session, $updateId);
                }
                return response()->json(['ok' => true]);
            }

            $selected = $this->buildCandidateFromRaw($rawProduct, $nmId);
            $this->storeSelectedProduct($selected, $session);
            $this->setSelectedWbItem($session, $selected);
            $this->storeLastQuery($session, (string) ($selected['title'] ?? ''));
            $session->state = 'await_price';
            $session->save();

            $this->replaceServiceMessage(
                $telegram, $session, $chatId,
                "Товар принят: {$selected['title']}\nВведите вашу цену (только число)."
            );

            if (is_int($updateId)) {
                $this->setLastUpdateId($session, $updateId);
            }
            return response()->json(['ok' => true]);
        }

        if ($this->isAwaitingPrice($session) && is_string($text) && $text !== '') {
            $price = $this->parsePrice($text);
            $selected = $this->getSelectedWbItem($session);
            $imagePath = $this->getImagePath($session);

            if ($price !== null && is_array($selected) && is_string($imagePath) && $imagePath !== '') {
                $session->price_raw = $text;
                $session->price_value = $price;
                $session->state = 'processing';
                $session->save();

                WbPriceQuote::create([
                    'chat_id' => $chatId,
                    'telegram_user_id' => $telegramUserId,
                    'wb_id' => $selected['id'] ?? null,
                    'price_raw' => $text,
                    'price_value' => $price,
                    'image_path' => $imagePath,
                    'data' => [
                        'wb_item' => $selected,
                    ],
                ]);

                $query = $this->getLastQueryFromSession($session) ?? ($selected['title'] ?? '');
                $this->replaceServiceMessage(
                    $telegram,
                    $session,
                    $chatId,
                    'Отправляю запрос в Aidentika для генерации инфографики...'
                );

                $aidentikaResp = $aidentika->generateCard(
                    $imagePath,
                    (string) $query,
                    (string) config('bot.aidentika.category_id', 'default'),
                    (string) config('bot.aidentika.concept_id', 'default')
                );
                $actionId = $aidentikaResp['action_id'] ?? null;
                $description = $this->buildInfographicCaption($selected, $price);

                $authorUsername = is_string($message->from?->username ?? null) && $message->from->username !== ''
                    ? (string) $message->from->username
                    : null;

                GeneratedPost::create([
                    'chat_id' => $chatId,
                    'telegram_user_id' => $telegramUserId,
                    'price_value' => $price,
                    'price_raw' => $text,
                    'description' => $description,
                    'image_path' => $imagePath,
                    'status' => 'processing',
                    'data' => [
                        'wb_item' => $selected,
                        'user_price' => $price,
                        'aidentika_action_id' => $actionId,
                        'aidentika_status' => $aidentikaResp['status'] ?? null,
                        'author_username' => $authorUsername,
                    ],
                ]);

                $session->state = 'await_photo';
                $session->save();

                $messageId = $this->replaceServiceMessage(
                    $telegram,
                    $session,
                    $chatId,
                    "Генерация запущена. Ждём результат...\nСсылка: {$selected['url']}\nЦена WB: ".($selected['price'] ?? '-')." ₽\nВаша цена: {$price} ₽"
                );

                if (is_numeric($actionId) && !config('bot.aidentika.webhook_enabled')) {
                    $postId = GeneratedPost::query()->latest('id')->value('id');
                    ProcessAidentikaGeneration::dispatch((int) $postId, $chatId)->delay(now()->addSeconds(20));
                }

                return response()->json(['ok' => true, 'message_id' => $messageId]);
            }
        }

        $fileId = $this->extractFileId($message);
        if (!is_string($fileId) || $fileId === '') {
            $messageId = $this->replaceServiceMessage(
                $telegram,
                $session,
                $chatId,
                'Пришлите фото товара.'
            );

            if (is_int($updateId)) {
                $this->setLastUpdateId($session, $updateId);
            }
            return response()->json(['ok' => true, 'message_id' => $messageId]);
        }

        $session->photo_file_id = $fileId;
        $this->clearAwaitUpdateId($session);
        if (is_int($updateId)) {
            $this->setLastUpdateId($session, $updateId);
        }
        $session->save();

        try {
            $photoPath = $telegram->downloadPhoto($fileId);
            $messageId = $this->replaceServiceMessage(
                $telegram,
                $session,
                $chatId,
                'Ищу товар на WB. Это может занять до 5 минут. Запрос 0/0...'
            );

            $items = $wbSearch->findLinks(
                $photoPath,
                5,
                function (int $current, int $total, string $query) use ($telegram, $chatId, $messageId) {
                    $telegram->editMessage(
                        $chatId,
                        $messageId,
                        "Ищу товар на WB. Это может занять до 5 минут. Запрос {$current}/{$total}..."
                    );
                }
            );

            if (!empty($items)) {
                $items = array_slice($items, 0, 5);
                $this->storeCandidates($session, $items, $photoPath);
                $this->storeLastQuery($session, $wbSearch->getLastQuery());

                $buttons = $this->buildInlineButtons($items);
                $lines = [];
                foreach ($items as $idx => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $url = $item['url'] ?? null;
                    if (!is_string($url) || $url === '') {
                        continue;
                    }
                    $price = $item['price'] ?? null;
                    $line = ($idx + 1).') '.$url;
                    if (is_string($price) && $price !== '') {
                        $line .= ' — '.$price.' ₽';
                    }
                    $lines[] = $line;
                }
                $messageId = $this->replaceServiceMessage(
                    $telegram,
                    $session,
                    $chatId,
                    "Выберите подходящий товар:\n".implode("\n", $lines)
                );
                $telegram->sendMessageWithInlineKeyboard($chatId, 'Нажмите номер:', $buttons);

                return response()->json(['ok' => true, 'message_id' => $messageId]);
            }

            $messageId = $this->replaceServiceMessage(
                $telegram,
                $session,
                $chatId,
                'Ничего похожего не найдено'
            );

            return response()->json(['ok' => true, 'message_id' => $messageId]);
        } catch (\Throwable $e) {
            logger()->error('WB minimal search failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $messageId = $this->replaceServiceMessage(
                $telegram,
                $session,
                $chatId,
                'Не удалось выполнить поиск. Попробуйте ещё раз.'
            );

            return response()->json(['ok' => true, 'message_id' => $messageId]);
        }
    }

    protected function extractFileId($message): ?string
    {
        $photos = $message->photo ?? null;

        if ($photos instanceof \Illuminate\Support\Collection) {
            $photos = $photos->all();
        }

        $fileId = null;
        if (is_array($photos) && count($photos) > 0) {
            $largest = $photos[array_key_last($photos)];
            $fileId = $largest?->fileId ?? null;
        }

        if (! $fileId && isset($message->document)) {
            $fileId = $message->document?->fileId ?? null;
        }

        return is_string($fileId) ? $fileId : null;
    }

    protected function replaceServiceMessage(
        TelegramBotService $telegram,
        DialogSession $session,
        int $chatId,
        string $text
    ): int {
        $messageId = $telegram->sendMessage($chatId, $text);
        $session->last_service_message_id = $messageId;
        $session->save();

        return $messageId;
    }

    protected function resetSession(DialogSession $session): void
    {
        $session->photo_file_id = null;
        $session->price_raw = null;
        $session->price_value = null;
        $session->data = null;
        $session->state = 'await_photo';
        $session->save();
    }

    protected function storeLastQuery(DialogSession $session, ?string $query): void
    {
        if (!is_string($query) || $query === '') {
            return;
        }
        $data = is_array($session->data) ? $session->data : [];
        $data['last_query'] = $query;
        $session->data = $data;
        $session->save();
    }

    protected function getLastQueryFromSession(DialogSession $session): ?string
    {
        $data = is_array($session->data) ? $session->data : [];
        $query = $data['last_query'] ?? null;
        return is_string($query) ? $query : null;
    }

    protected function setAwaitPhotoAfter(DialogSession $session, int $timestamp): void
    {
        $data = is_array($session->data) ? $session->data : [];
        $data['await_photo_after'] = $timestamp;
        $session->data = $data;
        $session->save();
    }

    protected function getAwaitPhotoAfter(DialogSession $session): ?int
    {
        $data = is_array($session->data) ? $session->data : [];
        $value = $data['await_photo_after'] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    protected function setAwaitUpdateId(DialogSession $session, int $updateId): void
    {
        $data = is_array($session->data) ? $session->data : [];
        $data['await_update_id'] = $updateId;
        $session->data = $data;
        $session->save();
    }

    protected function getAwaitUpdateId(DialogSession $session): ?int
    {
        $data = is_array($session->data) ? $session->data : [];
        $value = $data['await_update_id'] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    protected function clearAwaitUpdateId(DialogSession $session): void
    {
        $data = is_array($session->data) ? $session->data : [];
        unset($data['await_update_id']);
        $session->data = $data;
        $session->save();
    }

    protected function handleCallback(
        string $data,
        ?string $callbackId,
        DialogSession $session,
        TelegramBotService $telegram,
        int $chatId
    ): bool {
        if ($data === 'wb_custom') {
            $session->state = 'await_custom_link';
            $session->save();
            $telegram->answerCallbackQuery($callbackId ?? '');
            $telegram->sendMessage(
                $chatId,
                'Отправьте ссылку на товар WB (например: https://www.wildberries.ru/catalog/123456789/detail.aspx)'
            );
            return true;
        }

        if (!str_starts_with($data, 'wb_select:')) {
            return false;
        }

        $idx = (int) substr($data, strlen('wb_select:'));
        $candidates = $this->getCandidates($session);
        $selected = $candidates[$idx] ?? null;
        if (!is_array($selected)) {
            $telegram->answerCallbackQuery($callbackId ?? '', 'Не найдено');
            return true;
        }

        $this->storeSelectedProduct($selected, $session);
        $this->setSelectedWbItem($session, $selected);
        $session->state = 'await_price';
        $session->save();

        $telegram->answerCallbackQuery($callbackId ?? '', 'Выбрано');
        $telegram->sendMessage($chatId, 'Введите вашу цену (только число).');

        return true;
    }

    protected function buildInlineButtons(array $items): array
    {
        $buttons = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = (string) ($idx + 1);
            $buttons[] = [[
                'text' => $text,
                'callback_data' => 'wb_select:'.$idx,
            ]];
        }

        $buttons[] = [[
            'text' => 'Ни один не подходит — предложить свою ссылку',
            'callback_data' => 'wb_custom',
        ]];

        return $buttons;
    }

    protected function storeCandidates(DialogSession $session, array $items, string $imagePath): void
    {
        $data = is_array($session->data) ? $session->data : [];
        $data['wb_candidates'] = $items;
        $data['image_path'] = $imagePath;
        $session->data = $data;
        $session->state = 'await_choice';
        $session->save();
    }

    protected function getCandidates(DialogSession $session): array
    {
        $data = is_array($session->data) ? $session->data : [];
        $items = $data['wb_candidates'] ?? [];
        return is_array($items) ? array_values($items) : [];
    }

    protected function setSelectedWbItem(DialogSession $session, array $item): void
    {
        $data = is_array($session->data) ? $session->data : [];
        $data['selected_wb_item'] = $item;
        $session->data = $data;
        $session->save();
    }

    protected function getSelectedWbItem(DialogSession $session): ?array
    {
        $data = is_array($session->data) ? $session->data : [];
        $item = $data['selected_wb_item'] ?? null;
        return is_array($item) ? $item : null;
    }

    protected function storeSelectedProduct(array $item, DialogSession $session): void
    {
        $raw = is_array($item['raw'] ?? null) ? $item['raw'] : [];
        $subjectId = isset($raw['subjectId']) ? (int) $raw['subjectId'] : null;
        $subjectParentId = isset($raw['subjectParentId']) ? (int) $raw['subjectParentId'] : null;
        $entity = isset($raw['entity']) ? (string) $raw['entity'] : null;
        $aiCategory = is_array($item['ai_category'] ?? null) ? $item['ai_category'] : null;
        $aiSubcategory = is_array($item['ai_subcategory'] ?? null) ? $item['ai_subcategory'] : null;

        $parentCategory = null;
        if (is_array($aiCategory) && !empty($aiCategory['id'])) {
            $parentCategory = WbCategory::updateOrCreate(
                ['wb_subject_id' => (int) $aiCategory['id']],
                [
                    'parent_wb_subject_id' => null,
                    'name' => isset($aiCategory['name']) ? (string) $aiCategory['name'] : null,
                    'entity' => isset($aiCategory['slug']) ? (string) $aiCategory['slug'] : null,
                ]
            );
        } elseif ($subjectParentId) {
            $parentCategory = WbCategory::updateOrCreate(
                ['wb_subject_id' => $subjectParentId],
                [
                    'parent_wb_subject_id' => null,
                    'name' => null,
                    'entity' => null,
                ]
            );
        }

        $childCategory = null;
        if (is_array($aiSubcategory) && !empty($aiSubcategory['id']) && $parentCategory) {
            $childCategory = WbCategory::updateOrCreate(
                ['wb_subject_id' => (int) $aiSubcategory['id']],
                [
                    'parent_wb_subject_id' => (int) ($aiCategory['id'] ?? 0),
                    'name' => isset($aiSubcategory['name']) ? (string) $aiSubcategory['name'] : null,
                    'entity' => isset($aiSubcategory['slug']) ? (string) $aiSubcategory['slug'] : null,
                ]
            );
        } elseif ($subjectId) {
            $childCategory = WbCategory::updateOrCreate(
                ['wb_subject_id' => $subjectId],
                [
                    'parent_wb_subject_id' => $subjectParentId,
                    'name' => $entity,
                    'entity' => $entity,
                ]
            );
        }

        $imagePath = $this->getImagePath($session);
        $wbId = isset($item['id']) ? (int) $item['id'] : null;
        if ($wbId) {
            WbProduct::updateOrCreate(
                ['wb_id' => $wbId],
                [
                    'title' => $item['title'] ?? null,
                    'brand' => $item['brand'] ?? null,
                    'supplier' => $raw['supplier'] ?? null,
                    'supplier_id' => $raw['supplierId'] ?? null,
                    'subject_id' => $subjectId,
                    'subject_parent_id' => $subjectParentId,
                    'category_id' => $parentCategory?->id,
                    'subcategory_id' => $childCategory?->id,
                    'image_path' => $imagePath,
                    'data' => $raw,
                    'characteristics' => $this->buildMainCharacteristics($raw),
                ]
            );
        }
    }

    protected function buildMainCharacteristics(array $raw): array
    {
        $colors = [];
        if (isset($raw['colors']) && is_array($raw['colors'])) {
            foreach ($raw['colors'] as $color) {
                if (is_array($color) && !empty($color['name'])) {
                    $colors[] = $color['name'];
                }
            }
        }

        $sizes = [];
        if (isset($raw['sizes']) && is_array($raw['sizes'])) {
            foreach ($raw['sizes'] as $size) {
                if (is_array($size) && !empty($size['origName'])) {
                    $sizes[] = $size['origName'];
                }
            }
        }

        return array_values(array_filter([
            ['key' => 'wb_id', 'value' => $raw['id'] ?? null],
            ['key' => 'name', 'value' => $raw['name'] ?? null],
            ['key' => 'brand', 'value' => $raw['brand'] ?? null],
            ['key' => 'entity', 'value' => $raw['entity'] ?? null],
            ['key' => 'subject_id', 'value' => $raw['subjectId'] ?? null],
            ['key' => 'subject_parent_id', 'value' => $raw['subjectParentId'] ?? null],
            ['key' => 'supplier', 'value' => $raw['supplier'] ?? null],
            ['key' => 'supplier_id', 'value' => $raw['supplierId'] ?? null],
            ['key' => 'colors', 'value' => $colors],
            ['key' => 'sizes', 'value' => $sizes],
        ], fn ($item) => $item['value'] !== null && $item['value'] !== []));
    }

    protected function getImagePath(DialogSession $session): ?string
    {
        $data = is_array($session->data) ? $session->data : [];
        $path = $data['image_path'] ?? null;
        return is_string($path) ? $path : null;
    }

    protected function isAwaitingPrice(DialogSession $session): bool
    {
        return is_string($session->state) && $session->state === 'await_price';
    }

    protected function isAwaitingCustomLink(DialogSession $session): bool
    {
        return is_string($session->state) && $session->state === 'await_custom_link';
    }

    protected function extractWbIdFromUrl(string $input): ?int
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            $id = (int) $input;
            return $id > 0 ? $id : null;
        }

        if (preg_match('#/catalog/(\d+)#u', $input, $m)) {
            return (int) $m[1];
        }

        if (preg_match('#(?:^|[?&])(?:nm|nmId)=(\d+)#u', $input, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function buildCandidateFromRaw(array $raw, int $nmId): array
    {
        $title = (string) ($raw['name'] ?? $raw['title'] ?? 'Товар '.$nmId);

        $priceU = $raw['salePriceU'] ?? $raw['priceU'] ?? ($raw['sizes'][0]['price']['product'] ?? null);
        $price = null;
        if (is_numeric($priceU)) {
            $price = number_format(((float) $priceU) / 100, 0, ',', ' ');
        }

        return [
            'id' => $nmId,
            'title' => $title,
            'price' => $price,
            'url' => 'https://www.wildberries.ru/catalog/'.$nmId.'/detail.aspx',
            'brand' => $raw['brand'] ?? null,
            'subject' => $raw['subjectId'] ?? null,
            'raw' => $raw,
        ];
    }

    protected function parsePrice(string $raw): ?float
    {
        $clean = preg_replace('/[^0-9.,]/', '', $raw);
        $clean = str_replace(',', '.', $clean);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    protected function buildInfographicCaption(array $selected, float $userPrice): string
    {
        $lines = [];
        $title = trim((string) ($selected['title'] ?? ''));
        if ($title !== '') {
            $lines[] = $title;
        }

        $wbPrice = $selected['price'] ?? null;
        $wbPriceValue = null;
        if (is_string($wbPrice) && $wbPrice !== '') {
            $lines[] = 'WB цена: '.$wbPrice.' ₽';
            $wbPriceValue = $this->parsePrice($wbPrice);
        }
        $lines[] = 'Ваша цена: '.$userPrice.' ₽';
        if (is_numeric($wbPriceValue) && $wbPriceValue > 0 && $userPrice < $wbPriceValue) {
            $discount = $wbPriceValue - $userPrice;
            $percent = round(($discount / $wbPriceValue) * 100);
            $lines[] = 'Скидка: '.round($discount, 0).' ₽ ('.$percent.'%)';
        }
        if (!empty($selected['url'])) {
            $lines[] = (string) $selected['url'];
        }

        return trim(implode("\n", $lines));
    }

    protected function setLastUpdateId(DialogSession $session, int $updateId): void
    {
        $data = is_array($session->data) ? $session->data : [];
        $data['last_update_id'] = $updateId;
        $session->data = $data;
        $session->save();
    }

    protected function getLastUpdateId(DialogSession $session): ?int
    {
        $data = is_array($session->data) ? $session->data : [];
        $value = $data['last_update_id'] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }
}
