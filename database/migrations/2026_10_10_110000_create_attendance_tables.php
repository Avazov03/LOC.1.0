<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->enum('scope_type', ['UNIVERSITY', 'GROUP']);
            $table->unsignedBigInteger('scope_id');
            $table->boolean('check_in_enabled')->default(true);
            $table->boolean('check_out_enabled')->default(true);
            $table->unsignedInteger('minimum_duration_minutes')->nullable();
            $table->boolean('multiple_sessions_allowed')->default(false);
            $table->boolean('location_required')->default(true);
            $table->unsignedInteger('accuracy_threshold_meters')->nullable();
            $table->boolean('manual_correction_allowed')->default(true);
            $table->enum('status', ['ACTIVE', 'INACTIVE']);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['university_id', 'scope_type']);
        });
        DB::statement("CREATE UNIQUE INDEX attendance_policies_one_active ON attendance_policies (scope_type, scope_id) WHERE status = 'ACTIVE'");

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('internship_assignments')->restrictOnDelete();
            $table->date('local_date');
            $table->unsignedBigInteger('check_in_event_id')->nullable()->unique();
            $table->unsignedBigInteger('check_out_event_id')->nullable()->unique();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->enum('status', ['OPEN', 'COMPLETED', 'INCOMPLETE']);
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['student_profile_id', 'local_date']);
            $table->index('local_date');
            $table->index('assignment_id');
        });
        // One OPEN session per student. The service locks the student row first; this is the backstop.
        DB::statement("CREATE UNIQUE INDEX attendance_sessions_one_open ON attendance_sessions (student_profile_id) WHERE status = 'OPEN'");

        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('internship_assignments')->restrictOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('attendance_sessions')->restrictOnDelete();
            $table->enum('event_type', ['CHECK_IN', 'CHECK_OUT', 'FAILED_CHECK_IN', 'FAILED_CHECK_OUT', 'MANUAL_CORRECTION', 'SYSTEM_ADJUSTMENT']);
            $table->enum('verification_status', ['VERIFIED', 'OUTSIDE_RADIUS', 'INVALID_LOCATION', 'LOW_ACCURACY', 'NO_ASSIGNMENT', 'OUTSIDE_INTERNSHIP_PERIOD', 'NOT_APPLICABLE']);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->decimal('distance_meters', 12, 3)->nullable();
            $table->decimal('organization_latitude_snapshot', 10, 7)->nullable();
            $table->decimal('organization_longitude_snapshot', 10, 7)->nullable();
            $table->unsignedInteger('radius_snapshot_meters')->nullable();
            $table->timestampTz('occurred_at');
            $table->date('local_date');
            $table->enum('source', ['TELEGRAM', 'MANUAL', 'SYSTEM']);
            $table->unsignedBigInteger('telegram_update_id')->nullable()->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['student_profile_id', 'occurred_at']);
            $table->index(['student_profile_id', 'local_date']);
            $table->index('local_date');
            $table->index('session_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_check_in_fk FOREIGN KEY (check_in_event_id) REFERENCES attendance_events (id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_check_out_fk FOREIGN KEY (check_out_event_id) REFERENCES attendance_events (id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_duration CHECK (duration_seconds IS NULL OR duration_seconds >= 0)');
            DB::statement("ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_completed CHECK (status <> 'COMPLETED' OR (closed_at IS NOT NULL AND duration_seconds IS NOT NULL))");
            DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_minimum CHECK (minimum_duration_minutes IS NULL OR minimum_duration_minutes > 0)');
            DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_accuracy CHECK (accuracy_threshold_meters IS NULL OR accuracy_threshold_meters > 0)');
            DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT attendance_policies_single_session CHECK (multiple_sessions_allowed = false)');
            DB::statement('ALTER TABLE attendance_events ADD CONSTRAINT attendance_events_coordinates CHECK ((latitude IS NULL) = (longitude IS NULL))');

            // A34: events are immutable facts. Corrections add rows; they never edit or remove one.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION attendance_events_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'attendance_events is immutable';
                END;
                $$;
                CREATE TRIGGER attendance_events_immutable BEFORE UPDATE OR DELETE ON attendance_events
                    FOR EACH ROW EXECUTE FUNCTION attendance_events_immutable();
                SQL);
        } else {
            DB::unprepared("CREATE TRIGGER attendance_events_no_update BEFORE UPDATE ON attendance_events BEGIN SELECT RAISE(ABORT, 'attendance_events is immutable'); END;");
            DB::unprepared("CREATE TRIGGER attendance_events_no_delete BEFORE DELETE ON attendance_events BEGIN SELECT RAISE(ABORT, 'attendance_events is immutable'); END;");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendance_sessions DROP CONSTRAINT IF EXISTS attendance_sessions_check_in_fk');
            DB::statement('ALTER TABLE attendance_sessions DROP CONSTRAINT IF EXISTS attendance_sessions_check_out_fk');
        }
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('attendance_policies');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS attendance_events_immutable()');
        }
    }
};
