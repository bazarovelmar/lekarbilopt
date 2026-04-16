<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wb_id')->unique();
            $table->string('title', 512)->nullable();
            $table->string('brand', 255)->nullable();
            $table->string('supplier', 255)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->unsignedBigInteger('subject_parent_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->unsignedBigInteger('subcategory_id')->nullable()->index();
            $table->string('image_path')->nullable();
            $table->jsonb('data')->nullable();
            $table->jsonb('characteristics')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_products');
    }
};
