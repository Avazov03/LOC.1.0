<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §104: a primary key collision means the update was already handled (ERD §8).
        Schema::create('telegram_processed_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('update_id')->primary();
            $table->string('handler', 64)->nullable();
            $table->timestampTz('processed_at')->useCurrent();

            $table->index('processed_at');
        });

        // Dialog state only. Never a verified location or an organization point (ERD §8).
        Schema::create('telegram_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->string('state', 40);
            $table->jsonb('context')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        // A38: queued, idempotent student notifications. The key makes a retried or re-dispatched job a no-op.
        Schema::create('telegram_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->text('text');
            $table->enum('status', ['PENDING', 'SENT', 'FAILED', 'SKIPPED']);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['student_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_notifications');
        Schema::dropIfExists('telegram_conversations');
        Schema::dropIfExists('telegram_processed_updates');
    }
};
