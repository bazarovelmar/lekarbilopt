<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wb_subject_id')->unique();
            $table->unsignedBigInteger('parent_wb_subject_id')->nullable()->index();
            $table->string('name', 255)->nullable();
            $table->string('entity', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_categories');
    }
};
