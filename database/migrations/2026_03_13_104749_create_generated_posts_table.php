<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generated_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('telegram_user_id')->nullable()->index();
            $table->decimal('price_value', 12, 2)->nullable();
            $table->string('price_raw')->nullable();
            $table->string('description', 512)->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('channel_message_id')->nullable();
            $table->string('status', 30)->default('created')->index();
            $table->jsonb('data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_posts');
    }
};
