<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 16)->unique();
            $table->string('name')->default('Unknown');
            $table->string('first_name')->default('Unknown');
            $table->string('last_name')->default('Unknown');
            $table->string('surname')->default('Unknown');
            $table->string('age', 30)->default('Unknown');
            $table->string('date_of_birth', 40)->default('Unknown');
            $table->string('marital_status', 60)->default('Unknown');
            $table->string('spouse')->default('Unknown');
            $table->string('gender', 40)->default('Unknown');
            $table->string('citizenship', 100)->default('Unknown');
            $table->string('state', 100)->default('Unknown');
            $table->string('blood_group', 20)->default('Unknown');
            $table->string('genotype', 20)->default('Unknown');
            $table->string('height', 40)->default('Unknown');
            $table->string('flag', 20)->default('Unknown')->index();
            $table->string('occupation')->default('Unknown');
            $table->string('aliases')->default('Unknown');
            $table->string('last_known_location')->default('Unknown');
            $table->text('identifying_marks')->default('Unknown');
            $table->string('avatar_group', 20)->default('Group');
            $table->string('main_image_path', 500)->nullable();
            $table->text('statement')->default('Unknown');
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('person_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('original_name')->default('image');
            $table->timestamps();
        });

        Schema::create('case_files', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 16)->unique();
            $table->string('title')->default('Unknown');
            $table->string('classification', 40)->default('CLASSIFIED')->index();
            $table->string('case_status', 40)->default('OPEN')->index();
            $table->text('narrative')->default('Unknown');
            $table->text('comment')->default('Unknown');
            $table->timestamps();
        });

        Schema::create('case_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_file_id')->constrained('case_files')->cascadeOnDelete();
            $table->string('title')->default('Unknown');
            $table->text('description')->default('Unknown');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('case_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_category_id')->constrained('case_categories')->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('original_name')->default('image');
            $table->timestamps();
        });

        Schema::create('case_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('case_file_id')->constrained('case_files')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['person_id', 'case_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_person');
        Schema::dropIfExists('case_images');
        Schema::dropIfExists('case_categories');
        Schema::dropIfExists('case_files');
        Schema::dropIfExists('person_images');
        Schema::dropIfExists('people');
    }
};
