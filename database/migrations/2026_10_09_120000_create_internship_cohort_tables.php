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

        Schema::create('internships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_group_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['university_id', 'academic_year_id']);
            $table->index('student_group_id');
        });

        Schema::create('internship_supervisor_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->foreignId('supervisor_profile_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX internship_supervisor_periods_one_open ON internship_supervisor_periods (internship_id) WHERE ends_on IS NULL');
        DB::statement('CREATE INDEX internship_supervisor_periods_open_supervisor ON internship_supervisor_periods (supervisor_profile_id) WHERE ends_on IS NULL');

        Schema::create('internship_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('supervisor_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['ACTIVE', 'CLOSED', 'EXPIRED'])->default('ACTIVE');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index('internship_id');
        });

        Schema::create('internship_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('internship_invite_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('joined_at');
            $table->timestampsTz();

            $table->unique(['internship_id', 'student_profile_id']);
            $table->index('student_profile_id');
        });

        if ($pgsql) {
            Schema::table('student_group_memberships', function (Blueprint $table) {
                $table->foreign('internship_invite_id')->references('id')->on('internship_invites')->restrictOnDelete();
            });

            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE internships ADD CONSTRAINT internships_period_order CHECK (period_end >= period_start)');
            DB::statement('ALTER TABLE internship_supervisor_periods ADD CONSTRAINT internship_supervisor_periods_order CHECK (ends_on IS NULL OR ends_on >= starts_on)');
            DB::statement(<<<'SQL'
                ALTER TABLE internship_supervisor_periods ADD CONSTRAINT internship_supervisor_periods_no_overlap
                    EXCLUDE USING gist (internship_id WITH =, daterange(starts_on, ends_on, '[)') WITH &&)
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('student_group_memberships', function (Blueprint $table) {
                $table->dropForeign(['internship_invite_id']);
            });
        }
        // The invites go away with this migration; a dangling id would block re-adding the foreign key.
        DB::table('student_group_memberships')->whereNotNull('internship_invite_id')->update(['internship_invite_id' => null]);

        Schema::dropIfExists('internship_participants');
        Schema::dropIfExists('internship_invites');
        Schema::dropIfExists('internship_supervisor_periods');
        Schema::dropIfExists('internships');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP EXTENSION IF EXISTS btree_gist');
        }
    }
};
