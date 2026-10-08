<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\AssignmentStatus;
use App\Models\AuditLog;
use App\Models\InternshipAssignment;
use App\Services\Assignments\InternshipAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    public function test_admin_bulk_assigns_many_students_to_one_organization(): void
    {
        $world = $this->world();
        $ids = array_map(fn ($s) => $s->id, $world['students']);

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org'], $ids))
            ->assertRedirect()
            ->assertSessionHas('success');

        $rows = InternshipAssignment::query()->orderBy('student_profile_id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(AssignmentStatus::Active, $row->status);
            $this->assertSame($world['org']->id, $row->organization_id);
            $this->assertSame($world['profile']->id, $row->supervisor_profile_id);
            $this->assertSame($world['admin']->id, $row->created_by);
        }
        $this->assertSame(2, AuditLog::query()->where('action', 'assignment.create')->count());
    }

    public function test_one_group_can_be_spread_across_several_organizations(): void
    {
        $world = $this->world();
        [$first, $second] = $world['students'];

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org'], [$first->id]));
        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org2'], [$second->id]));

        $this->assertSame($world['org']->id, $first->openAssignment()->value('organization_id'));
        $this->assertSame($world['org2']->id, $second->openAssignment()->value('organization_id'));
    }

    public function test_future_start_stays_pending_and_the_scheduler_activates_it_when_due(): void
    {
        $world = $this->world();
        $student = $world['students'][0];

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org'], [$student->id], 3));
        $assignment = InternshipAssignment::query()->sole();
        $this->assertSame(AssignmentStatus::Pending, $assignment->status);

        $this->artisan('assignments:activate-due')->assertSuccessful();
        $this->assertSame(AssignmentStatus::Pending, $assignment->fresh()->status);

        $this->travel(4)->days();
        $this->artisan('assignments:activate-due')->assertSuccessful();
        $this->assertSame(AssignmentStatus::Active, $assignment->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'assignment.activate')->whereNull('actor_user_id')->exists());
    }

    public function test_d2_second_open_assignment_is_refused_and_the_first_is_untouched(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $first = $this->placement($world['internship'], $student, $world['org']);

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org2'], [$student->id]))
            ->assertSessionHas('error')
            ->assertSessionHas('assignment_results', fn ($results) => $results[0]['error'] === InternshipAssignmentService::MSG_CONFLICT);

        $this->assertSame(1, InternshipAssignment::query()->count());
        $this->assertSame(AssignmentStatus::Active, $first->fresh()->status);
        $this->assertSame($world['org']->id, $first->fresh()->organization_id);
    }

    public function test_d2_database_backstop_rejects_a_second_open_row(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->placement($world['internship'], $student, $world['org']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->placement($world['internship'], $student, $world['org2'], AssignmentStatus::Pending);
    }

    public function test_ended_and_cancelled_rows_do_not_block_a_new_assignment(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->placement($world['internship'], $student, $world['org'], AssignmentStatus::Ended);
        $this->placement($world['internship'], $student, $world['org'], AssignmentStatus::Cancelled);

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org2'], [$student->id]))->assertSessionHas('success');

        $this->assertSame(3, InternshipAssignment::query()->where('student_profile_id', $student->id)->count());
    }

    public function test_bulk_with_mixed_rows_reports_each_student(): void
    {
        $world = $this->world();
        [$free, $busy] = $world['students'];
        $this->placement($world['internship'], $busy, $world['org']);
        $outsider = $this->student($world['university'], $world['group'], 5555, 'Tashqi');

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org2'], [$free->id, $busy->id, $outsider->id]))
            ->assertSessionHas('error', '1 ta biriktirildi, 2 ta rad etildi.')
            ->assertSessionHas('assignment_results', function (array $results) use ($free, $busy, $outsider) {
                $byStudent = collect($results)->keyBy('student_id');

                return $byStudent[$free->id]['error'] === null
                    && $byStudent[$busy->id]['error'] === InternshipAssignmentService::MSG_CONFLICT
                    && $byStudent[$outsider->id]['error'] === InternshipAssignmentService::MSG_NOT_PARTICIPANT;
            });

        $this->assertSame($world['org2']->id, $free->openAssignment()->value('organization_id'));
        $this->assertSame(0, InternshipAssignment::query()->where('student_profile_id', $outsider->id)->count());
    }

    public function test_inactive_organization_is_refused(): void
    {
        $world = $this->world();
        $closed = $this->organization($world['university'], 'Yopiq', ActiveStatus::Inactive);

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $closed, [$world['students'][0]->id]))
            ->assertSessionHas('error', InternshipAssignmentService::MSG_ORGANIZATION_INACTIVE);

        $this->assertSame(0, InternshipAssignment::query()->count());
    }

    public function test_inactive_organization_is_refused_by_the_database_trigger(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('PostgreSQL trigger. Run composer test:pgsql.');
        }
        $world = $this->world();
        $closed = $this->organization($world['university'], 'Yopiq', ActiveStatus::Inactive);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('is not ACTIVE');
        $this->placement($world['internship'], $world['students'][0], $closed);
    }

    public function test_batch_size_is_capped(): void
    {
        $world = $this->world();

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org'], range(1, InternshipAssignmentService::MAX_BATCH + 1)))
            ->assertSessionHasErrors('student_ids');
        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org'], []))
            ->assertSessionHasErrors('student_ids');
    }

    public function test_bulk_assignment_runs_a_constant_number_of_preload_queries(): void
    {
        $world = $this->world();
        $students = [];
        for ($i = 0; $i < 30; $i++) {
            $students[] = $this->student($world['university'], $world['group'], 7000 + $i, "Talaba{$i}");
        }
        $this->enroll($world['internship'], ...$students);
        $ids = array_map(fn ($s) => $s->id, $students);
        Queue::fake();

        DB::enableQueryLog();
        $results = app(InternshipAssignmentService::class)->assign(
            $world['admin'], $ids, $world['org']->id, $world['internship']->id, now()->subMinute(), now()->addDays(10),
        );
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(30, array_filter($results, fn ($row) => $row['error'] === null));
        // Per student: lock, re-check, insert, activate, audit, notification row (+ savepoints). Bounded per row, no lookups that grow with the batch.
        $this->assertLessThan(30 * 12, $queries);
    }

    public function test_d5_supervisor_assigns_only_own_participants_to_active_organizations(): void
    {
        $world = $this->world();
        $supervisor = $world['supervisor'];
        $student = $world['students'][0];

        $this->actingAs($supervisor)->post('/assignments', $this->assignPayload($world['internship'], $world['org'], [$student->id]))->assertSessionHas('success');
        $this->assertSame($supervisor->id, InternshipAssignment::query()->sole()->created_by);

        // A student of the same university who is not in the supervisor's internship.
        $outsider = $this->student($world['university'], $world['group'], 8888, 'Begona');
        $this->actingAs($supervisor)->post('/assignments', $this->assignPayload($world['internship'], $world['org'], [$outsider->id]))
            ->assertSessionHas('assignment_results', fn ($results) => $results[0]['error'] === InternshipAssignmentService::MSG_NOT_PARTICIPANT);

        // Another supervisor's internship is not visible at all.
        $otherProfile = $this->supervisorProfile($world['university'], 'Boshqa');
        $tree = $this->academicTree($world['university'], 'D');
        $otherInternship = $this->internship($world['university'], $tree['group'], $otherProfile);
        $theirStudent = $this->student($world['university'], $tree['group'], 9999, 'Ularniki');
        $this->enroll($otherInternship, $theirStudent);
        $this->actingAs($supervisor)->post('/assignments', $this->assignPayload($otherInternship, $world['org'], [$theirStudent->id]))->assertNotFound();

        $closed = $this->organization($world['university'], 'Yopiq', ActiveStatus::Inactive);
        $this->actingAs($supervisor)->post('/assignments', $this->assignPayload($world['internship'], $closed, [$world['students'][1]->id]))
            ->assertSessionHas('error', InternshipAssignmentService::MSG_ORGANIZATION_INACTIVE);

        $this->assertSame(1, InternshipAssignment::query()->count());
    }

    public function test_admin_must_also_pick_a_participant_of_the_chosen_internship(): void
    {
        $world = $this->world();
        $stranger = $this->student($world['university'], $world['group'], 4444, 'Notanish');

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org'], [$stranger->id]))
            ->assertSessionHas('assignment_results', fn ($results) => $results[0]['error'] === InternshipAssignmentService::MSG_NOT_PARTICIPANT);
        $this->assertSame(0, InternshipAssignment::query()->count());
    }

    public function test_state_transitions_are_admin_only_and_keep_history(): void
    {
        $world = $this->world();
        [$first, $second] = $world['students'];
        $active = $this->placement($world['internship'], $first, $world['org']);

        $this->actingAs($world['admin'])->post("/assignments/{$active->id}/activate")->assertSessionHas('error');
        $this->actingAs($world['admin'])->post("/assignments/{$active->id}/end")->assertSessionHas('success');
        $active->refresh();
        $this->assertSame(AssignmentStatus::Ended, $active->status);
        $this->assertNotNull($active->ended_at);
        $this->actingAs($world['admin'])->post("/assignments/{$active->id}/end")->assertSessionHas('error');
        $this->actingAs($world['admin'])->post("/assignments/{$active->id}/cancel", ['reason' => 'x'])->assertSessionHas('error');

        $pending = InternshipAssignment::query()->create([
            ...$active->only(['organization_id', 'supervisor_profile_id', 'internship_id', 'created_by']),
            'student_profile_id' => $second->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(20),
            'status' => AssignmentStatus::Pending,
        ]);
        $this->actingAs($world['admin'])->post("/assignments/{$pending->id}/activate")->assertSessionHas('error');
        $this->actingAs($world['admin'])->post("/assignments/{$pending->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($world['admin'])->post("/assignments/{$pending->id}/cancel", ['reason' => 'Xato kiritilgan'])->assertSessionHas('success');
        $this->assertSame(AssignmentStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame('Xato kiritilgan', $pending->fresh()->cancel_reason);

        $this->assertSame(2, InternshipAssignment::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'assignment.end')->where('entity_id', $active->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'assignment.cancel')->where('reason', 'Xato kiritilgan')->exists());
    }
}
