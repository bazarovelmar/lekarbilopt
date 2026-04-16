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

            $messageId = $telegram->sendPhotoToChannel($path, $post->description ?? '');
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
}
