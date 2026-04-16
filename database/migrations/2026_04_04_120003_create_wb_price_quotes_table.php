<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_price_quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('telegram_user_id')->nullable()->index();
            $table->unsignedBigInteger('wb_id')->nullable()->index();
            $table->string('price_raw')->nullable();
            $table->decimal('price_value', 12, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_price_quotes');
    }
};
