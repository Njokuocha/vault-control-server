<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_session_history', function (Blueprint $table): void {
            $table->char('session_key', 64)->primary();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
            $table->index(['staff_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_session_history');
    }
};
