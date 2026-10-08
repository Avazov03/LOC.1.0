<?php

namespace App\Services\Academic;

use App\Enums\AcademicYearStatus;
use App\Enums\ActiveStatus;
use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudyYear;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AcademicStructureService
{
    public function faculties(User $actor): Collection
    {
        return Faculty::query()
            ->where('university_id', $this->universityId($actor))
            ->withCount('programs')
            ->orderBy('name')
            ->get();
    }

    public function createFaculty(User $actor, string $name): Faculty
    {
        $universityId = $this->universityId($actor);
        $this->assertUnique(Faculty::query()->where('university_id', $universityId)->where('name', $name)->exists(), 'name', 'Bu fakultet allaqachon mavjud.');

        return Faculty::query()->create([
            'university_id' => $universityId,
            'name' => $name,
            'status' => ActiveStatus::Active,
        ]);
    }

    public function updateFaculty(User $actor, int $id, string $name, ActiveStatus $status): Faculty
    {
        $faculty = $this->faculty($actor, $id);
        $this->assertUnique(
            Faculty::query()->where('university_id', $faculty->university_id)->where('name', $name)->whereKeyNot($faculty->id)->exists(),
            'name',
            'Bu fakultet allaqachon mavjud.',
        );
        $faculty->update(['name' => $name, 'status' => $status]);

        return $faculty;
    }

    public function programs(User $actor): Collection
    {
        return Program::query()
            ->whereHas('faculty', fn ($query) => $query->where('university_id', $this->universityId($actor)))
            ->with('faculty')
            ->orderBy('name')
            ->get();
    }

    public function createProgram(User $actor, int $facultyId, string $name): Program
    {
        $faculty = $this->faculty($actor, $facultyId);
        $this->assertUnique(Program::query()->where('faculty_id', $faculty->id)->where('name', $name)->exists(), 'name', 'Bu yo‘nalish allaqachon mavjud.');

        return Program::query()->create([
            'faculty_id' => $faculty->id,
            'name' => $name,
            'status' => ActiveStatus::Active,
        ]);
    }

    public function updateProgram(User $actor, int $id, int $facultyId, string $name, ActiveStatus $status): Program
    {
        $program = $this->program($actor, $id);
        $faculty = $this->faculty($actor, $facultyId);
        $this->assertUnique(
            Program::query()->where('faculty_id', $faculty->id)->where('name', $name)->whereKeyNot($program->id)->exists(),
            'name',
            'Bu yo‘nalish allaqachon mavjud.',
        );
        $program->update(['faculty_id' => $faculty->id, 'name' => $name, 'status' => $status]);

        return $program;
    }

    public function academicYears(User $actor): Collection
    {
        return AcademicYear::query()
            ->where('university_id', $this->universityId($actor))
            ->orderByDesc('starts_on')
            ->get();
    }

    public function createAcademicYear(User $actor, string $name, string $startsOn, string $endsOn): AcademicYear
    {
        $this->assertDateOrder($startsOn, $endsOn);
        $universityId = $this->universityId($actor);
        $this->assertUnique(AcademicYear::query()->where('university_id', $universityId)->where('name', $name)->exists(), 'name', 'Bu o‘quv yili allaqachon mavjud.');

        return AcademicYear::query()->create([
            'university_id' => $universityId,
            'name' => $name,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => AcademicYearStatus::Active,
        ]);
    }

    public function updateAcademicYear(User $actor, int $id, string $name, string $startsOn, string $endsOn, AcademicYearStatus $status): AcademicYear
    {
        $this->assertDateOrder($startsOn, $endsOn);
        $year = $this->academicYear($actor, $id);
        $this->assertUnique(
            AcademicYear::query()->where('university_id', $year->university_id)->where('name', $name)->whereKeyNot($year->id)->exists(),
            'name',
            'Bu o‘quv yili allaqachon mavjud.',
        );
        $year->update([
            'name' => $name,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => $status,
        ]);

        return $year;
    }

    public function studyYears(User $actor): Collection
    {
        return StudyYear::query()
            ->whereHas('program.faculty', fn ($query) => $query->where('university_id', $this->universityId($actor)))
            ->with(['program.faculty', 'academicYear'])
            ->orderBy('course_number')
            ->get();
    }

    public function createStudyYear(User $actor, int $programId, int $academicYearId, int $courseNumber, string $name): StudyYear
    {
        $program = $this->program($actor, $programId);
        $year = $this->academicYear($actor, $academicYearId);
        $this->assertUnique(
            StudyYear::query()->where('program_id', $program->id)->where('academic_year_id', $year->id)->where('course_number', $courseNumber)->exists(),
            'course_number',
            'Bu kurs shu yil va yo‘nalishda allaqachon bor.',
        );

        return StudyYear::query()->create([
            'program_id' => $program->id,
            'academic_year_id' => $year->id,
            'course_number' => $courseNumber,
            'name' => $name,
        ]);
    }

    /**
     * Program and academic year stay fixed: internships and memberships already point at this course.
     */
    public function updateStudyYear(User $actor, int $id, int $courseNumber, string $name): StudyYear
    {
        $studyYear = $this->studyYear($actor, $id);
        $this->assertUnique(
            StudyYear::query()->where('program_id', $studyYear->program_id)->where('academic_year_id', $studyYear->academic_year_id)
                ->where('course_number', $courseNumber)->whereKeyNot($studyYear->id)->exists(),
            'course_number',
            'Bu kurs shu yil va yo‘nalishda allaqachon bor.',
        );
        $studyYear->update(['course_number' => $courseNumber, 'name' => $name]);

        return $studyYear;
    }

    public function groups(User $actor): Collection
    {
        return StudentGroup::query()
            ->whereHas('studyYear.program.faculty', fn ($query) => $query->where('university_id', $this->universityId($actor)))
            ->with(['studyYear.program.faculty', 'studyYear.academicYear'])
            ->orderBy('name')
            ->get();
    }

    public function createGroup(User $actor, int $studyYearId, string $name, string $code): StudentGroup
    {
        $studyYear = $this->studyYear($actor, $studyYearId);
        $this->assertUnique(
            StudentGroup::query()->where('study_year_id', $studyYear->id)->where('name', $name)->exists(),
            'name',
            'Bu guruh allaqachon mavjud.',
        );

        return StudentGroup::query()->create([
            'study_year_id' => $studyYear->id,
            'name' => $name,
            'code' => $code,
        ]);
    }

    /**
     * The course stays fixed: internships, invites and memberships already point at this group.
     */
    public function updateGroup(User $actor, int $id, string $name, string $code): StudentGroup
    {
        $group = StudentGroup::query()
            ->whereHas('studyYear.program.faculty', fn ($query) => $query->where('university_id', $this->universityId($actor)))
            ->findOrFail($id);
        $this->assertUnique(
            StudentGroup::query()->where('study_year_id', $group->study_year_id)->where('name', $name)->whereKeyNot($group->id)->exists(),
            'name',
            'Bu guruh allaqachon mavjud.',
        );
        $group->update(['name' => $name, 'code' => $code]);

        return $group;
    }

    public function faculty(User $actor, int $id): Faculty
    {
        return Faculty::query()->where('university_id', $this->universityId($actor))->findOrFail($id);
    }

    public function program(User $actor, int $id): Program
    {
        return Program::query()
            ->whereHas('faculty', fn ($query) => $query->where('university_id', $this->universityId($actor)))
            ->findOrFail($id);
    }

    public function academicYear(User $actor, int $id): AcademicYear
    {
        return AcademicYear::query()->where('university_id', $this->universityId($actor))->findOrFail($id);
    }

    public function studyYear(User $actor, int $id): StudyYear
    {
        return StudyYear::query()
            ->whereHas('program.faculty', fn ($query) => $query->where('university_id', $this->universityId($actor)))
            ->findOrFail($id);
    }

    private function universityId(User $actor): int
    {
        return $actor->university_id;
    }

    private function assertUnique(bool $exists, string $field, string $message): void
    {
        if ($exists) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function assertDateOrder(string $startsOn, string $endsOn): void
    {
        if ($endsOn <= $startsOn) {
            throw ValidationException::withMessages([
                'ends_on' => 'Tugash sanasi boshlanish sanasidan keyin bo‘lishi kerak.',
            ]);
        }
    }
}
