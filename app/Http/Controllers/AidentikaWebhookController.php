<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAidentikaGeneration;
use App\Models\GeneratedPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AidentikaWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('bot.aidentika.webhook_secret', '');
        $signatureHeader = (string) $request->header('X-Aidentika-Signature', '');
        $payload = (string) $request->getContent();

        if ($secret !== '') {
            $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
            if (!hash_equals($expected, $signatureHeader)) {
                logger()->warning('Aidentika webhook invalid signature', [
                    'expected' => $expected,
                    'received' => $signatureHeader,
                ]);
                return response()->json(['ok' => false], 401);
            }
        }

        $data = $request->json()->all();
        $actionId = $data['action_id'] ?? null;
        if (!is_numeric($actionId)) {
            return response()->json(['ok' => true]);
        }

        $post = GeneratedPost::query()
            ->whereRaw("data->>'aidentika_action_id' = ?", [(string) $actionId])
            ->latest('id')
            ->first();

        if (!$post) {
            logger()->info('Aidentika webhook: post not found', [
                'action_id' => $actionId,
            ]);
            return response()->json(['ok' => true]);
        }

        ProcessAidentikaGeneration::dispatch($post->id, (int) $post->chat_id);

        return response()->json(['ok' => true]);
    }
}
