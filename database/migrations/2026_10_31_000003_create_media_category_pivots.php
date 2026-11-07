<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_gallery_category_person_image', function (Blueprint $table): void {
            $table->foreignId('person_gallery_category_id');
            $table->foreignId('person_image_id');
            $table->primary(['person_gallery_category_id', 'person_image_id'], 'person_gallery_image_primary');
            $table->foreign('person_gallery_category_id', 'pgci_category_fk')
                ->references('id')
                ->on('person_gallery_categories')
                ->cascadeOnDelete();
            $table->foreign('person_image_id', 'pgci_image_fk')
                ->references('id')
                ->on('person_images')
                ->cascadeOnDelete();
        });

        Schema::create('case_category_case_image', function (Blueprint $table): void {
            $table->foreignId('case_category_id');
            $table->foreignId('case_image_id');
            $table->primary(['case_category_id', 'case_image_id'], 'case_category_image_primary');
            $table->foreign('case_category_id', 'cci_category_fk')
                ->references('id')
                ->on('case_categories')
                ->cascadeOnDelete();
            $table->foreign('case_image_id', 'cci_image_fk')
                ->references('id')
                ->on('case_images')
                ->cascadeOnDelete();
        });

        DB::table('person_images')
            ->whereNotNull('gallery_category_id')
            ->select(['gallery_category_id', 'id'])
            ->orderBy('id')
            ->chunk(500, function ($images): void {
                DB::table('person_gallery_category_person_image')->insert(
                    $images->map(fn (object $image): array => [
                        'person_gallery_category_id' => $image->gallery_category_id,
                        'person_image_id' => $image->id,
                    ])->all(),
                );
            });

        DB::table('case_images')
            ->select(['case_category_id', 'id'])
            ->orderBy('id')
            ->chunk(500, function ($images): void {
                DB::table('case_category_case_image')->insert(
                    $images->map(fn (object $image): array => [
                        'case_category_id' => $image->case_category_id,
                        'case_image_id' => $image->id,
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_category_case_image');
        Schema::dropIfExists('person_gallery_category_person_image');
    }
};
