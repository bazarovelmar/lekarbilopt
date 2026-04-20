<?php

namespace App\Jobs;

use App\Models\GeneratedPost;
use App\Services\AidentikaService;
use App\Services\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAidentikaGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $generatedPostId,
        public int $chatId
    ) {}

    public function handle(AidentikaService $aidentika, TelegramBotService $telegram): void
    {
        $post = GeneratedPost::find($this->generatedPostId);
        if (!$post) {
            return;
        }
        if (in_array($post->status, ['published', 'failed'], true)) {
            return;
        }

        $actionId = $post->data['aidentika_action_id'] ?? null;
        if (!is_numeric($actionId)) {
            return;
        }

        $status = $aidentika->getStatus((int) $actionId);
        $state = $status['status'] ?? null;

        $post->data = array_merge($post->data ?? [], [
            'aidentika_status' => $state,
            'aidentika_result_url' => $status['result_url'] ?? null,
        ]);
        $post->save();

        if ($state === 'completed') {
            if (!empty($post->channel_message_id)) {
                $post->status = 'published';
                $post->save();
                return;
            }
            $path = storage_path('app/aidentika/'.date('Ymd_His').'_'.uniqid().'.png');
            $aidentika->downloadResult((int) $actionId, $path);

            $buttons = $this->buildOrderButtons($post, $telegram);

            $messageId = $telegram->sendPhotoToChannel($path, $post->description ?? '', $buttons);
            $post->channel_message_id = $messageId;
            $post->status = 'published';
            $post->save();

            $telegram->sendMessage($this->chatId, 'Инфографика готова и опубликована в канал.');
            return;
        }

        if ($state === 'failed') {
            $post->status = 'failed';
            $post->save();
            $telegram->sendMessage($this->chatId, 'Генерация инфографики не удалась.');
            return;
        }

        // pending -> re-dispatch with delay
        self::dispatch($this->generatedPostId, $this->chatId)->delay(now()->addSeconds(20));
    }

    /**
     * Собирает inline-кнопку "Заказать" со ссылкой в ЛС автора поста
     * с префилл-сообщением "Добрый день, хочу заказать товар {wb_url}".
     */
    protected function buildOrderButtons(GeneratedPost $post, TelegramBotService $telegram): array
    {
        $data = is_array($post->data) ? $post->data : [];
        $wbItem = is_array($data['wb_item'] ?? null) ? $data['wb_item'] : [];

        // URL товара на WB
        $wbUrl = null;
        if (!empty($wbItem['url']) && is_string($wbItem['url'])) {
            $wbUrl = $wbItem['url'];
        } elseif (!empty($wbItem['id'])) {
            $wbUrl = 'https://www.wildberries.ru/catalog/'.((int) $wbItem['id']).'/detail.aspx';
        }

        // Username автора — сначала из data, затем через getChat как fallback
        $username = null;
        if (!empty($data['author_username']) && is_string($data['author_username'])) {
            $username = ltrim($data['author_username'], '@');
        }

        if (!$username && !empty($post->telegram_user_id)) {
            $username = $telegram->getChatUsername((int) $post->telegram_user_id);
        }

        // Если username автора не удалось получить — используем дефолтный контакт
        if (!$username) {
            $username = 'elyargula';
        }

        $prefill = $wbUrl
            ? "Добрый день, хочу заказать товар {$wbUrl}"
            : 'Добрый день, хочу заказать товар';

        $orderUrl = 'https://t.me/'.$username.'?text='.rawurlencode($prefill);

        return [[[
            'text' => 'Заказать',
            'url' => $orderUrl,
        ]]];
    }
}
