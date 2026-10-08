<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Asia/Tashkent');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('university_id')->references('id')->on('universities')->restrictOnDelete();
        });

        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->unique(['university_id', 'name']);
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->unique(['faculty_id', 'name']);
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['ACTIVE', 'CLOSED'])->default('ACTIVE');
            $table->timestamps();
            $table->unique(['university_id', 'name']);
        });

        Schema::create('study_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('course_number');
            $table->string('name');
            $table->timestamps();
            $table->unique(['program_id', 'academic_year_id', 'course_number']);
        });

        Schema::create('student_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
            $table->unique(['study_year_id', 'name']);
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_group_id')->nullable()->constrained('student_groups')->restrictOnDelete();
            $table->string('student_code')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone');
            $table->unsignedBigInteger('telegram_user_id')->nullable()->unique();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'BLOCKED'])->default('ACTIVE');
            $table->timestamps();
            $table->index('phone');
            $table->unique(['university_id', 'student_code']);
        });

        Schema::create('supervisor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('phone');
            $table->string('position');
            $table->timestamps();
        });

        Schema::create('student_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('internship_invite_id')->nullable()->index();
            $table->enum('status', ['ACTIVE', 'ENDED']);
            $table->timestampTz('joined_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();
            $table->index('student_group_id');
        });

        DB::statement('CREATE UNIQUE INDEX student_group_memberships_one_active ON student_group_memberships (student_profile_id) WHERE status = \'ACTIVE\'');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE academic_years ADD CONSTRAINT academic_years_dates CHECK (ends_on > starts_on)');
            DB::statement('ALTER TABLE study_years ADD CONSTRAINT study_years_course_positive CHECK (course_number > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_group_memberships');
        Schema::dropIfExists('supervisor_profiles');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('student_groups');
        Schema::dropIfExists('study_years');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('faculties');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['university_id']);
        });
        Schema::dropIfExists('universities');
    }
};
