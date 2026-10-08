<?php

namespace Tests\Feature;

use App\Models\University;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationIntegrityTest extends TestCase
{
    private const TABLES = [
        'universities',
        'users',
        'faculties',
        'programs',
        'academic_years',
        'study_years',
        'student_groups',
        'student_profiles',
        'supervisor_profiles',
        'student_group_memberships',
        'audit_logs',
        'organizations',
        'internships',
        'internship_supervisor_periods',
        'internship_invites',
        'internship_participants',
        'internship_assignments',
        'internship_change_requests',
    ];

    public function test_migrations_roll_back_completely_and_reapply(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} missing after migrate");
        }

        Artisan::call('migrate:reset', ['--force' => true]);

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} survived rollback");
        }

        Artisan::call('migrate', ['--force' => true]);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} missing after re-migrate");
        }
    }

    public function test_user_without_a_university_is_rejected_by_the_database(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Orphan',
            'login' => 'orphan',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_university_with_users_cannot_be_deleted(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $university = University::factory()->create();
        DB::table('users')->insert([
            'university_id' => $university->id,
            'name' => 'Admin',
            'login' => 'admin',
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('universities')->where('id', $university->id)->delete();
    }

    public function test_postgres_constraints_and_partial_indexes_exist(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraints. Run composer test:pgsql.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        $constraints = collect(DB::select("SELECT conname FROM pg_constraint WHERE contype = 'c'"))->pluck('conname');
        $this->assertContains('academic_years_dates', $constraints);
        $this->assertContains('study_years_course_positive', $constraints);

        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'student_group_memberships_one_active'");
        $this->assertNotNull($index);
        $this->assertMatchesRegularExpression("/UNIQUE INDEX .* WHERE .*status.*'ACTIVE'/", $index->indexdef);

        $nullable = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'university_id'");
        $this->assertSame('NO', $nullable->is_nullable);

        foreach (['users_university_role_status_index', 'student_profiles_directory_index', 'student_group_memberships_history_index'] as $name) {
            $this->assertNotNull(DB::selectOne('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$name]), "{$name} missing");
        }
    }

    public function test_phase2_constraints_indexes_and_triggers_exist(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraints. Run composer test:pgsql.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        $constraints = collect(DB::select('SELECT conname FROM pg_constraint'))->pluck('conname');
        foreach ([
            'organizations_radius_range',
            'internships_period_order',
            'internship_supervisor_periods_order',
            'internship_supervisor_periods_no_overlap',
            'internship_assignments_range',
            'internship_change_requests_payload',
        ] as $name) {
            $this->assertContains($name, $constraints, "{$name} missing");
        }

        $partial = [
            'internship_assignments_one_open' => '/UNIQUE INDEX .* WHERE .*PENDING.*ACTIVE/',
            'internship_change_requests_one_pending' => '/UNIQUE INDEX .* WHERE .*PENDING/',
            'internship_supervisor_periods_one_open' => '/UNIQUE INDEX .* WHERE .*ends_on IS NULL/',
        ];
        foreach ($partial as $name => $pattern) {
            $index = DB::selectOne('SELECT indexdef FROM pg_indexes WHERE indexname = ?', [$name]);
            $this->assertNotNull($index, "{$name} missing");
            $this->assertMatchesRegularExpression($pattern, $index->indexdef);
        }

        $triggers = collect(DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal'))->pluck('tgname');
        $this->assertContains('audit_logs_append_only', $triggers);
        $this->assertContains('internship_assignments_active_organization', $triggers);

        $hashColumn = DB::selectOne("SELECT character_maximum_length AS len FROM information_schema.columns WHERE table_name = 'internship_invites' AND column_name = 'token_hash'");
        $this->assertSame(64, (int) $hashColumn->len);
        $this->assertNull(DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'internship_invites' AND column_name IN ('token', 'raw_token')"));
    }

    public function test_migrate_fresh_twice_is_idempotent_for_functions_and_extensions(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('internship_change_requests'));
    }
}
