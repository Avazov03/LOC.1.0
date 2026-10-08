<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'faculties index' => ['get', '/academic/faculties'],
            'faculty store' => ['post', '/academic/faculties'],
            'faculty update' => ['put', '/academic/faculties/1'],
            'programs index' => ['get', '/academic/programs'],
            'program store' => ['post', '/academic/programs'],
            'program update' => ['put', '/academic/programs/1'],
            'years index' => ['get', '/academic/years'],
            'year store' => ['post', '/academic/years'],
            'year update' => ['put', '/academic/years/1'],
            'study years index' => ['get', '/academic/study-years'],
            'study year store' => ['post', '/academic/study-years'],
            'groups index' => ['get', '/academic/groups'],
            'group store' => ['post', '/academic/groups'],
            'students index' => ['get', '/academic/students'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_supervisor_is_forbidden_from_every_admin_route(string $method, string $uri): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($supervisor)->{$method}($uri, ['name' => 'X'])->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_student_is_forbidden_from_every_admin_route(string $method, string $uri): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'password' => null]);

        $this->actingAs($student)->{$method}($uri, ['name' => 'X'])->assertForbidden();
    }

    public function test_admin_is_forbidden_from_the_supervisor_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/my-groups')->assertForbidden();
    }

    public function test_student_has_no_web_home(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'password' => null]);

        $this->actingAs($student)->get('/dashboard')->assertForbidden();
        $this->actingAs($student)->get('/my-groups')->assertForbidden();
    }

    public function test_supervisor_navigation_has_no_admin_links(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($supervisor)
            ->get('/dashboard')
            ->assertInertia(function ($page) {
                $hrefs = collect($page->toArray()['props']['navigation'])->pluck('href')->filter()->values()->all();
                $this->assertSame(['/dashboard', '/my-groups', '/my-students', '/attendance', '/reports', '/change-requests'], $hrefs);
            });
    }
}
