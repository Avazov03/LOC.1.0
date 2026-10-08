<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Models\InternshipAssignment;
use App\Models\InternshipChangeRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

/**
 * Role matrix, university scope and supervisor scope for every Phase 2 route (PERMISSIONS.md).
 */
class Phase2AuthorizationTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function adminOnlyRoutes(): array
    {
        return [
            'supervisors index' => ['get', '/supervisors'],
            'supervisor store' => ['post', '/supervisors'],
            'supervisor update' => ['put', '/supervisors/1'],
            'supervisor status' => ['patch', '/supervisors/1/status'],
            'organizations index' => ['get', '/organizations'],
            'organization create form' => ['get', '/organizations/create'],
            'organization store' => ['post', '/organizations'],
            'organization edit' => ['get', '/organizations/1/edit'],
            'organization update' => ['put', '/organizations/1'],
            'internships index' => ['get', '/internships'],
            'internship store' => ['post', '/internships'],
            'internship show' => ['get', '/internships/1'],
            'supervisor replace' => ['post', '/internships/1/supervisor'],
            'invite store' => ['post', '/internships/1/invites'],
            'invite close' => ['post', '/invites/1/close'],
            'assignments index' => ['get', '/assignments'],
            'assignment activate' => ['post', '/assignments/1/activate'],
            'assignment end' => ['post', '/assignments/1/end'],
            'assignment cancel' => ['post', '/assignments/1/cancel'],
            'approve new form' => ['get', '/change-requests/1/approve-new'],
            'approve new' => ['post', '/change-requests/1/approve-new'],
            'change request cancel' => ['post', '/change-requests/1/cancel'],
            'audit logs' => ['get', '/audit-logs'],
        ];
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_supervisor_is_forbidden_from_admin_routes(string $method, string $uri): void
    {
        $world = $this->world();

        $this->actingAs($world['supervisor'])->{$method}($uri)->assertForbidden();
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_student_and_inactive_users_are_forbidden_from_admin_routes(string $method, string $uri): void
    {
        $world = $this->world();
        $studentUser = $world['students'][0]->user;
        $inactiveAdmin = User::factory()->inactive()->create(['university_id' => $world['university']->id]);

        $this->actingAs($studentUser)->{$method}($uri)->assertForbidden();
        $this->actingAs($inactiveAdmin)->{$method}($uri)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_student_has_no_staff_routes_at_all(): void
    {
        $world = $this->world();
        $studentUser = $world['students'][0]->user;

        $this->actingAs($studentUser)->get('/change-requests')->assertForbidden();
        $this->actingAs($studentUser)->post('/assignments', $this->assignPayload($world['internship'], $world['org'], [$world['students'][0]->id]))->assertForbidden();
        $this->actingAs($studentUser)->get('/my-groups')->assertForbidden();
        $this->assertSame(0, InternshipAssignment::query()->count());
    }

    public function test_admin_is_forbidden_from_supervisor_routes(): void
    {
        $world = $this->world();

        $this->actingAs($world['admin'])->get('/my-groups/'.$world['internship']->id)->assertForbidden();
        $this->actingAs($world['admin'])->get('/students/'.$world['students'][0]->id)->assertForbidden();
        $this->actingAs($world['admin'])->post('/students/'.$world['students'][0]->id.'/change-requests', [])->assertForbidden();
    }

    public function test_supervisor_cannot_create_an_organization_or_its_point(): void
    {
        $world = $this->world();

        $this->actingAs($world['supervisor'])->post('/organizations', [
            'name' => 'Rahbar tashkiloti',
            'type' => 'Sud',
            'address' => 'Toshkent',
            'contact_name' => 'X',
            'contact_phone' => '+998',
            'latitude' => 41.3,
            'longitude' => 69.2,
            'radius_meters' => 100,
        ])->assertForbidden();

        $this->assertSame(0, Organization::query()->where('name', 'Rahbar tashkiloti')->count());
    }

    public function test_foreign_university_ids_are_not_found_for_admin(): void
    {
        $mine = $this->world('A', 1000);
        $theirs = $this->world('B', 2000);
        $admin = $mine['admin'];
        $placement = $this->placement($theirs['internship'], $theirs['students'][0], $theirs['org']);

        $this->actingAs($admin)->get('/internships/'.$theirs['internship']->id)->assertNotFound();
        $this->actingAs($admin)->get('/organizations/'.$theirs['org']->id.'/edit')->assertNotFound();
        $this->actingAs($admin)->put('/supervisors/'.$theirs['profile']->id, [
            'name' => 'X', 'login' => 'xx', 'phone' => '1', 'position' => 'y',
        ])->assertNotFound();
        $this->actingAs($admin)->post('/assignments/'.$placement->id.'/end')->assertNotFound();
        $this->actingAs($admin)->post('/internships/'.$theirs['internship']->id.'/invites')->assertNotFound();

        // Foreign internship or foreign organization in an assignment request.
        $this->actingAs($admin)->post('/assignments', $this->assignPayload($theirs['internship'], $mine['org'], [$theirs['students'][1]->id]))->assertNotFound();
        $this->actingAs($admin)->post('/assignments', $this->assignPayload($mine['internship'], $theirs['org'], [$mine['students'][0]->id]))->assertNotFound();

        $this->assertSame(1, InternshipAssignment::query()->count());
    }

    public function test_admin_lists_only_contain_own_university(): void
    {
        $mine = $this->world('A', 1000);
        $theirs = $this->world('B', 2000);
        $this->placement($theirs['internship'], $theirs['students'][0], $theirs['org']);

        $this->actingAs($mine['admin'])->get('/organizations')
            ->assertInertia(fn ($page) => $page->has('organizations.data', 2));
        $this->actingAs($mine['admin'])->get('/assignments')
            ->assertInertia(fn ($page) => $page->has('assignments.data', 0));
        $this->actingAs($mine['admin'])->get('/internships')
            ->assertInertia(fn ($page) => $page->has('internships.data', 1));
        $this->actingAs($mine['admin'])->get('/supervisors')
            ->assertInertia(fn ($page) => $page->has('supervisors.data', 1));
    }

    public function test_supervisor_sees_only_own_internships_students_and_requests(): void
    {
        $world = $this->world();
        $otherProfile = $this->supervisorProfile($world['university'], 'Boshqa');
        $tree = $this->academicTree($world['university'], 'C');
        $otherInternship = $this->internship($world['university'], $tree['group'], $otherProfile);
        $outsider = $this->student($world['university'], $tree['group'], 3001, 'Begona');
        $this->enroll($otherInternship, $outsider);
        $placement = $this->placement($otherInternship, $outsider, $world['org']);
        $request = InternshipChangeRequest::query()->create([
            'student_profile_id' => $outsider->id,
            'current_assignment_id' => $placement->id,
            'request_type' => ChangeRequestType::ExistingOrganization,
            'requested_organization_id' => $world['org2']->id,
            'reason' => 'x',
            'status' => ChangeRequestStatus::Pending,
            'initiated_by' => $otherProfile->user_id,
        ]);
        $supervisor = $world['supervisor'];

        $this->actingAs($supervisor)->get('/my-groups')
            ->assertInertia(fn ($page) => $page->component('Supervisor/Groups')->has('internships', 1)->where('internships.0.id', $world['internship']->id));
        $this->actingAs($supervisor)->get('/my-groups/'.$world['internship']->id)->assertOk();
        $this->actingAs($supervisor)->get('/my-groups/'.$otherInternship->id)->assertNotFound();
        $this->actingAs($supervisor)->get('/students/'.$world['students'][0]->id)->assertOk();
        $this->actingAs($supervisor)->get('/students/'.$outsider->id)->assertNotFound();
        $this->actingAs($supervisor)->get('/change-requests')->assertInertia(fn ($page) => $page->has('requests.data', 0));
        $this->actingAs($supervisor)->post('/change-requests/'.$request->id.'/approve')->assertNotFound();
        $this->actingAs($supervisor)->post('/change-requests/'.$request->id.'/reject')->assertNotFound();
        $this->actingAs($supervisor)->post('/students/'.$outsider->id.'/change-requests', [
            'request_type' => 'EXISTING_ORGANIZATION', 'organization_id' => $world['org2']->id, 'reason' => 'x',
        ])->assertNotFound();

        $this->assertSame(ChangeRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_supervisor_pages_never_expose_organization_coordinates(): void
    {
        $world = $this->world();

        $this->actingAs($world['supervisor'])->get('/my-groups/'.$world['internship']->id)
            ->assertInertia(fn ($page) => $page->has('organizations.0', fn ($org) => $org->hasAll(['id', 'name', 'address'])->missing('latitude')->missing('location')->missing('radius_meters')));
    }
}
