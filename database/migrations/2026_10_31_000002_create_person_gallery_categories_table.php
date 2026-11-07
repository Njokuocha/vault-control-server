<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_gallery_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['person_id', 'sort_order']);
        });

        Schema::table('person_images', function (Blueprint $table): void {
            $table->foreignId('gallery_category_id')
                ->nullable()
                ->index('person_images_gallery_category_index')
                ->constrained('person_gallery_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('person_images', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gallery_category_id');
        });

        Schema::dropIfExists('person_gallery_categories');
    }
};
