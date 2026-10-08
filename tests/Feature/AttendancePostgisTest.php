<?php

namespace Tests\Feature;

use App\Enums\VerificationStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\Organization;
use App\Services\Attendance\AttendanceOutcome;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\LocationInput;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

/**
 * Real PostGIS geography distance and two real PostgreSQL sessions. Run with composer test:pgsql.
 */
class AttendancePostgisTest extends TestCase
{
    use BuildsInternships;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->isPgsql()) {
            $this->markTestSkipped('Needs PostgreSQL + PostGIS. Run composer test:pgsql.');
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
     * A point $meters due east of the organization, computed by PostGIS on the spheroid.
     *
     * @return array{0: float, 1: float}
     */
    private function pointAt(Organization $organization, float $meters): array
    {
        $row = DB::selectOne(
            'SELECT ST_Y(p::geometry) AS lat, ST_X(p::geometry) AS lng FROM (SELECT ST_Project(location::geography, CAST(? AS double precision), radians(90.0))::geography AS p FROM organizations WHERE id = ?) x',
            [$meters, $organization->id],
        );

        return [(float) $row->lat, (float) $row->lng];
    }

    /**
     * @return array{0: string, 1: ?float}
     */
    private function attempt(array $world, float $meters, int $student = 0): array
    {
        [$lat, $lng] = $this->pointAt($world['org'], $meters);
        $outcome = app(AttendanceService::class)->checkIn($world['students'][$student], new LocationInput($lat, $lng, 5.0));
        $event = AttendanceEvent::query()->latest('id')->first();

        return [$outcome->code, $event?->distance_meters];
    }

    public function test_radius_boundary_99_100_101_meters(): void
    {
        $world = $this->world();
        $this->placement($world['internship'], $world['students'][0], $world['org']);
        $this->assertSame(100, (int) $world['org']->radius_meters);

        [$code, $distance] = $this->attempt($world, 101);
        $this->assertSame(AttendanceOutcome::OUTSIDE_RADIUS, $code);
        $this->assertEqualsWithDelta(101, $distance, 0.01);
        $this->assertSame(VerificationStatus::OutsideRadius, AttendanceEvent::query()->sole()->verification_status);

        // A geodesic projection round-tripped through coordinates lands within float error of the edge, so "100 m" is 0.1 mm inside it.
        [$code, $distance] = $this->attempt($world, 99.9999);
        $this->assertSame(AttendanceOutcome::CHECKED_IN, $code, 'The radius edge counts as inside (ST_DWithin is inclusive).');
        $this->assertEqualsWithDelta(100, $distance, 0.01);

        $this->placement($world['internship'], $world['students'][1], $world['org']);
        [$code, $distance] = $this->attempt($world, 99, 1);
        $this->assertSame(AttendanceOutcome::CHECKED_IN, $code);
        $this->assertEqualsWithDelta(99, $distance, 0.01);
    }

    public function test_concurrent_check_ins_create_exactly_one_open_session(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $assignment = $this->placement($world['internship'], $student, $world['org']);
        $second = DB::connection('pgsql_second');

        DB::beginTransaction();
        $this->assertSame(AttendanceOutcome::CHECKED_IN, app(AttendanceService::class)->checkIn($student, new LocationInput(41.3112, 69.2797, 5.0))->code);

        // The second writer waits on the student row lock held by the first transaction.
        $second->statement("SET lock_timeout = '500ms'");
        try {
            $second->table('student_profiles')->where('id', $student->id)->lockForUpdate()->first();
            $this->fail('The student row must be locked while the first check-in is in flight.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->getCode());
        }
        DB::commit();

        // Even without the lock, the partial unique index refuses a second OPEN session.
        try {
            $second->table('attendance_sessions')->insert([
                'student_profile_id' => $student->id,
                'assignment_id' => $assignment->id,
                'local_date' => now('Asia/Tashkent')->toDateString(),
                'status' => 'OPEN',
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A second OPEN session must be refused.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }
        $this->assertSame(1, AttendanceSession::query()->count());
    }

    public function test_events_are_immutable_in_postgres(): void
    {
        $world = $this->world();
        $this->placement($world['internship'], $world['students'][0], $world['org']);
        app(AttendanceService::class)->checkIn($world['students'][0], new LocationInput(41.3112, 69.2797, 5.0));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attendance_events is immutable');
        DB::table('attendance_events')->update(['distance_meters' => 1]);
    }
}
