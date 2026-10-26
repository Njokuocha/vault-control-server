<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('video_playable_path', 500)->nullable();
            $table->string('video_poster_path', 500)->nullable();
            $table->unsignedInteger('video_duration')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['video_playable_path', 'video_poster_path', 'video_duration']);
        });
    }
};
