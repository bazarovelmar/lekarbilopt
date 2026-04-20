<?php

use App\Http\Controllers\AidentikaWebhookController;
use App\Http\Controllers\CatalogAuthController;
use App\Http\Controllers\ProductCatalogController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/catalog');
});

Route::get('/login', [CatalogAuthController::class, 'showLogin'])->name('catalog.login');
Route::post('/login', [CatalogAuthController::class, 'login'])->name('catalog.login.submit');
Route::post('/logout', [CatalogAuthController::class, 'logout'])->name('catalog.logout');

Route::middleware('catalog.auth')->group(function () {
    Route::get('/catalog', [ProductCatalogController::class, 'index'])->name('catalog.index');
    Route::get('/catalog/image/{id}', [ProductCatalogController::class, 'image'])->name('catalog.image');
    Route::get('/catalog/{id}', [ProductCatalogController::class, 'show'])
        ->whereNumber('id')
        ->name('catalog.show');
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/aidentika/webhook', [AidentikaWebhookController::class, 'handle']);
Route::post('/aidentika-webhook', [AidentikaWebhookController::class, 'handle']);
