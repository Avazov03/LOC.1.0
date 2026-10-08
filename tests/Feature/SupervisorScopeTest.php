<?php

namespace Tests\Feature;

use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAcademicTree;
use Tests\TestCase;

class SupervisorScopeTest extends TestCase
{
    use BuildsAcademicTree;
    use RefreshDatabase;

    public function test_supervisor_dashboard_carries_no_university_wide_counts(): void
    {
        $university = University::factory()->create();
        $tree = $this->academicTree($university);
        $this->student($university, $tree['group'], 7001, 'Aliyev');
        $supervisor = User::factory()->supervisor()->create(['university_id' => $university->id]);

        $this->actingAs($supervisor)->get('/dashboard')
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Dashboard');
                $values = collect($page->toArray()['props']['stats'])->pluck('value')->unique()->values()->all();
                $this->assertSame([0], $values);
            });
    }

    public function test_supervisor_group_page_lists_nothing_before_internships_exist(): void
    {
        $university = University::factory()->create();
        $this->academicTree($university);
        $supervisor = User::factory()->supervisor()->create(['university_id' => $university->id]);

        $this->actingAs($supervisor)->get('/my-groups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Supervisor/Groups')->missing('groups')->missing('students'));
    }

    public function test_supervisor_cannot_read_student_rows_through_admin_routes(): void
    {
        $university = University::factory()->create();
        $tree = $this->academicTree($university);
        $this->student($university, $tree['group'], 7002, 'Aliyev');
        $supervisor = User::factory()->supervisor()->create(['university_id' => $university->id]);

        $this->actingAs($supervisor)->get('/academic/students')->assertForbidden();
        $this->actingAs($supervisor)->get('/academic/groups')->assertForbidden();
    }

    public function test_shared_props_expose_only_the_signed_in_user(): void
    {
        $university = University::factory()->create(['name' => 'Demo']);
        $supervisor = User::factory()->supervisor()->create(['university_id' => $university->id, 'name' => 'Rahbar']);
        User::factory()->create(['university_id' => $university->id, 'name' => 'Admin']);

        $this->actingAs($supervisor)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.name', 'Rahbar')
                ->where('auth.user.role', 'SUPERVISOR')
                ->where('auth.user.university', 'Demo')
                ->missing('auth.user.email')
                ->missing('auth.user.password'));
    }
}
