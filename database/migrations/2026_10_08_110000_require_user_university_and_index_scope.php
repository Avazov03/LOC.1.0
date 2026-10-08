<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('users')->whereNull('university_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException("{$orphans} user row(s) have no university_id. Assign each one to a university before running this migration.");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('university_id')->nullable(false)->change();
            $table->index(['university_id', 'role', 'status'], 'users_university_role_status_index');
        });

        Schema::table('study_years', function (Blueprint $table) {
            $table->index('academic_year_id');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->index(['university_id', 'last_name', 'first_name'], 'student_profiles_directory_index');
            $table->index('current_group_id');
        });

        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->index('university_id');
        });

        Schema::table('student_group_memberships', function (Blueprint $table) {
            $table->index(['student_profile_id', 'joined_at'], 'student_group_memberships_history_index');
            $table->index('academic_year_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_group_memberships', function (Blueprint $table) {
            $table->dropIndex('student_group_memberships_history_index');
            $table->dropIndex(['academic_year_id']);
        });

        Schema::table('supervisor_profiles', function (Blueprint $table) {
            $table->dropIndex(['university_id']);
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropIndex('student_profiles_directory_index');
            $table->dropIndex(['current_group_id']);
        });

        Schema::table('study_years', function (Blueprint $table) {
            $table->dropIndex(['academic_year_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_university_role_status_index');
            $table->unsignedBigInteger('university_id')->nullable()->change();
        });
    }
};
