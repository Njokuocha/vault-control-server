<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blacklisted_ips', function (Blueprint $table): void {
            $table->unsignedInteger('failed_attempts')->nullable()->change();
            $table->timestamp('triggered_at')->nullable()->change();
            $table->string('reason', 160)->nullable()->change();
            $table->string('source', 20)->default('automatic')->after('reason');
        });

        Schema::create('whitelisted_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whitelisted_ips');
        DB::table('blacklisted_ips')->whereNull('failed_attempts')->update(['failed_attempts' => 0]);
        DB::table('blacklisted_ips')->whereNull('triggered_at')->update(['triggered_at' => DB::raw('created_at')]);
        DB::table('blacklisted_ips')->whereNull('reason')->update(['reason' => 'Unknown']);
        Schema::table('blacklisted_ips', function (Blueprint $table): void {
            $table->dropColumn('source');
            $table->unsignedInteger('failed_attempts')->nullable(false)->change();
            $table->timestamp('triggered_at')->nullable(false)->change();
            $table->string('reason', 160)->nullable(false)->change();
        });
    }
};
