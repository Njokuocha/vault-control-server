<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_images', function (Blueprint $table): void {
            $table->index('path', 'case_images_path_index');
        });

        Schema::table('person_images', function (Blueprint $table): void {
            $table->index('path', 'person_images_path_index');
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->index('main_image_file_id', 'people_main_image_file_id_index');
            $table->index('main_image_path', 'people_main_image_path_index');
            $table->index('updated_at', 'people_updated_at_index');
        });

        Schema::table('case_files', function (Blueprint $table): void {
            $table->index('updated_at', 'case_files_updated_at_index');
        });

        Schema::table('report_case_file', function (Blueprint $table): void {
            $table->index('case_file_id', 'report_case_file_case_file_id_index');
        });

        Schema::table('report_person', function (Blueprint $table): void {
            $table->index('person_id', 'report_person_person_id_index');
        });

        Schema::table('upload_transfers', function (Blueprint $table): void {
            $table->index(['file_id', 'attachment_purpose'], 'upload_transfers_file_attachment_purpose_index');
        });
    }

    public function down(): void
    {
        $indexes = [
            'upload_transfers' => ['upload_transfers_file_attachment_purpose_index'],
            'report_person' => ['report_person_person_id_index'],
            'report_case_file' => ['report_case_file_case_file_id_index'],
            'case_files' => ['case_files_updated_at_index'],
            'people' => [
                'people_updated_at_index',
                'people_main_image_path_index',
                'people_main_image_file_id_index',
            ],
            'person_images' => ['person_images_path_index'],
            'case_images' => ['case_images_path_index'],
        ];

        foreach ($indexes as $tableName => $tableIndexes) {
            foreach ($tableIndexes as $indexName) {
                if (! Schema::hasIndex($tableName, $indexName)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropIndex($indexName);
                });
            }
        }
    }
};
