<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_media', function (Blueprint $table): void {
            $table->string('thumbnail_path', 500)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('report_media', function (Blueprint $table): void {
            $table->dropColumn('thumbnail_path');
        });
    }
};
