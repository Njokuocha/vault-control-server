<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_gallery_categories', function (Blueprint $table): void {
            $table->boolean('is_locked')->default(false);
        });

        Schema::table('case_categories', function (Blueprint $table): void {
            $table->boolean('is_locked')->default(false);
        });

        Schema::create('person_gallery_category_user', function (Blueprint $table): void {
            $table->foreignId('person_gallery_category_id');
            $table->foreignId('user_id');
            $table->primary(['person_gallery_category_id', 'user_id'], 'pgcu_primary');
            $table->foreign('person_gallery_category_id', 'pgcu_category_fk')
                ->references('id')
                ->on('person_gallery_categories')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'pgcu_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        Schema::create('case_category_user', function (Blueprint $table): void {
            $table->foreignId('case_category_id');
            $table->foreignId('user_id');
            $table->primary(['case_category_id', 'user_id'], 'ccu_primary');
            $table->foreign('case_category_id', 'ccu_category_fk')
                ->references('id')
                ->on('case_categories')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'ccu_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_category_user');
        Schema::dropIfExists('person_gallery_category_user');

        Schema::table('case_categories', function (Blueprint $table): void {
            $table->dropColumn('is_locked');
        });

        Schema::table('person_gallery_categories', function (Blueprint $table): void {
            $table->dropColumn('is_locked');
        });
    }
};
