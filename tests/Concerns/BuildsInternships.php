<?php

namespace Tests\Concerns;

use App\Enums\ActiveStatus;
use App\Enums\AssignmentStatus;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\InternshipParticipant;
use App\Models\InternshipSupervisorPeriod;
use App\Models\Organization;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use App\Models\SupervisorProfile;
use App\Models\University;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

trait BuildsInternships
{
    use BuildsAcademicTree;

    protected function creatorId(University $university): int
    {
        return User::query()->where('university_id', $university->id)->where('role', 'ADMIN')->value('id')
            ?? User::factory()->create(['university_id' => $university->id])->id;
    }

    protected function supervisorProfile(University $university, string $name = 'Rahbar'): SupervisorProfile
    {
        $user = User::factory()->supervisor()->create(['university_id' => $university->id, 'name' => $name]);

        return SupervisorProfile::query()->create([
            'user_id' => $user->id,
            'university_id' => $university->id,
            'phone' => '+998900000000',
            'position' => 'Dotsent',
        ]);
    }

    protected function organization(University $university, string $name = 'Tashkilot', ActiveStatus $status = ActiveStatus::Active): Organization
    {
        return Organization::query()->create([
            'university_id' => $university->id,
            'name' => $name,
            'type' => 'Sud',
            'address' => 'Toshkent',
            'location' => Geo::point(41.3111, 69.2797),
            'radius_meters' => 100,
            'status' => $status,
            'contact_name' => 'Mas’ul',
            'contact_phone' => '+998712000000',
            'created_by' => $this->creatorId($university),
        ]);
    }

    protected function internship(University $university, StudentGroup $group, SupervisorProfile $supervisor): Internship
    {
        $group->loadMissing('studyYear');
        $today = now($university->timezone)->startOfDay();

        $internship = Internship::query()->create([
            'university_id' => $university->id,
            'academic_year_id' => $group->studyYear->academic_year_id,
            'student_group_id' => $group->id,
            'period_start' => $today->copy()->subDays(7)->toDateString(),
            'period_end' => $today->copy()->addDays(60)->toDateString(),
            'created_by' => $this->creatorId($university),
        ]);
        InternshipSupervisorPeriod::query()->create([
            'internship_id' => $internship->id,
            'supervisor_profile_id' => $supervisor->id,
            'starts_on' => $today->copy()->subDays(7)->toDateString(),
            'created_by' => $this->creatorId($university),
        ]);

        return $internship;
    }

    protected function enroll(Internship $internship, StudentProfile ...$students): void
    {
        foreach ($students as $student) {
            InternshipParticipant::query()->create([
                'internship_id' => $internship->id,
                'student_profile_id' => $student->id,
                'joined_at' => now(),
            ]);
        }
    }

    protected function placement(Internship $internship, StudentProfile $student, Organization $organization, AssignmentStatus $status = AssignmentStatus::Active): InternshipAssignment
    {
        return InternshipAssignment::query()->create([
            'student_profile_id' => $student->id,
            'organization_id' => $organization->id,
            'supervisor_profile_id' => $internship->currentPeriod()->value('supervisor_profile_id'),
            'internship_id' => $internship->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(30),
            'status' => $status,
            'created_by' => $this->creatorId($internship->university()->firstOrFail()),
        ]);
    }

    /**
     * University with an admin, a supervisor running one internship with two enrolled students, and two ACTIVE organizations.
     *
     * @return array{university: University, admin: User, supervisor: User, profile: SupervisorProfile, internship: Internship, students: list<StudentProfile>, org: Organization, org2: Organization, group: StudentGroup}
     */
    protected function world(string $label = 'A', int $telegramBase = 1000): array
    {
        $university = University::factory()->create();
        $admin = User::factory()->create(['university_id' => $university->id]);
        $tree = $this->academicTree($university, $label);
        $profile = $this->supervisorProfile($university);
        $internship = $this->internship($university, $tree['group'], $profile);
        $students = [
            $this->student($university, $tree['group'], $telegramBase + 1, 'Aliyev'),
            $this->student($university, $tree['group'], $telegramBase + 2, 'Valiyev'),
        ];
        $this->enroll($internship, ...$students);

        return [
            'university' => $university,
            'admin' => $admin,
            'supervisor' => $profile->user,
            'profile' => $profile,
            'internship' => $internship,
            'students' => $students,
            'org' => $this->organization($university, 'Sud'),
            'org2' => $this->organization($university, 'Prokuratura'),
            'group' => $tree['group'],
        ];
    }

    protected function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<string, mixed>
     */
    protected function assignPayload(Internship $internship, Organization $organization, array $studentIds, int $startOffsetDays = 0): array
    {
        $today = now('Asia/Tashkent');

        return [
            'internship_id' => $internship->id,
            'organization_id' => $organization->id,
            'student_ids' => $studentIds,
            'start_date' => $today->copy()->addDays($startOffsetDays)->toDateString(),
            'end_date' => $today->copy()->addDays(30)->toDateString(),
        ];
    }
}
