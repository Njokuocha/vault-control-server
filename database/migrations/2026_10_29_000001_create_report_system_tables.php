<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->longText('body');
            $table->string('reporter_type', 20);
            $table->unsignedBigInteger('reporter_id');
            $table->boolean('is_hidden')->default(false)->index();
            $table->timestamps();
            $table->index(['reporter_type', 'reporter_id']);
            $table->index(['updated_at', 'id']);
        });

        Schema::create('report_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->string('media_type', 10);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['report_id', 'sort_order']);
        });

        Schema::create('report_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('report_comments')->cascadeOnDelete();
            $table->string('author_type', 20);
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->timestamps();
            $table->index(['author_type', 'author_id']);
            $table->index(['report_id', 'parent_id', 'updated_at']);
        });

        Schema::create('report_case_file', function (Blueprint $table): void {
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_file_id')->constrained('case_files')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['report_id', 'case_file_id']);
        });

        Schema::create('report_person', function (Blueprint $table): void {
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['report_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_person');
        Schema::dropIfExists('report_case_file');
        Schema::dropIfExists('report_comments');
        Schema::dropIfExists('report_media');
        Schema::dropIfExists('reports');
    }
};
