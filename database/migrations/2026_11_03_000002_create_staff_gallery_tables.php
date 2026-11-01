<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_gallery_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('staff_gallery_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('type', 20);
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });

        Schema::create('staff_gallery_category_staff_gallery_image', function (Blueprint $table): void {
            $table->foreignId('staff_gallery_category_id');
            $table->foreignId('staff_gallery_image_id');
            $table->primary(['staff_gallery_category_id', 'staff_gallery_image_id'], 'sgc_sgi_primary');
            $table->foreign('staff_gallery_category_id', 'sgc_sgi_category_fk')
                ->references('id')
                ->on('staff_gallery_categories')
                ->cascadeOnDelete();
            $table->foreign('staff_gallery_image_id', 'sgc_sgi_image_fk')
                ->references('id')
                ->on('staff_gallery_images')
                ->cascadeOnDelete();
        });

        Schema::create('staff_gallery_category_user', function (Blueprint $table): void {
            $table->foreignId('staff_gallery_category_id');
            $table->foreignId('user_id');
            $table->primary(['staff_gallery_category_id', 'user_id'], 'sgcu_primary');
            $table->foreign('staff_gallery_category_id', 'sgcu_category_fk')
                ->references('id')
                ->on('staff_gallery_categories')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'sgcu_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_gallery_category_user');
        Schema::dropIfExists('staff_gallery_category_staff_gallery_image');
        Schema::dropIfExists('staff_gallery_images');
        Schema::dropIfExists('staff_gallery_categories');
    }
};
