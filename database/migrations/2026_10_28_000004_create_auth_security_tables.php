<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table): void {
            $table->id();
            $table->string('guard', 20);
            $table->string('account_type', 20)->nullable();
            $table->string('account_id', 64)->nullable();
            $table->string('account_name');
            $table->string('ip_address', 45)->index();
            $table->unsignedInteger('duration_ms');
            $table->string('status', 20)->index();
            $table->boolean('is_brute_force')->default(false)->index();
            $table->string('result_detail', 160)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['guard', 'occurred_at']);
        });

        Schema::create('brute_force_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auth_event_id')->nullable()->constrained('auth_events')->nullOnDelete();
            $table->string('account_type', 20)->nullable();
            $table->string('account_id', 64)->nullable();
            $table->string('account_name');
            $table->string('ip_address', 45)->index();
            $table->unsignedInteger('failed_attempts');
            $table->unsignedInteger('window_seconds');
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamps();
        });

        Schema::create('blacklisted_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->unsignedInteger('failed_attempts');
            $table->timestamp('triggered_at')->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('reason', 160);
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 40)->index();
            $table->string('action', 80)->index();
            $table->string('actor_type', 20)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_name');
            $table->string('summary', 255);
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['category', 'occurred_at']);
        });

        Schema::create('security_session_presence', function (Blueprint $table): void {
            $table->string('session_id')->primary();
            $table->string('guard', 20);
            $table->string('account_type', 20);
            $table->string('account_id', 64);
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
            $table->index(['guard', 'account_id']);
        });

        Schema::create('security_metrics_snapshots', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('active_accounts')->default(0);
            $table->timestamp('captured_at')->index();
        });

        Schema::create('security_locations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('map_url');
            $table->text('embed_url');
            $table->string('latitude', 32)->default('Unknown');
            $table->string('longitude', 32)->default('Unknown');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_locations');
        Schema::dropIfExists('security_metrics_snapshots');
        Schema::dropIfExists('security_session_presence');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('blacklisted_ips');
        Schema::dropIfExists('brute_force_records');
        Schema::dropIfExists('auth_events');
    }
};
