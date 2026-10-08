<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

/**
 * Admin student directory, identity correction and status (§12, §13, A48), and the supervisor's scoped student list (§50, §94).
 */
class StudentManagementTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    public function test_admin_lists_students_with_group_and_current_organization(): void
    {
        $world = $this->world();
        $this->placement($world['internship'], $world['students'][0], $world['org']);

        $this->actingAs($world['admin'])->get('/academic/students')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Academic/Students')
                ->has('students.data', 2)
                ->where('students.data.0.name', 'Aliyev Talaba')
                ->where('students.data.0.group', 'Guruh A')
                ->where('students.data.0.program', 'Yo‘nalish A')
                ->where('students.data.0.assignment.organization', 'Sud')
                ->where('students.data.1.assignment', null)
                ->has('groups', 1)
                ->has('internships', 1));
    }

    public function test_admin_search_and_filters_narrow_the_list(): void
    {
        $world = $this->world();
        [$aliyev, $valiyev] = $world['students'];
        $this->placement($world['internship'], $aliyev, $world['org']);
        $valiyev->update(['status' => StudentStatus::Blocked, 'student_code' => 'ST-77']);

        $names = fn (string $query) => collect($this->actingAs($world['admin'])->get('/academic/students?'.$query)->viewData('page')['props']['students']['data'])->pluck('name')->all();

        $this->assertSame(['Valiyev Talaba'], $names('search=valiy'));
        $this->assertSame(['Valiyev Talaba'], $names('search=ST-77'));
        $this->assertSame(['Aliyev Talaba'], $names('search='.urlencode($aliyev->phone)));
        $this->assertSame(['Aliyev Talaba'], $names('placement=assigned'));
        $this->assertSame(['Valiyev Talaba'], $names('placement=unassigned'));
        $this->assertSame(['Valiyev Talaba'], $names('status=BLOCKED'));
        $this->assertSame(['Aliyev Talaba', 'Valiyev Talaba'], $names('group='.$world['group']->id));
        $this->assertSame(['Aliyev Talaba', 'Valiyev Talaba'], $names('internship='.$world['internship']->id));
        $this->assertSame(['Aliyev Talaba', 'Valiyev Talaba'], $names('status=NOPE&placement=nope'));
    }

    public function test_admin_detail_shows_academic_path_internship_supervisor_and_assignments(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->placement($world['internship'], $student, $world['org']);

        $this->actingAs($world['admin'])->get("/academic/students/{$student->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Academic/StudentShow')
                ->where('student.name', 'Aliyev Talaba')
                ->where('student.faculty', 'Fakultet A')
                ->where('student.program', 'Yo‘nalish A')
                ->where('student.course', '4-kurs')
                ->where('student.group', 'Guruh A')
                ->where('student.academic_year', '2026/2027')
                ->where('student.telegram_user_id', (string) $student->telegram_user_id)
                ->where('student.participations.0.supervisor', 'Rahbar')
                ->where('assignments.0.organization', 'Sud')
                ->where('assignments.0.status', 'ACTIVE')
                ->has('history'));
    }

    public function test_admin_corrects_identity_with_audit_and_cannot_touch_telegram_id(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $telegramId = $student->telegram_user_id;

        $this->actingAs($world['admin'])->put("/academic/students/{$student->id}", [
            'first_name' => 'Anvar',
            'last_name' => 'Aliyev',
            'phone' => '+998901112233',
            'student_code' => 'ST-1',
            'telegram_user_id' => 999999,
            'reason' => 'Pasport bo‘yicha',
        ])->assertSessionHas('success');

        $student->refresh();
        $this->assertSame('Anvar', $student->first_name);
        $this->assertSame('ST-1', $student->student_code);
        $this->assertSame($telegramId, $student->telegram_user_id);
        $this->assertSame('Aliyev Anvar', $student->user->name);

        $log = AuditLog::query()->where('action', 'student.update')->sole();
        $this->assertSame('student_profile', $log->entity_type);
        $this->assertSame('Talaba', $log->before['first_name']);
        $this->assertSame('Anvar', $log->after['first_name']);
        $this->assertSame('Pasport bo‘yicha', $log->reason);
        $this->assertSame($world['university']->id, $log->university_id);
    }

    public function test_unchanged_correction_writes_no_audit_and_duplicate_code_is_refused(): void
    {
        $world = $this->world();
        [$aliyev, $valiyev] = $world['students'];
        $valiyev->update(['student_code' => 'ST-9']);
        $payload = ['first_name' => 'Talaba', 'last_name' => 'Aliyev', 'phone' => $aliyev->phone, 'student_code' => ''];

        $this->actingAs($world['admin'])->put("/academic/students/{$aliyev->id}", $payload)->assertSessionHas('success');
        $this->assertSame(0, AuditLog::query()->where('action', 'student.update')->count());

        $this->actingAs($world['admin'])->put("/academic/students/{$aliyev->id}", [...$payload, 'student_code' => 'ST-9'])->assertSessionHasErrors('student_code');
        $this->assertNull($aliyev->fresh()->student_code);

        $this->actingAs($world['admin'])->put("/academic/students/{$aliyev->id}", [...$payload, 'first_name' => '', 'phone' => 'abc'])->assertSessionHasErrors(['first_name', 'phone']);
    }

    public function test_admin_changes_status_with_reason_and_audit_and_assignment_is_kept(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $assignment = $this->placement($world['internship'], $student, $world['org']);

        $this->actingAs($world['admin'])->patch("/academic/students/{$student->id}/status", ['status' => 'BLOCKED'])->assertSessionHasErrors(['reason' => 'Sabab kiritilishi shart.']);
        $this->assertSame(StudentStatus::Active, $student->fresh()->status);

        $this->actingAs($world['admin'])->patch("/academic/students/{$student->id}/status", ['status' => 'BLOCKED', 'reason' => 'Intizom'])->assertSessionHas('success');
        $this->assertSame(StudentStatus::Blocked, $student->fresh()->status);
        $this->assertSame(AssignmentStatus::Active, $assignment->fresh()->status);

        $log = AuditLog::query()->where('action', 'student.status_change')->sole();
        $this->assertSame(['status' => 'ACTIVE'], $log->before);
        $this->assertSame(['status' => 'BLOCKED'], $log->after);
        $this->assertSame('Intizom', $log->reason);

        $this->actingAs($world['admin'])->patch("/academic/students/{$student->id}/status", ['status' => 'BLOCKED', 'reason' => 'Yana'])->assertSessionHas('success');
        $this->assertSame(1, AuditLog::query()->where('action', 'student.status_change')->count());

        $this->actingAs($world['admin'])->patch("/academic/students/{$student->id}/status", ['status' => 'DELETED', 'reason' => 'x'])->assertSessionHasErrors('status');
        $this->assertSame(2, StudentProfile::query()->count());
    }

    public function test_foreign_student_is_404_for_admin_and_admin_routes_are_403_for_supervisor(): void
    {
        $a = $this->world('A', 1000);
        $b = $this->world('B', 2000);
        $foreign = $b['students'][0];

        $this->actingAs($a['admin'])->get("/academic/students/{$foreign->id}")->assertNotFound();
        $this->actingAs($a['admin'])->put("/academic/students/{$foreign->id}", ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '+998900000001'])->assertNotFound();
        $this->actingAs($a['admin'])->patch("/academic/students/{$foreign->id}/status", ['status' => 'BLOCKED', 'reason' => 'x'])->assertNotFound();
        $this->assertSame(StudentStatus::Active, $foreign->fresh()->status);

        $own = $a['students'][0];
        $this->actingAs($a['supervisor'])->get("/academic/students/{$own->id}")->assertForbidden();
        $this->actingAs($a['supervisor'])->put("/academic/students/{$own->id}", ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '+998900000001'])->assertForbidden();
        $this->actingAs($a['supervisor'])->patch("/academic/students/{$own->id}/status", ['status' => 'BLOCKED', 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, AuditLog::query()->whereIn('action', ['student.update', 'student.status_change'])->count());
    }

    public function test_supervisor_student_list_is_scoped_to_open_periods(): void
    {
        $world = $this->world();
        $university = $world['university'];
        $otherTree = $this->academicTree($university, 'C');
        $otherInternship = $this->internship($university, $otherTree['group'], $this->supervisorProfile($university, 'Boshqa rahbar'));
        $outsider = $this->student($university, $otherTree['group'], 3001, 'Begona');
        $this->enroll($otherInternship, $outsider);
        $this->placement($world['internship'], $world['students'][0], $world['org']);

        $this->actingAs($world['supervisor'])->get('/my-students')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Supervisor/Students')
                ->has('students.data', 2)
                ->where('students.total', 2)
                ->has('internships', 1)
                ->where('internships.0.id', $world['internship']->id));

        $names = fn (string $query) => collect($this->actingAs($world['supervisor'])->get('/my-students?'.$query)->viewData('page')['props']['students']['data'])->pluck('name')->all();
        $this->assertSame([], $names('search=Begona'));
        $this->assertSame(['Aliyev Talaba'], $names('placement=assigned'));
        $this->assertSame(['Valiyev Talaba'], $names('placement=unassigned'));
        $this->assertSame([], $names('internship='.$otherInternship->id));

        $this->actingAs($world['supervisor'])->get("/students/{$outsider->id}")->assertNotFound();
        $this->actingAs($world['admin'])->get('/my-students')->assertForbidden();
    }

    public function test_supervisor_detail_shows_period_supervisor_and_organization_but_not_telegram_id(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->placement($world['internship'], $student, $world['org']);

        $this->actingAs($world['supervisor'])->get("/students/{$student->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Supervisor/StudentShow')
                ->where('student.telegram_user_id', null)
                ->where('student.program', 'Yo‘nalish A')
                ->has('student.participations', 1)
                ->where('student.participations.0.supervisor', 'Rahbar')
                ->where('student.participations.0.period_start', $world['internship']->period_start->toDateString())
                ->where('assignments.0.organization', 'Sud')
                ->where('assignments.0.supervisor', 'Rahbar'));
    }

    public function test_replaced_supervisor_loses_the_student_list_immediately(): void
    {
        $world = $this->world();
        $replacement = $this->supervisorProfile($world['university'], 'Yangi rahbar');

        $this->actingAs($world['admin'])->post("/internships/{$world['internship']->id}/supervisor", ['supervisor_profile_id' => $replacement->id])->assertSessionHas('success');

        $this->actingAs($world['supervisor'])->get('/my-students')->assertInertia(fn ($page) => $page->where('students.total', 0));
        $this->actingAs($world['supervisor'])->get('/students/'.$world['students'][0]->id)->assertNotFound();
        $this->actingAs($replacement->user)->get('/my-students')->assertInertia(fn ($page) => $page->where('students.total', 2));
    }
}
