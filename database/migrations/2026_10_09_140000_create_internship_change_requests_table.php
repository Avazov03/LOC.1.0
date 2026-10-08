<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_assignment_id')->nullable()->constrained('internship_assignments')->restrictOnDelete();
            $table->enum('request_type', ['EXISTING_ORGANIZATION', 'NEW_ORGANIZATION']);
            $table->foreignId('requested_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->jsonb('requested_organization_data')->nullable();
            $table->text('reason');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'])->default('PENDING');
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['student_profile_id', 'created_at']);
        });

        DB::statement("CREATE UNIQUE INDEX internship_change_requests_one_pending ON internship_change_requests (student_profile_id) WHERE status = 'PENDING'");

        if (DB::getDriverName() === 'pgsql') {
            // A NEW_ORGANIZATION request gets requested_organization_id only when an admin approves it.
            DB::statement(<<<'SQL'
                ALTER TABLE internship_change_requests ADD CONSTRAINT internship_change_requests_payload CHECK (
                    (request_type = 'EXISTING_ORGANIZATION' AND requested_organization_id IS NOT NULL AND requested_organization_data IS NULL)
                    OR (request_type = 'NEW_ORGANIZATION' AND requested_organization_data IS NOT NULL)
                )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_change_requests');
    }
};
