<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\InternshipSupervisorPeriod;
use App\Models\SupervisorProfile;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class InternshipManagementTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    public function test_admin_creates_and_manages_supervisors(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/supervisors', [
            'name' => 'Karimova Dilnoza',
            'login' => 'dilnoza',
            'email' => 'd@example.test',
            'password' => 'secret-pass',
            'phone' => '+998901112233',
            'position' => 'Dotsent',
        ])->assertSessionHas('success');

        $profile = SupervisorProfile::query()->with('user')->sole();
        $this->assertSame('SUPERVISOR', $profile->user->role->value);
        $this->assertSame($admin->university_id, $profile->university_id);

        $this->actingAs($admin)->patch("/supervisors/{$profile->id}/status", ['status' => 'INACTIVE'])->assertSessionHas('success');
        $this->assertSame('INACTIVE', $profile->user->fresh()->status->value);

        $this->post('/logout');
        $this->post('/login', ['login' => 'dilnoza', 'password' => 'secret-pass'])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_admin_creates_an_internship_with_an_open_supervisor_period(): void
    {
        $university = University::factory()->create();
        $admin = User::factory()->create(['university_id' => $university->id]);
        $tree = $this->academicTree($university);
        $profile = $this->supervisorProfile($university);

        $this->actingAs($admin)->post('/internships', [
            'student_group_id' => $tree['group']->id,
            'supervisor_profile_id' => $profile->id,
            'period_start' => '2026-11-01',
            'period_end' => '2026-12-31',
        ])->assertRedirect();

        $internship = Internship::query()->sole();
        $this->assertSame($tree['year']->id, $internship->academic_year_id);
        $period = InternshipSupervisorPeriod::query()->sole();
        $this->assertNull($period->ends_on);
        $this->assertSame($profile->id, $period->supervisor_profile_id);
        $this->assertTrue(AuditLog::query()->where('action', 'internship.create')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'internship.supervisor_assign')->exists());

        $this->actingAs($admin)->post('/internships', [
            'student_group_id' => $tree['group']->id,
            'supervisor_profile_id' => $profile->id,
            'period_start' => '2026-12-31',
            'period_end' => '2026-11-01',
        ])->assertSessionHasErrors('period_end');
    }

    public function test_inactive_or_foreign_supervisor_cannot_be_attached(): void
    {
        $world = $this->world();
        $foreign = $this->supervisorProfile(University::factory()->create());
        $world['supervisor']->update(['status' => 'INACTIVE']);

        foreach ([$world['profile']->id, $foreign->id] as $profileId) {
            $this->actingAs($world['admin'])->post('/internships', [
                'student_group_id' => $world['group']->id,
                'supervisor_profile_id' => $profileId,
                'period_start' => '2026-11-01',
                'period_end' => '2026-12-31',
            ])->assertSessionHasErrors('supervisor_profile_id');
        }

        $this->assertSame(1, Internship::query()->count());
    }

    public function test_supervisor_replacement_keeps_history_and_moves_access(): void
    {
        $world = $this->world();
        $old = $world['supervisor'];
        $newProfile = $this->supervisorProfile($world['university'], 'Yangi rahbar');
        $placement = $this->placement($world['internship'], $world['students'][0], $world['org']);

        $this->actingAs($world['admin'])->post('/internships/'.$world['internship']->id.'/supervisor', ['supervisor_profile_id' => $world['profile']->id])
            ->assertSessionHas('error');
        $this->actingAs($world['admin'])->post('/internships/'.$world['internship']->id.'/supervisor', ['supervisor_profile_id' => $newProfile->id])
            ->assertSessionHas('success');

        $periods = InternshipSupervisorPeriod::query()->orderBy('id')->get();
        $this->assertCount(2, $periods);
        $this->assertNotNull($periods[0]->ends_on);
        $this->assertNull($periods[1]->ends_on);
        $this->assertSame($newProfile->id, $periods[1]->supervisor_profile_id);
        $this->assertSame($world['profile']->id, $placement->fresh()->supervisor_profile_id, 'History keeps the supervisor snapshot.');
        $this->assertTrue(AuditLog::query()->where('action', 'internship.supervisor_replace')->exists());

        $this->actingAs($old)->get('/my-groups/'.$world['internship']->id)->assertNotFound();
        $this->actingAs($old)->get('/students/'.$world['students'][0]->id)->assertNotFound();
        $this->actingAs($newProfile->user)->get('/my-groups/'.$world['internship']->id)->assertOk();
    }

    public function test_one_open_period_per_internship_is_enforced_by_the_database(): void
    {
        $world = $this->world();

        $this->expectException(QueryException::class);
        InternshipSupervisorPeriod::query()->create([
            'internship_id' => $world['internship']->id,
            'supervisor_profile_id' => $this->supervisorProfile($world['university'], 'Ikkinchi')->id,
            'starts_on' => now()->toDateString(),
            'created_by' => $world['admin']->id,
        ]);
    }

    public function test_overlapping_closed_periods_are_excluded_by_postgres(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('PostgreSQL EXCLUDE constraint. Run composer test:pgsql.');
        }
        $world = $this->world();
        $other = $this->supervisorProfile($world['university'], 'Ikkinchi');
        DB::table('internship_supervisor_periods')->where('internship_id', $world['internship']->id)->update(['ends_on' => now()->addDays(10)->toDateString()]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('internship_supervisor_periods_no_overlap');
        InternshipSupervisorPeriod::query()->create([
            'internship_id' => $world['internship']->id,
            'supervisor_profile_id' => $other->id,
            'starts_on' => now()->addDays(5)->toDateString(),
            'ends_on' => now()->addDays(20)->toDateString(),
            'created_by' => $world['admin']->id,
        ]);
    }

    public function test_internship_page_shows_participants_and_history(): void
    {
        $world = $this->world();
        $this->placement($world['internship'], $world['students'][0], $world['org']);

        $this->actingAs($world['admin'])->get('/internships/'.$world['internship']->id)
            ->assertInertia(fn ($page) => $page->component('Internships/Show')
                ->has('participants', 2)
                ->has('history', 1)
                ->has('periods', 1)
                ->where('participants.0.assignment.organization', 'Sud'));

        $this->assertSame(1, InternshipAssignment::query()->count());
    }
}
