<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Models\AuditLog;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    public function test_admin_views_as_supervisor_read_only_and_returns(): void
    {
        $university = University::factory()->create();
        $admin = User::factory()->create(['university_id' => $university->id, 'password' => 'admin-pass-123']);
        $profile = $this->supervisorProfile($university, 'Karimov Aziz');
        $supervisorPassword = $profile->user->password;

        $this->actingAs($admin)->post("/supervisors/{$profile->id}/impersonate")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($profile->user);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'auth.impersonate', 'entity_id' => $profile->user_id]);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.name', 'Karimov Aziz')
            ->where('auth.impersonating', true));
        $this->get('/my-groups')->assertOk();
        $this->get('/settings')->assertForbidden();

        $this->from('/profile')->put('/profile/password', [
            'current_password' => 'x', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123',
        ])->assertRedirect('/profile')->assertSessionHas('error');
        $this->post('/profile/telegram')->assertSessionHas('error');
        $this->assertSame($supervisorPassword, $profile->user->fresh()->password);
        $this->assertNull($profile->fresh()->telegram_link_hash);

        $this->post('/impersonate/leave')->assertRedirect("/supervisors/{$profile->id}");
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'auth.impersonate_end']);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('auth.impersonating', false));
        $this->get('/settings')->assertOk();
    }

    public function test_impersonation_does_not_count_as_supervisor_login(): void
    {
        $university = University::factory()->create();
        $admin = User::factory()->create(['university_id' => $university->id]);
        $profile = $this->supervisorProfile($university);

        $this->actingAs($admin)->post("/supervisors/{$profile->id}/impersonate");

        $this->assertSame(0, AuditLog::query()->where('action', 'auth.login')->where('entity_id', $profile->user_id)->count());
    }

    public function test_only_admins_of_the_same_university_can_impersonate_active_supervisors(): void
    {
        $university = University::factory()->create();
        $other = University::factory()->create();
        $admin = User::factory()->create(['university_id' => $university->id]);
        $foreign = $this->supervisorProfile($other);
        $inactive = $this->supervisorProfile($university, 'Nofaol');
        $inactive->user->update(['status' => ActiveStatus::Inactive]);
        $supervisor = $this->supervisorProfile($university);

        $this->actingAs($admin)->post("/supervisors/{$foreign->id}/impersonate")->assertNotFound();
        $this->assertAuthenticatedAs($admin);

        $this->actingAs($admin)->post("/supervisors/{$inactive->id}/impersonate")->assertSessionHas('error');
        $this->assertAuthenticatedAs($admin);

        $this->actingAs($supervisor->user)->post("/supervisors/{$inactive->id}/impersonate")->assertForbidden();
    }

    public function test_leave_without_an_impersonation_logs_out(): void
    {
        $university = University::factory()->create();
        $profile = $this->supervisorProfile($university);

        $this->actingAs($profile->user)->post('/impersonate/leave')->assertRedirect('/login');
        $this->assertGuest();
    }
}
