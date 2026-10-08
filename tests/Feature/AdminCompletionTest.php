<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\AssignmentStatus;
use App\Models\AuditLog;
use App\Models\InternshipAssignment;
use App\Models\Organization;
use App\Models\StudentGroup;
use App\Models\StudyYear;
use App\Models\SupervisorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

/**
 * Admin flows completed in the Phase 2 hardening pass: settings, course/group edits, supervisor detail and audits,
 * organization detail/status, internship dates, assignment date correction, dashboards.
 */
class AdminCompletionTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    public function test_settings_update_name_and_timezone_with_audit(): void
    {
        $world = $this->world();

        $this->actingAs($world['admin'])->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings')->where('university.timezone', 'Asia/Tashkent'));

        $this->actingAs($world['admin'])->put('/settings', ['name' => 'Yangi nom', 'timezone' => 'Asia/Samarkand'])->assertSessionHas('success');
        $this->assertSame('Asia/Samarkand', $world['university']->fresh()->timezone);
        $log = AuditLog::query()->where('action', 'university.update')->sole();
        $this->assertSame('university', $log->entity_type);
        $this->assertSame($world['university']->id, $log->university_id);

        $this->actingAs($world['admin'])->put('/settings', ['name' => 'Yangi nom', 'timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');
        $this->actingAs($world['admin'])->put('/settings', ['name' => 'Yangi nom', 'timezone' => 'Asia/Samarkand']);
        $this->assertSame(1, AuditLog::query()->where('action', 'university.update')->count());

        $this->actingAs($world['supervisor'])->get('/settings')->assertForbidden();
        $this->actingAs($world['supervisor'])->put('/settings', ['name' => 'X', 'timezone' => 'UTC'])->assertForbidden();
    }

    public function test_settings_only_change_the_admins_university(): void
    {
        $a = $this->world('A', 1000);
        $b = $this->world('B', 2000);

        $this->actingAs($a['admin'])->put('/settings', ['name' => 'Faqat A', 'timezone' => 'UTC']);

        $this->assertSame('Faqat A', $a['university']->fresh()->name);
        $this->assertNotSame('Faqat A', $b['university']->fresh()->name);
    }

    public function test_course_and_group_are_editable_but_foreign_ones_are_404(): void
    {
        $a = $this->world('A', 1000);
        $b = $this->world('B', 2000);
        $studyYear = StudyYear::query()->where('id', $a['group']->study_year_id)->sole();

        $this->actingAs($a['admin'])->put("/academic/study-years/{$studyYear->id}", ['course_number' => 3, 'name' => '3-kurs'])->assertSessionHas('success');
        $this->assertSame(3, $studyYear->fresh()->course_number);

        $this->actingAs($a['admin'])->put("/academic/groups/{$a['group']->id}", ['name' => '401-guruh', 'code' => '401'])->assertSessionHas('success');
        $this->assertSame('401-guruh', $a['group']->fresh()->name);

        $this->actingAs($a['admin'])->put("/academic/study-years/{$b['group']->study_year_id}", ['course_number' => 2, 'name' => '2-kurs'])->assertNotFound();
        $this->actingAs($a['admin'])->put("/academic/groups/{$b['group']->id}", ['name' => 'X', 'code' => 'X'])->assertNotFound();
        $this->assertSame('Guruh B', $b['group']->fresh()->name);

        $this->actingAs($a['supervisor'])->put("/academic/groups/{$a['group']->id}", ['name' => 'Y', 'code' => 'Y'])->assertForbidden();
    }

    public function test_group_name_stays_unique_within_its_course(): void
    {
        $world = $this->world();
        StudentGroup::query()->create(['study_year_id' => $world['group']->study_year_id, 'name' => 'Ikkinchi', 'code' => 'B2']);

        $this->actingAs($world['admin'])->put("/academic/groups/{$world['group']->id}", ['name' => 'Ikkinchi', 'code' => 'A1'])->assertSessionHasErrors('name');
        $this->assertSame('Guruh A', $world['group']->fresh()->name);
    }

    public function test_supervisor_create_update_and_status_are_audited(): void
    {
        $world = $this->world();
        $admin = $world['admin'];

        $this->actingAs($admin)->post('/supervisors', [
            'name' => 'Karimov Bekzod', 'login' => 'karimov', 'email' => '', 'password' => 'secret-pass-1', 'phone' => '+998901234567', 'position' => 'Dotsent',
        ])->assertSessionHas('success');
        $profile = SupervisorProfile::query()->whereHas('user', fn ($q) => $q->where('login', 'karimov'))->sole();

        $this->actingAs($admin)->put("/supervisors/{$profile->id}", [
            'name' => 'Karimov Bekzod', 'login' => 'karimov', 'email' => '', 'password' => '', 'phone' => '+998901234567', 'position' => 'Professor',
        ])->assertSessionHas('success');
        $this->actingAs($admin)->patch("/supervisors/{$profile->id}/status", ['status' => 'INACTIVE'])->assertSessionHas('success');

        $actions = AuditLog::query()->where('entity_type', 'supervisor_profile')->where('entity_id', $profile->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['supervisor.create', 'supervisor.update', 'supervisor.status_change'], $actions);
        $update = AuditLog::query()->where('action', 'supervisor.update')->sole();
        $this->assertSame('Dotsent', $update->before['position']);
        $this->assertSame('Professor', $update->after['position']);
        $this->assertArrayNotHasKey('password', $update->after);
        $this->assertSame(ActiveStatus::Inactive, $profile->user->fresh()->status);
    }

    public function test_supervisor_detail_lists_periods_students_and_history(): void
    {
        $a = $this->world('A', 1000);
        $b = $this->world('B', 2000);

        $this->actingAs($a['admin'])->get("/supervisors/{$a['profile']->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Supervisors/Show')
                ->where('supervisor.name', 'Rahbar')
                ->where('supervisor.open_students', 2)
                ->has('periods', 1)
                ->where('periods.0.ends_on', null)
                ->where('periods.0.participants_count', 2)
                ->has('history'));

        $this->actingAs($a['admin'])->get("/supervisors/{$b['profile']->id}")->assertNotFound();
        $this->actingAs($a['supervisor'])->get("/supervisors/{$a['profile']->id}")->assertForbidden();
    }

    public function test_inactive_supervisor_cannot_sign_in_or_keep_a_session(): void
    {
        $world = $this->world();
        $world['supervisor']->update(['login' => 'rahbar-x', 'password' => 'secret-pass-1']);

        $this->actingAs($world['admin'])->patch("/supervisors/{$world['profile']->id}/status", ['status' => 'INACTIVE']);

        $this->actingAs($world['supervisor']->fresh())->get('/my-students')->assertRedirect('/login');
        auth()->logout();
        $this->post('/login', ['login' => 'rahbar-x', 'password' => 'secret-pass-1']);
        $this->assertGuest();
    }

    public function test_organization_detail_and_status_endpoint_with_audit(): void
    {
        $a = $this->world('A', 1000);
        $b = $this->world('B', 2000);
        $this->placement($a['internship'], $a['students'][0], $a['org']);

        $this->actingAs($a['admin'])->get("/organizations/{$a['org']->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Organizations/Show')
                ->where('organization.name', 'Sud')
                ->where('organization.radius_meters', 100)
                ->has('assignments.data', 1)
                ->where('assignments.data.0.student', 'Aliyev Talaba'));

        $this->actingAs($a['admin'])->patch("/organizations/{$a['org']->id}/status", ['status' => 'INACTIVE'])->assertSessionHas('success');
        $this->assertSame(ActiveStatus::Inactive, $a['org']->fresh()->status);
        $this->assertSame(AssignmentStatus::Active, InternshipAssignment::query()->sole()->status);
        $log = AuditLog::query()->where('action', 'organization.status_change')->sole();
        $this->assertSame(['status' => 'ACTIVE'], $log->before);
        $this->assertSame(['status' => 'INACTIVE'], $log->after);

        $this->actingAs($a['admin'])->get("/organizations/{$b['org']->id}")->assertNotFound();
        $this->actingAs($a['admin'])->patch("/organizations/{$b['org']->id}/status", ['status' => 'INACTIVE'])->assertNotFound();
        $this->assertSame(ActiveStatus::Active, $b['org']->fresh()->status);
        $this->actingAs($a['supervisor'])->get("/organizations/{$a['org']->id}")->assertForbidden();
        $this->actingAs($a['supervisor'])->patch("/organizations/{$a['org']->id}/status", ['status' => 'ACTIVE'])->assertForbidden();
        $this->assertSame(2 + 2, Organization::query()->count());
    }

    public function test_internship_dates_are_editable_with_audit(): void
    {
        $a = $this->world('A', 1000);
        $b = $this->world('B', 2000);
        $id = $a['internship']->id;

        $this->actingAs($a['admin'])->put("/internships/{$id}", ['period_start' => '2026-11-01', 'period_end' => '2026-10-01'])->assertSessionHasErrors('period_end');
        $this->actingAs($a['admin'])->put("/internships/{$id}", ['period_start' => '2026-11-01', 'period_end' => '2026-12-31'])->assertSessionHas('success');

        $this->assertSame('2026-12-31', $a['internship']->fresh()->period_end->toDateString());
        $log = AuditLog::query()->where('action', 'internship.update')->sole();
        $this->assertSame('2026-12-31', $log->after['period_end']);

        $this->actingAs($a['admin'])->put("/internships/{$b['internship']->id}", ['period_start' => '2026-11-01', 'period_end' => '2026-12-31'])->assertNotFound();
        $this->actingAs($a['supervisor'])->put("/internships/{$id}", ['period_start' => '2026-11-01', 'period_end' => '2026-12-31'])->assertForbidden();
    }

    public function test_pending_assignment_dates_are_editable_and_activate_when_start_arrives(): void
    {
        $world = $this->world();
        $assignment = $this->placement($world['internship'], $world['students'][0], $world['org'], AssignmentStatus::Pending);
        $assignment->update(['start_at' => now()->addDays(5), 'end_at' => now()->addDays(40)]);
        $today = now('Asia/Tashkent');

        $this->actingAs($world['admin'])->put("/assignments/{$assignment->id}", [
            'start_date' => $today->copy()->addDays(10)->toDateString(),
            'end_date' => $today->copy()->addDays(9)->toDateString(),
        ])->assertSessionHasErrors('end_date');

        $this->actingAs($world['admin'])->put("/assignments/{$assignment->id}", [
            'start_date' => $today->copy()->addDays(10)->toDateString(),
            'end_date' => $today->copy()->addDays(50)->toDateString(),
        ])->assertSessionHas('success');
        $this->assertSame(AssignmentStatus::Pending, $assignment->fresh()->status);
        $this->assertSame($today->copy()->addDays(10)->toDateString(), $assignment->fresh()->start_at->setTimezone('Asia/Tashkent')->toDateString());

        $this->actingAs($world['admin'])->put("/assignments/{$assignment->id}", [
            'start_date' => $today->toDateString(),
            'end_date' => $today->copy()->addDays(50)->toDateString(),
        ])->assertSessionHas('success');
        $this->assertSame(AssignmentStatus::Active, $assignment->fresh()->status);
        $this->assertSame(2, AuditLog::query()->where('action', 'assignment.update')->count());
    }

    public function test_active_assignment_only_moves_its_end_and_history_is_frozen(): void
    {
        $world = $this->world();
        $assignment = $this->placement($world['internship'], $world['students'][0], $world['org']);
        $today = now('Asia/Tashkent');

        $this->actingAs($world['admin'])->put("/assignments/{$assignment->id}", [
            'start_date' => $today->copy()->subDays(20)->toDateString(),
            'end_date' => $today->copy()->addDays(60)->toDateString(),
        ])->assertSessionHas('error');

        $this->actingAs($world['admin'])->put("/assignments/{$assignment->id}", ['end_date' => $today->copy()->subDay()->toDateString()])->assertSessionHasErrors('end_date');

        $this->actingAs($world['admin'])->put("/assignments/{$assignment->id}", ['end_date' => $today->copy()->addDays(60)->toDateString()])->assertSessionHas('success');
        $log = AuditLog::query()->where('action', 'assignment.update')->sole();
        $this->assertNotSame($log->before['end_at'], $log->after['end_at']);

        $this->actingAs($world['admin'])->post("/assignments/{$assignment->id}/end")->assertSessionHas('success');
        $this->actingAs($world['admin'])->put("/assignments/{$assignment->id}", ['end_date' => $today->copy()->addDays(90)->toDateString()])->assertSessionHas('error');
        $this->assertSame(1, AuditLog::query()->where('action', 'assignment.update')->count());
    }

    public function test_assignment_update_is_admin_only_and_university_scoped(): void
    {
        $a = $this->world('A', 1000);
        $b = $this->world('B', 2000);
        $own = $this->placement($a['internship'], $a['students'][0], $a['org']);
        $foreign = $this->placement($b['internship'], $b['students'][0], $b['org']);
        $end = now('Asia/Tashkent')->addDays(60)->toDateString();

        $this->actingAs($a['supervisor'])->put("/assignments/{$own->id}", ['end_date' => $end])->assertForbidden();
        $this->actingAs($a['admin'])->put("/assignments/{$foreign->id}", ['end_date' => $end])->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('action', 'assignment.update')->count());
    }

    public function test_admin_dashboard_counts_phase_two_entities_for_own_university_only(): void
    {
        $a = $this->world('A', 1000);
        $this->world('B', 2000);
        $this->placement($a['internship'], $a['students'][0], $a['org']);

        $this->actingAs($a['admin'])->get('/dashboard')->assertInertia(function ($page) {
            $stats = collect($page->toArray()['props']['stats'])->pluck('value', 'label')->all();
            $this->assertSame(2, $stats['Talabalar']);
            $this->assertSame(1, $stats['Faol amaliyotlar']);
            $this->assertSame(1, $stats['Faol biriktirishlar']);
            $this->assertSame(0, $stats['Kutilayotgan so‘rovlar']);
            $this->assertSame(2, $stats['Faol tashkilotlar']);
        });
    }

    public function test_supervisor_dashboard_counts_only_scoped_students(): void
    {
        $world = $this->world();
        $university = $world['university'];
        $other = $this->academicTree($university, 'C');
        $otherInternship = $this->internship($university, $other['group'], $this->supervisorProfile($university, 'Boshqa'));
        $outsider = $this->student($university, $other['group'], 3001, 'Begona');
        $this->enroll($otherInternship, $outsider);
        $this->placement($otherInternship, $outsider, $world['org']);
        $this->placement($world['internship'], $world['students'][0], $world['org']);

        $this->actingAs($world['supervisor'])->get('/dashboard')->assertInertia(function ($page) {
            $stats = collect($page->toArray()['props']['stats'])->pluck('value', 'label')->all();
            $this->assertSame(1, $stats['Guruhlarim']);
            $this->assertSame(2, $stats['Talabalar']);
            $this->assertSame(1, $stats['Faol biriktirishlar']);
            $this->assertSame(1, $stats['Biriktirilmagan']);
            $this->assertSame(0, $stats['Kutilayotgan so‘rovlar']);
        });
    }

    public function test_audit_log_filter_offers_new_entity_types(): void
    {
        $world = $this->world();

        $this->actingAs($world['admin'])->get('/audit-logs?entity=student_profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.entity', 'student_profile')->where('entities', fn ($entities) => collect($entities)->contains('supervisor_profile') && collect($entities)->contains('university')));
    }
}
