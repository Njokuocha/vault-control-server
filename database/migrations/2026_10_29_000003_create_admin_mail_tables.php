<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('related_email_id')->nullable()->constrained('sent_emails')->nullOnDelete();
            $table->string('message_kind', 20)->default('original');
            $table->text('to_recipients');
            $table->text('cc_recipients')->nullable();
            $table->text('bcc_recipients')->nullable();
            $table->string('from_address', 255);
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 255);
            $table->longText('body_html');
            $table->string('status', 20)->default('sending');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('sent_email_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sent_email_id')->constrained()->cascadeOnDelete();
            $table->string('stored_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->index('sent_email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_email_attachments');
        Schema::dropIfExists('sent_emails');
    }
};
