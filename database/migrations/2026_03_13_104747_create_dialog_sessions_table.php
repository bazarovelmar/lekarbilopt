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
        Schema::create('dialog_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('telegram_user_id')->nullable()->index();
            $table->string('state', 50)->index();
            $table->string('photo_file_id')->nullable();
            $table->string('price_raw')->nullable();
            $table->decimal('price_value', 12, 2)->nullable();
            $table->unsignedBigInteger('last_service_message_id')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();

            $table->unique('chat_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dialog_sessions');
    }
};
