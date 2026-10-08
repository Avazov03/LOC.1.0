<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    public function test_login_is_audited(): void
    {
        $admin = User::factory()->create(['login' => 'boss']);

        $this->post('/login', ['login' => 'boss', 'password' => 'password'])->assertRedirect('/dashboard');

        $row = AuditLog::query()->where('action', 'auth.login')->sole();
        $this->assertSame($admin->id, $row->actor_user_id);
        $this->assertSame($admin->university_id, $row->university_id);
    }

    public function test_audit_rows_cannot_be_updated_or_deleted(): void
    {
        $admin = User::factory()->create(['login' => 'boss']);
        $this->post('/login', ['login' => 'boss', 'password' => 'password']);
        $id = AuditLog::query()->value('id');

        // Savepoints keep the surrounding test transaction usable on PostgreSQL after the trigger raises.
        try {
            DB::transaction(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']));
            $this->fail('Update must be refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::transaction(fn () => DB::table('audit_logs')->where('id', $id)->delete());
            $this->fail('Delete must be refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('auth.login', AuditLog::query()->find($id)->action);
        $this->assertNotNull($admin);
    }

    public function test_eloquent_timestamps_are_stored_as_the_real_instant(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('timestamptz check. Run composer test:pgsql.');
        }
        User::factory()->create(['login' => 'boss']);
        $this->post('/login', ['login' => 'boss', 'password' => 'password']);

        $drift = (float) DB::selectOne('SELECT abs(EXTRACT(EPOCH FROM (now() - created_at))) AS s FROM audit_logs ORDER BY id DESC LIMIT 1')->s;
        $this->assertLessThan(60, $drift, 'Timestamps must not be shifted by the university offset.');
    }

    public function test_audit_page_is_scoped_to_the_admins_university(): void
    {
        $mine = $this->world('A', 1000);
        $theirs = $this->world('B', 2000);
        $this->actingAs($mine['admin'])->post('/organizations', [
            'name' => 'Mening', 'type' => 'Sud', 'address' => 'x', 'contact_name' => 'x', 'contact_phone' => 'x',
            'latitude' => 41.3, 'longitude' => 69.2, 'radius_meters' => 100,
        ]);
        $this->actingAs($theirs['admin'])->post('/organizations', [
            'name' => 'Ularning', 'type' => 'Sud', 'address' => 'x', 'contact_name' => 'x', 'contact_phone' => 'x',
            'latitude' => 41.3, 'longitude' => 69.2, 'radius_meters' => 100,
        ]);

        $this->actingAs($mine['admin'])->get('/audit-logs?entity=organization')
            ->assertInertia(fn ($page) => $page->component('AuditLogs/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.after.name', 'Mening'));
    }
}
