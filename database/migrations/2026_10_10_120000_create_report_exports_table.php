<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A36: CSV of the caller's own scope, built by a queued job. Only the requesting user may download it.
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->jsonb('filters');
            $table->enum('status', ['PENDING', 'RUNNING', 'DONE', 'FAILED']);
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
