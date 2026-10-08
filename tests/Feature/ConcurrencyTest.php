<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\InternshipAssignment;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use App\Services\Internships\InternshipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

/**
 * Two real PostgreSQL sessions. Needs committed rows, so it migrates instead of wrapping each test in a transaction.
 */
class ConcurrencyTest extends TestCase
{
    use BuildsInternships;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->isPgsql()) {
            $this->markTestSkipped('Needs two PostgreSQL sessions. Run composer test:pgsql.');
        }

        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        if ($this->isPgsql()) {
            DB::connection('pgsql_second')->rollBack(0);
            DB::purge('pgsql_second');
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $world, int $organizationId, string $status): array
    {
        return [
            'student_profile_id' => $world['students'][0]->id,
            'organization_id' => $organizationId,
            'supervisor_profile_id' => $world['profile']->id,
            'internship_id' => $world['internship']->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(30),
            'status' => $status,
            'created_by' => $world['admin']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function test_concurrent_open_assignments_for_one_student_cannot_both_commit(): void
    {
        $world = $this->world();
        $second = DB::connection('pgsql_second');

        DB::beginTransaction();
        DB::table('internship_assignments')->insert($this->row($world, $world['org']->id, 'ACTIVE'));

        // The second session waits on the first one's uncommitted unique-index entry instead of inserting a duplicate.
        $second->statement("SET lock_timeout = '500ms'");
        try {
            $second->table('internship_assignments')->insert($this->row($world, $world['org2']->id, 'PENDING'));
            $this->fail('Second open assignment must not be inserted while the first is in flight.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }

        DB::commit();

        $this->expectException(QueryException::class);
        $second->table('internship_assignments')->insert($this->row($world, $world['org2']->id, 'PENDING'));
    }

    public function test_student_row_lock_serialises_assignment_writers(): void
    {
        $world = $this->world();
        $second = DB::connection('pgsql_second');

        DB::beginTransaction();
        DB::table('student_profiles')->where('id', $world['students'][0]->id)->lockForUpdate()->first();

        try {
            $second->select('SELECT id FROM student_profiles WHERE id = ? FOR UPDATE NOWAIT', [$world['students'][0]->id]);
            $this->fail('Second writer must not get the student lock.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }

        DB::rollBack();
        $this->assertCount(1, $second->select('SELECT id FROM student_profiles WHERE id = ? FOR UPDATE NOWAIT', [$world['students'][0]->id]));
    }

    public function test_duplicate_onboarding_of_one_telegram_user_cannot_both_commit(): void
    {
        $world = $this->world();
        $second = DB::connection('pgsql_second');
        $row = fn (int $userId) => [
            'user_id' => $userId,
            'university_id' => $world['university']->id,
            'current_group_id' => $world['group']->id,
            'first_name' => 'Ikki',
            'last_name' => 'Marta',
            'phone' => '+998900009999',
            'telegram_user_id' => 777_000_111,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $userRow = fn (string $name) => ['university_id' => $world['university']->id, 'name' => $name, 'role' => 'STUDENT', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];
        $firstUser = DB::table('users')->insertGetId($userRow('Birinchi'));
        $secondUser = DB::table('users')->insertGetId($userRow('Ikkinchi'));

        DB::beginTransaction();
        DB::table('student_profiles')->insert($row($firstUser));

        $second->statement("SET lock_timeout = '500ms'");
        try {
            $second->table('student_profiles')->insert($row($secondUser));
            $this->fail('A second profile for the same Telegram user must wait for the first.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }

        DB::commit();

        try {
            $second->table('student_profiles')->insert($row($secondUser));
            $this->fail('After commit the duplicate must violate the unique index.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }
        $this->assertSame(1, DB::table('student_profiles')->where('telegram_user_id', 777_000_111)->count());
    }

    public function test_simultaneous_approval_of_one_change_request_is_serialised(): void
    {
        $world = $this->world();
        $current = $this->placement($world['internship'], $world['students'][0], $world['org']);
        $requestId = DB::table('internship_change_requests')->insertGetId([
            'student_profile_id' => $world['students'][0]->id,
            'current_assignment_id' => $current->id,
            'request_type' => 'EXISTING_ORGANIZATION',
            'requested_organization_id' => $world['org2']->id,
            'reason' => 'Yaqin',
            'status' => 'PENDING',
            'initiated_by' => $world['supervisor']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second = DB::connection('pgsql_second');
        $service = app(InternshipChangeRequestService::class);

        // Another reviewer holds the request row; this approval must not run alongside it.
        $second->beginTransaction();
        $second->select('SELECT id FROM internship_change_requests WHERE id = ? FOR UPDATE', [$requestId]);
        DB::statement("SET lock_timeout = '500ms'");
        try {
            $service->approveExisting($world['admin'], $requestId);
            $this->fail('Approval must wait for the other reviewer.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }
        $second->rollBack();
        DB::statement('SET lock_timeout = 0');

        $service->approveExisting($world['admin'], $requestId);
        try {
            $service->approveExisting($world['admin'], $requestId);
            $this->fail('A second approval must be refused.');
        } catch (BusinessRuleException) {
        }

        $this->assertSame(1, InternshipAssignment::query()->where('student_profile_id', $world['students'][0]->id)->whereIn('status', ['PENDING', 'ACTIVE'])->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'change_request.approve')->count());
    }

    public function test_concurrent_supervisor_replacement_keeps_one_open_period(): void
    {
        $world = $this->world();
        $first = $this->supervisorProfile($world['university'], 'Birinchi');
        $other = $this->supervisorProfile($world['university'], 'Ikkinchi');
        $second = DB::connection('pgsql_second');
        $internshipId = $world['internship']->id;

        // Session two is mid-replacement: it holds the internship row lock.
        $second->beginTransaction();
        $second->select('SELECT id FROM internships WHERE id = ? FOR UPDATE', [$internshipId]);
        DB::statement("SET lock_timeout = '500ms'");
        try {
            app(InternshipService::class)->replaceSupervisor($world['admin'], $internshipId, $first->id);
            $this->fail('Replacement must wait for the other session.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }
        $second->rollBack();
        DB::statement('SET lock_timeout = 0');

        // Even bypassing the service, the partial unique index refuses a second open period.
        try {
            DB::transaction(fn () => DB::table('internship_supervisor_periods')->insert([
                'internship_id' => $internshipId,
                'supervisor_profile_id' => $other->id,
                'starts_on' => now()->toDateString(),
                'created_by' => $world['admin']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->fail('Two open periods must not exist.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }

        app(InternshipService::class)->replaceSupervisor($world['admin'], $internshipId, $first->id);
        $this->assertSame(1, DB::table('internship_supervisor_periods')->where('internship_id', $internshipId)->whereNull('ends_on')->count());
        $this->assertSame($first->id, DB::table('internship_supervisor_periods')->where('internship_id', $internshipId)->whereNull('ends_on')->value('supervisor_profile_id'));
    }

    public function test_a_closed_organization_cannot_slip_in_after_the_service_check(): void
    {
        $world = $this->world();
        $second = DB::connection('pgsql_second');

        // Admin deactivates the organization in another session between the service check and the insert.
        $second->table('organizations')->where('id', $world['org']->id)->update(['status' => 'INACTIVE']);

        try {
            DB::table('internship_assignments')->insert($this->row($world, $world['org']->id, 'ACTIVE'));
            $this->fail('Trigger must refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('is not ACTIVE', $exception->getMessage());
        }

        $this->assertSame(0, InternshipAssignment::query()->where('status', AssignmentStatus::Active->value)->count());
    }
}
