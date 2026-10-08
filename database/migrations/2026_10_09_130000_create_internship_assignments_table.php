<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internship_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('supervisor_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->enum('status', ['PENDING', 'ACTIVE', 'ENDED', 'CANCELLED']);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('ended_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['student_profile_id', 'start_at']);
            $table->index(['internship_id', 'status']);
            $table->index('organization_id');
            $table->index('supervisor_profile_id');
        });

        // D2: at most one PENDING or ACTIVE assignment per student.
        DB::statement("CREATE UNIQUE INDEX internship_assignments_one_open ON internship_assignments (student_profile_id) WHERE status IN ('PENDING', 'ACTIVE')");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE internship_assignments ADD CONSTRAINT internship_assignments_range CHECK (end_at > start_at)');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION internship_assignments_active_organization() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.status IN ('PENDING', 'ACTIVE')
                        AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM NEW.status OR OLD.organization_id IS DISTINCT FROM NEW.organization_id)
                        AND NOT EXISTS (SELECT 1 FROM organizations WHERE id = NEW.organization_id AND status = 'ACTIVE')
                    THEN
                        RAISE EXCEPTION 'organization % is not ACTIVE', NEW.organization_id USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER internship_assignments_active_organization BEFORE INSERT OR UPDATE ON internship_assignments
                    FOR EACH ROW EXECUTE FUNCTION internship_assignments_active_organization();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('internship_assignments');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS internship_assignments_active_organization()');
        }
    }
};
