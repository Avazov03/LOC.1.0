<?php

namespace Tests\Concerns;

use App\Enums\ActiveStatus;
use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use App\Models\StudyYear;
use App\Models\University;
use App\Models\User;

trait BuildsAcademicTree
{
    /**
     * @return array{faculty: Faculty, program: Program, year: AcademicYear, studyYear: StudyYear, group: StudentGroup}
     */
    protected function academicTree(University $university, string $label = 'A'): array
    {
        $faculty = Faculty::query()->create(['university_id' => $university->id, 'name' => "Fakultet {$label}", 'status' => ActiveStatus::Active]);
        $program = Program::query()->create(['faculty_id' => $faculty->id, 'name' => "Yo‘nalish {$label}", 'status' => ActiveStatus::Active]);
        $year = AcademicYear::query()->firstOrCreate(
            ['university_id' => $university->id, 'name' => '2026/2027'],
            ['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'status' => 'ACTIVE'],
        );
        $studyYear = StudyYear::query()->create(['program_id' => $program->id, 'academic_year_id' => $year->id, 'course_number' => 4, 'name' => '4-kurs']);
        $group = StudentGroup::query()->create(['study_year_id' => $studyYear->id, 'name' => "Guruh {$label}", 'code' => $label]);

        return compact('faculty', 'program', 'year', 'studyYear', 'group');
    }

    protected function student(University $university, StudentGroup $group, int $telegramUserId, string $lastName): StudentProfile
    {
        $user = User::query()->create([
            'university_id' => $university->id,
            'name' => $lastName,
            'role' => UserRole::Student,
            'status' => ActiveStatus::Active,
        ]);

        return StudentProfile::query()->create([
            'user_id' => $user->id,
            'university_id' => $university->id,
            'current_group_id' => $group->id,
            'first_name' => 'Talaba',
            'last_name' => $lastName,
            'phone' => '+99890'.str_pad((string) $telegramUserId, 7, '0', STR_PAD_LEFT),
            'telegram_user_id' => $telegramUserId,
            'status' => StudentStatus::Active,
        ]);
    }
}
