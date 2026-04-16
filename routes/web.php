<?php

use App\Http\Controllers\AidentikaWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/aidentika/webhook', [AidentikaWebhookController::class, 'handle']);
