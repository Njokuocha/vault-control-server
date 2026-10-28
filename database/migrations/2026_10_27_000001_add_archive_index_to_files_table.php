<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->json('archive_index')->nullable();
            $table->string('archive_status', 16)->nullable()->index();
            $table->unsignedInteger('archive_entry_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropIndex(['archive_status']);
            $table->dropColumn(['archive_index', 'archive_status', 'archive_entry_count']);
        });
    }
};
