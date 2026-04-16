<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_playwright_node_logs', function (Blueprint $table) {
            $table->id();
            $table->string('node', 255);
            $table->string('query', 512)->nullable();
            $table->string('status', 32);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['node', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_playwright_node_logs');
    }
};
