<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramBotService
{
    public function sendMessage(int|string $chatId, string $text): int
    {
        $message = $this->withRetry(function () use ($chatId, $text) {
            return Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        });

        return (int) $message->messageId;
    }

    public function sendMessageWithInlineKeyboard(int|string $chatId, string $text, array $buttons): int
    {
        $message = $this->withRetry(function () use ($chatId, $text, $buttons) {
            return Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode([
                    'inline_keyboard' => $buttons,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        });

        return (int) $message->messageId;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if (is_string($text) && $text !== '') {
            $payload['text'] = $text;
        }

        try {
            $this->withRetry(function () use ($payload) {
                Telegram::answerCallbackQuery($payload);
            });
        } catch (\Throwable) {
            // Best-effort; ignore failures.
        }
    }

    public function deleteMessage(int|string $chatId, ?int $messageId): void
    {
        if (! $messageId) {
            return;
        }

        try {
            $this->withRetry(function () use ($chatId, $messageId) {
                Telegram::deleteMessage([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
            });
        } catch (\Throwable) {
            // Best-effort cleanup; ignore failures.
        }
    }

    public function editMessage(int|string $chatId, ?int $messageId, string $text): void
    {
        if (! $messageId) {
            return;
        }

        try {
            $this->withRetry(function () use ($chatId, $messageId, $text) {
                Telegram::editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                ]);
            });
        } catch (\Throwable) {
            // Best-effort updates; ignore failures.
        }
    }

    public function downloadPhoto(string $fileId): string
    {
        $file = $this->withRetry(function () use ($fileId) {
            return Telegram::getFile(['file_id' => $fileId]);
        });
        $filePath = $file->filePath ?? null;

        if (! is_string($filePath) || $filePath === '') {
            throw new RuntimeException('Telegram file path not found.');
        }

        $token = $this->botToken();
        $url = "https://api.telegram.org/file/bot{$token}/{$filePath}";
        $response = Http::timeout(20)->retry(3, 700)->get($url);

        if (! $response->ok()) {
            throw new RuntimeException('Failed to download image from Telegram.');
        }

        $filename = 'telegram/'.now()->format('Ymd_His').'_'.uniqid().'.jpg';
        Storage::disk('local')->put($filename, $response->body());

        return Storage::disk('local')->path($filename);
    }

    public function sendPhotoToChannel(string $imagePath, string $caption, array $inlineButtons = []): int
    {
        $channelId = config('services.telegram.channel_id');
        if (! $channelId) {
            throw new RuntimeException('Telegram channel id is missing.');
        }

        $payload = [
            'chat_id' => $channelId,
            'photo' => InputFile::create($imagePath),
            'caption' => $caption,
        ];

        if (!empty($inlineButtons)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => $inlineButtons,
            ], JSON_UNESCAPED_UNICODE);
        }

        $message = Telegram::sendPhoto($payload);

        return (int) $message->messageId;
    }

    public function getChatUsername(int|string $chatId): ?string
    {
        try {
            $chat = $this->withRetry(function () use ($chatId) {
                return Telegram::getChat(['chat_id' => $chatId]);
            });

            $username = $chat->username ?? null;
            return is_string($username) && $username !== '' ? $username : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function botToken(): string
    {
        $default = config('telegram.default');
        $token = config("telegram.bots.{$default}.token");

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram bot token is missing.');
        }

        return $token;
    }

    protected function withRetry(callable $callback, int $attempts = 3, int $delayMs = 700)
    {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $last = $e;
                usleep($delayMs * 1000);
            }
        }

        throw $last ?? new RuntimeException('Telegram request failed.');
    }
}
