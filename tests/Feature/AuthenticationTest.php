<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_can_log_in_and_open_the_dashboard(): void
    {
        $admin = User::factory()->create(['login' => 'admin-user']);

        $this->post('/login', [
            'login' => 'admin-user',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['login' => 'blocked']);

        $this->post('/login', [
            'login' => 'blocked',
            'password' => 'password',
        ])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_supervisor_cannot_open_academic_admin_pages(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($supervisor)
            ->get('/academic/faculties')
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->post('/academic/faculties', ['name' => 'Yuridik'])
            ->assertForbidden();
    }

    public function test_admin_cannot_open_the_supervisor_group_page(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/my-groups')->assertForbidden();
    }
}
