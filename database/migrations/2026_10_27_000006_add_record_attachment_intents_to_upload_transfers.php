<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_transfers', function (Blueprint $table) {
            $table->string('attachment_purpose', 32)->nullable()->after('user_id');
            $table->string('record_type', 20)->nullable()->after('attachment_purpose');
            $table->unsignedBigInteger('record_id')->nullable()->after('record_type');
            $table->uuid('draft_token')->nullable()->after('record_id');
            $table->unsignedBigInteger('target_id')->nullable()->after('draft_token');
            $table->uuid('target_key')->nullable()->after('target_id');
            $table->timestamp('committed_at')->nullable()->after('target_key');
            $table->timestamp('attached_at')->nullable()->after('committed_at');
            $table->index(['record_type', 'record_id', 'attachment_purpose'], 'upload_transfers_record_attachment_index');
            $table->index(['user_id', 'draft_token'], 'upload_transfers_user_draft_index');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('main_image_file_id')->nullable()->after('main_image_path')->constrained('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_image_file_id');
        });

        Schema::table('upload_transfers', function (Blueprint $table) {
            $table->dropIndex('upload_transfers_record_attachment_index');
            $table->dropIndex('upload_transfers_user_draft_index');
            $table->dropColumn([
                'attachment_purpose',
                'record_type',
                'record_id',
                'draft_token',
                'target_id',
                'target_key',
                'committed_at',
                'attached_at',
            ]);
        });
    }
};
