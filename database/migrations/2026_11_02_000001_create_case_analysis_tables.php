<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_analysis_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_file_id')->constrained('case_files')->cascadeOnDelete();
            $table->string('author_type', 30);
            $table->unsignedBigInteger('author_id');
            $table->string('author_name');
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['case_file_id', 'created_at']);
            $table->index(['author_type', 'author_id']);
        });

        Schema::create('case_analysis_recent_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_file_id')->constrained('case_files')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('last_accessed_at');
            $table->timestamps();
            $table->unique(['user_id', 'case_file_id'], 'case_analysis_recent_user_case_unique');
            $table->unique(['admin_id', 'case_file_id'], 'case_analysis_recent_admin_case_unique');
            $table->index(['user_id', 'last_accessed_at']);
            $table->index(['admin_id', 'last_accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_analysis_recent_accesses');
        Schema::dropIfExists('case_analysis_notes');
    }
};
