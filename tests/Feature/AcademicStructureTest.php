<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudentGroupMembership;
use App\Models\StudentProfile;
use App\Models\StudyYear;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_faculty(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/academic/faculties', ['name' => 'Yuridik fakultet'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('faculties', [
            'university_id' => $admin->university_id,
            'name' => 'Yuridik fakultet',
        ]);
    }

    public function test_duplicate_faculty_name_is_rejected(): void
    {
        $admin = User::factory()->create();
        Faculty::query()->create([
            'university_id' => $admin->university_id,
            'name' => 'Yuridik fakultet',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($admin)
            ->from('/academic/faculties')
            ->post('/academic/faculties', ['name' => 'Yuridik fakultet'])
            ->assertRedirect('/academic/faculties')
            ->assertSessionHasErrors('name');
    }

    public function test_admin_cannot_attach_a_program_to_another_university(): void
    {
        $admin = User::factory()->create();
        $other = University::factory()->create();
        $foreignFaculty = Faculty::query()->create([
            'university_id' => $other->id,
            'name' => 'Chet fakultet',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($admin)
            ->post('/academic/programs', [
                'faculty_id' => $foreignFaculty->id,
                'name' => 'Yurisprudensiya',
            ])
            ->assertNotFound();
    }

    public function test_seed_builds_the_demo_academic_tree(): void
    {
        $this->seed();

        $this->assertDatabaseHas('universities', ['slug' => 'demo-universitet']);
        $this->assertSame(2, Faculty::query()->count());
        $this->assertSame(2, Program::query()->count());
        $this->assertSame(3, StudentGroup::query()->count());
        $this->assertDatabaseHas('users', ['login' => 'admin', 'role' => 'ADMIN']);
    }

    public function test_a_student_cannot_have_two_active_group_memberships(): void
    {
        $university = University::factory()->create();
        $user = User::query()->create([
            'university_id' => $university->id,
            'name' => 'Aliyev Abdulloh',
            'login' => 'aliyev',
            'password' => Hash::make('password'),
            'role' => UserRole::Student,
            'status' => 'ACTIVE',
        ]);
        $faculty = Faculty::query()->create([
            'university_id' => $university->id,
            'name' => 'Yuridik',
            'status' => 'ACTIVE',
        ]);
        $program = Program::query()->create([
            'faculty_id' => $faculty->id,
            'name' => 'Yurisprudensiya',
            'status' => 'ACTIVE',
        ]);
        $year = AcademicYear::query()->create([
            'university_id' => $university->id,
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'status' => 'ACTIVE',
        ]);
        $studyYear = StudyYear::query()->create([
            'program_id' => $program->id,
            'academic_year_id' => $year->id,
            'course_number' => 4,
            'name' => '4-kurs',
        ]);
        $first = StudentGroup::query()->create(['study_year_id' => $studyYear->id, 'name' => '403-guruh', 'code' => '403']);
        $second = StudentGroup::query()->create(['study_year_id' => $studyYear->id, 'name' => '404-guruh', 'code' => '404']);
        $student = StudentProfile::query()->create([
            'user_id' => $user->id,
            'university_id' => $university->id,
            'current_group_id' => $first->id,
            'first_name' => 'Abdulloh',
            'last_name' => 'Aliyev',
            'phone' => '+998901112233',
            'telegram_user_id' => 1001,
            'status' => StudentStatus::Active,
        ]);

        StudentGroupMembership::query()->create([
            'student_profile_id' => $student->id,
            'student_group_id' => $first->id,
            'academic_year_id' => $year->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        StudentGroupMembership::query()->create([
            'student_profile_id' => $student->id,
            'student_group_id' => $second->id,
            'academic_year_id' => $year->id,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
    }
}
