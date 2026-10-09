<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        // Work days as an ISO weekday bitmask: bit 0 = Monday … bit 6 = Sunday. 127 = every day.
        Schema::table('internships', function (Blueprint $table) {
            $table->unsignedSmallInteger('work_days')->default(127);
        });
        // Null = the internship's work days; a value overrides them for this one student.
        Schema::table('internship_participants', function (Blueprint $table) {
            $table->unsignedSmallInteger('work_days')->nullable();
        });

        // Local time (HH:MM) of the daily supervisor digest and the student check-out reminder.
        Schema::table('universities', function (Blueprint $table) {
            $table->string('reminder_time', 5)->default('18:00');
        });

        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_user_id')->nullable()->unique();
            $table->timestampTz('telegram_linked_at')->nullable();
            $table->char('telegram_link_hash', 64)->nullable()->unique();
            $table->timestampTz('telegram_link_expires_at')->nullable();
            $table->boolean('notify_check_events')->default(true);
        });

        // A supervisor or admin decision about a whole day. Attendance events stay untouched; a revoked mark is kept.
        Schema::create('attendance_day_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('internship_assignments')->restrictOnDelete();
            $table->date('local_date');
            $table->enum('kind', ['PRESENT', 'EXCUSED']);
            $table->string('note', 500)->nullable();
            $table->enum('source', ['WEB', 'TELEGRAM']);
            $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['student_profile_id', 'local_date']);
        });
        DB::statement('CREATE UNIQUE INDEX attendance_day_marks_one_active ON attendance_day_marks (student_profile_id, local_date) WHERE revoked_at IS NULL');

        // Queued, idempotent Telegram messages to supervisors (same pattern as telegram_notifications).
        Schema::create('supervisor_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->foreignId('supervisor_profile_id')->constrained()->restrictOnDelete();
            $table->text('text');
            $table->jsonb('payload')->nullable();
            $table->enum('status', ['PENDING', 'SENT', 'FAILED', 'SKIPPED']);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['supervisor_profile_id', 'created_at']);
        });

        if ($pgsql) {
            DB::statement('ALTER TABLE internships ADD CONSTRAINT internships_work_days CHECK (work_days BETWEEN 1 AND 127)');
            DB::statement('ALTER TABLE internship_participants ADD CONSTRAINT internship_participants_work_days CHECK (work_days IS NULL OR work_days BETWEEN 1 AND 127)');
            DB::statement("ALTER TABLE universities ADD CONSTRAINT universities_reminder_time CHECK (reminder_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
            DB::statement('ALTER TABLE attendance_day_marks ADD CONSTRAINT attendance_day_marks_revoked CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_notifications');
        Schema::dropIfExists('attendance_day_marks');

        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->dropUnique(['telegram_user_id']);
            $table->dropUnique(['telegram_link_hash']);
            $table->dropColumn(['telegram_user_id', 'telegram_linked_at', 'telegram_link_hash', 'telegram_link_expires_at', 'notify_check_events']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE universities DROP CONSTRAINT IF EXISTS universities_reminder_time');
            DB::statement('ALTER TABLE internship_participants DROP CONSTRAINT IF EXISTS internship_participants_work_days');
            DB::statement('ALTER TABLE internships DROP CONSTRAINT IF EXISTS internships_work_days');
        }
        Schema::table('universities', fn (Blueprint $table) => $table->dropColumn('reminder_time'));
        Schema::table('internship_participants', fn (Blueprint $table) => $table->dropColumn('work_days'));
        Schema::table('internships', fn (Blueprint $table) => $table->dropColumn('work_days'));
    }
};
