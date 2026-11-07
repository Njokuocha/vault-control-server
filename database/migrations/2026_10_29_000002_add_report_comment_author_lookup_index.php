<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_comments', function (Blueprint $table): void {
            $table->index(
                ['report_id', 'author_type', 'author_id', 'updated_at'],
                'report_comments_author_preview_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('report_comments', function (Blueprint $table): void {
            $table->dropIndex('report_comments_author_preview_index');
        });
    }
};
