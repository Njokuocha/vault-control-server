<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_transfers', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('upload_transfers', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('upload_transfers', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->cascadeOnDelete();
            $table->index(['admin_id', 'status', 'created_at'], 'upload_transfers_admin_status_created_index');
        });
    }

    public function down(): void
    {
        if (DB::table('upload_transfers')->whereNotNull('admin_id')->exists()) {
            throw new RuntimeException('Admin-owned uploads must be removed before rolling back their ownership support.');
        }

        Schema::table('upload_transfers', function (Blueprint $table): void {
            $table->dropIndex('upload_transfers_admin_status_created_index');
            $table->dropConstrainedForeignId('admin_id');
            $table->dropForeign(['user_id']);
        });

        Schema::table('upload_transfers', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('upload_transfers', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
