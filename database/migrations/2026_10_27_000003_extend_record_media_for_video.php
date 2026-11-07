<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_images', function (Blueprint $table) {
            $table->string('type', 10)->default('image')->index();
            $table->foreignId('file_id')->nullable()->unique()->constrained('files')->nullOnDelete();
            $table->string('poster_path', 500)->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('playable_path', 500)->nullable();
        });

        Schema::table('case_images', function (Blueprint $table) {
            $table->string('type', 10)->default('image')->index();
            $table->foreignId('file_id')->nullable()->unique()->constrained('files')->nullOnDelete();
            $table->string('poster_path', 500)->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('playable_path', 500)->nullable();
        });

        Schema::table('video_uploads', function (Blueprint $table) {
            $table->timestamp('attached_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('video_uploads', function (Blueprint $table) {
            $table->dropIndex(['attached_at']);
            $table->dropColumn('attached_at');
        });

        Schema::table('case_images', function (Blueprint $table) {
            $table->dropForeign(['file_id']);
            $table->dropUnique(['file_id']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'file_id', 'poster_path', 'duration', 'playable_path']);
        });

        Schema::table('person_images', function (Blueprint $table) {
            $table->dropForeign(['file_id']);
            $table->dropUnique(['file_id']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'file_id', 'poster_path', 'duration', 'playable_path']);
        });
    }
};
