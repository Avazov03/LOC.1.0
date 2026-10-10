<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/academic/faculties')->assertRedirect('/login');
        $this->get('/my-groups')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_admin_can_log_in_and_open_the_dashboard(): void
    {
        User::factory()->create(['login' => 'admin-user']);

        $this->post('/login', ['login' => 'admin-user', 'password' => 'password'])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_supervisor_can_log_in(): void
    {
        User::factory()->supervisor()->create(['login' => 'rahbar-1']);

        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password'])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['login' => 'admin-user']);

        $this->post('/login', ['login' => 'admin-user', 'password' => 'wrong'])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_inactive_staff_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['login' => 'blocked']);
        User::factory()->supervisor()->inactive()->create(['login' => 'blocked-supervisor']);

        $this->post('/login', ['login' => 'blocked', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'blocked-supervisor', 'password' => 'password'])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_student_cannot_log_in_to_the_web_panel_even_with_a_password(): void
    {
        User::factory()->create(['login' => 'talaba', 'role' => UserRole::Student]);

        $this->post('/login', ['login' => 'talaba', 'password' => 'password'])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_email_is_not_a_login_credential(): void
    {
        User::factory()->create(['login' => 'admin-user', 'email' => 'admin@example.test']);

        $this->post('/login', ['login' => 'admin@example.test', 'password' => 'password'])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->create(['login' => 'admin-user']);

        for ($attempt = 0; $attempt < LoginRequest::MAX_ATTEMPTS; $attempt++) {
            $this->post('/login', ['login' => 'admin-user', 'password' => 'wrong']);
        }

        $this->post('/login', ['login' => 'admin-user', 'password' => 'password'])->assertSessionHasErrors('login');

        $this->assertStringStartsWith('Juda ko‘p urinish.', session('errors')->first('login'));
        $this->assertGuest();
    }

    public function test_staff_deactivated_mid_session_is_signed_out(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $admin->update(['status' => ActiveStatus::Inactive]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_change_elsewhere_signs_out_other_sessions(): void
    {
        $supervisor = User::factory()->supervisor()->create(['login' => 'rahbar-1']);
        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        User::query()->findOrFail($supervisor->id)->forceFill(['password' => 'reset-by-admin-1'])->save();
        $this->app['auth']->forgetGuards();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_own_password_change_keeps_the_current_session(): void
    {
        User::factory()->supervisor()->create(['login' => 'rahbar-1']);
        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password']);

        $this->from('/profile')->put('/profile/password', [
            'current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();

        $this->get('/dashboard')->assertOk();
    }

    public function test_pages_send_security_headers_and_the_inline_script_carries_the_nonce(): void
    {
        $response = $this->get('/login')->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $nonce);
        $this->assertStringContainsString('<script nonce="'.$nonce[1].'">', $response->getContent());
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString('geolocation=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'));

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_logout_ends_the_session(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }
}
