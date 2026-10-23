<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('original_name');
            $table->string('stored_path', 500);
            $table->string('extension', 20)->nullable();
            $table->string('mime_type', 150);
            $table->string('category', 30)->index();
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->boolean('is_editable')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
            $table->index('original_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
