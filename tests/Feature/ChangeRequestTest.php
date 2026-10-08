<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Models\AuditLog;
use App\Models\InternshipAssignment;
use App\Models\InternshipChangeRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class ChangeRequestTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    /**
     * @return array{0: array<string, mixed>, 1: InternshipAssignment}
     */
    private function placed(): array
    {
        $world = $this->world();

        return [$world, $this->placement($world['internship'], $world['students'][0], $world['org'])];
    }

    private function openExisting(array $world): InternshipChangeRequest
    {
        $this->actingAs($world['supervisor'])->post('/students/'.$world['students'][0]->id.'/change-requests', [
            'request_type' => 'EXISTING_ORGANIZATION',
            'organization_id' => $world['org2']->id,
            'reason' => 'Uyiga yaqin',
        ])->assertSessionHas('success');

        return InternshipChangeRequest::query()->latest('id')->firstOrFail();
    }

    public function test_supervisor_opens_a_request_and_the_current_assignment_is_untouched(): void
    {
        [$world, $current] = $this->placed();

        $request = $this->openExisting($world);

        $this->assertSame(ChangeRequestStatus::Pending, $request->status);
        $this->assertSame($current->id, $request->current_assignment_id);
        $this->assertSame($world['supervisor']->id, $request->initiated_by);
        $this->assertSame(AssignmentStatus::Active, $current->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'change_request.open')->where('entity_id', $request->id)->exists());
    }

    public function test_only_one_pending_request_per_student(): void
    {
        [$world] = $this->placed();
        $this->openExisting($world);

        $this->actingAs($world['supervisor'])->post('/students/'.$world['students'][0]->id.'/change-requests', [
            'request_type' => 'NEW_ORGANIZATION',
            'organization' => ['name' => 'Yangi joy'],
            'reason' => 'Yana',
        ])->assertSessionHas('error', InternshipChangeRequestService::MSG_PENDING_EXISTS);

        $this->assertSame(1, InternshipChangeRequest::query()->count());
    }

    public function test_one_pending_request_is_enforced_by_the_database(): void
    {
        [$world, $current] = $this->placed();
        $request = $this->openExisting($world);

        $this->expectException(UniqueConstraintViolationException::class);
        InternshipChangeRequest::query()->create([
            ...$request->only(['student_profile_id', 'request_type', 'requested_organization_id', 'initiated_by']),
            'current_assignment_id' => $current->id,
            'reason' => 'ikkinchi',
            'status' => ChangeRequestStatus::Pending,
        ]);
    }

    public function test_new_organization_request_never_accepts_coordinates(): void
    {
        [$world] = $this->placed();

        foreach (['latitude', 'lng', 'location', 'radius_meters'] as $key) {
            $this->actingAs($world['supervisor'])->post('/students/'.$world['students'][0]->id.'/change-requests', [
                'request_type' => 'NEW_ORGANIZATION',
                'organization' => ['name' => 'Yangi joy', $key => 41.3],
                'reason' => 'x',
            ])->assertSessionHasErrors('organization');
        }

        $this->assertSame(0, InternshipChangeRequest::query()->count());
    }

    public function test_opening_requests_is_rate_limited_per_supervisor(): void
    {
        [$world] = $this->placed();
        $url = '/students/'.$world['students'][0]->id.'/change-requests';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($world['supervisor'])->post($url, ['request_type' => 'EXISTING_ORGANIZATION', 'organization_id' => $world['org2']->id, 'reason' => 'x'])
                ->assertStatus(302);
        }
        $this->actingAs($world['supervisor'])->post($url, ['request_type' => 'EXISTING_ORGANIZATION', 'organization_id' => $world['org2']->id, 'reason' => 'x'])
            ->assertStatus(429);

        $this->assertSame(1, InternshipChangeRequest::query()->count());
    }

    public function test_request_needs_an_active_assignment(): void
    {
        $world = $this->world();

        $this->actingAs($world['supervisor'])->post('/students/'.$world['students'][0]->id.'/change-requests', [
            'request_type' => 'EXISTING_ORGANIZATION',
            'organization_id' => $world['org2']->id,
            'reason' => 'x',
        ])->assertSessionHas('error');

        $this->assertSame(0, InternshipChangeRequest::query()->count());
    }

    public function test_admin_does_not_open_requests(): void
    {
        [$world] = $this->placed();

        $this->expectException(AuthorizationException::class);
        app(InternshipChangeRequestService::class)->openExisting($world['admin'], $world['students'][0]->id, $world['org2']->id, 'x');
    }

    public function test_student_opens_and_cancels_own_request_only(): void
    {
        [$world] = $this->placed();
        $service = app(InternshipChangeRequestService::class);
        $studentUser = $world['students'][0]->user;
        $otherStudentUser = $world['students'][1]->user;

        $request = $service->openNew($studentUser, $world['students'][0]->id, ['name' => 'Notarius', 'address' => 'Toshkent'], 'Yaqinroq');
        $this->assertSame(ChangeRequestType::NewOrganization, $request->request_type);
        $this->assertSame(['name' => 'Notarius', 'address' => 'Toshkent'], $request->requested_organization_data);

        try {
            $service->cancel($otherStudentUser, $request->id);
            $this->fail('Another student must not cancel.');
        } catch (ModelNotFoundException) {
        }

        $service->cancel($studentUser, $request->id);
        $this->assertSame(ChangeRequestStatus::Cancelled, $request->fresh()->status);
    }

    public function test_supervisor_approval_ends_the_old_assignment_and_activates_the_new_one(): void
    {
        [$world, $current] = $this->placed();
        $request = $this->openExisting($world);

        $this->actingAs($world['supervisor'])->post("/change-requests/{$request->id}/approve")->assertSessionHas('success');

        $current->refresh();
        $request->refresh();
        $this->assertSame(AssignmentStatus::Ended, $current->status);
        $this->assertNotNull($current->ended_at);
        $new = InternshipAssignment::query()->where('student_profile_id', $world['students'][0]->id)->where('status', 'ACTIVE')->sole();
        $this->assertSame($world['org2']->id, $new->organization_id);
        $this->assertSame($current->end_at->utc()->format('Y-m-d H:i:s'), $new->end_at->utc()->format('Y-m-d H:i:s'), 'Replacement keeps the planned end.');
        $this->assertSame(ChangeRequestStatus::Approved, $request->status);
        $this->assertSame($world['supervisor']->id, $request->reviewed_by);
        $this->assertSame(2, InternshipAssignment::query()->count());
        $audit = AuditLog::query()->where('action', 'change_request.approve')->sole();
        $this->assertSame($new->id, $audit->metadata['new_assignment_id']);

        // Second approval is refused and creates nothing.
        $this->actingAs($world['admin'])->post("/change-requests/{$request->id}/approve")->assertSessionHas('error');
        $this->assertSame(2, InternshipAssignment::query()->count());
    }

    public function test_failure_inside_approval_rolls_everything_back(): void
    {
        [$world, $current] = $this->placed();
        $request = $this->openExisting($world);
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function log(?User $actor, string $action, Model $entity, ?array $before = null, ?array $after = null, ?string $reason = null, array $metadata = [], ?int $universityId = null): AuditLog
            {
                if ($action === 'change_request.approve') {
                    throw new RuntimeException('forced failure');
                }

                return parent::log($actor, $action, $entity, $before, $after, $reason, $metadata, $universityId);
            }
        });

        try {
            app(InternshipChangeRequestService::class)->approveExisting($world['admin'], $request->id);
            $this->fail('Approval should have thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced failure', $exception->getMessage());
        }

        $this->assertSame(AssignmentStatus::Active, $current->fresh()->status);
        $this->assertNull($current->fresh()->ended_at);
        $this->assertSame(1, InternshipAssignment::query()->count());
        $this->assertSame(ChangeRequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'assignment.end')->count());
    }

    public function test_new_organization_is_approved_only_by_admin_creating_it(): void
    {
        [$world, $current] = $this->placed();
        $request = app(InternshipChangeRequestService::class)->openNew($world['supervisor'], $world['students'][0]->id, ['name' => 'Notarius idorasi'], 'Talaba topdi');

        $this->actingAs($world['supervisor'])->post("/change-requests/{$request->id}/approve")->assertSessionHas('error');
        $this->actingAs($world['supervisor'])->post("/change-requests/{$request->id}/reject")->assertForbidden();
        $this->actingAs($world['supervisor'])->post("/change-requests/{$request->id}/approve-new")->assertForbidden();
        $this->assertSame(ChangeRequestStatus::Pending, $request->fresh()->status);

        $this->actingAs($world['admin'])->get("/change-requests/{$request->id}/approve-new")
            ->assertInertia(fn ($page) => $page->component('ChangeRequests/ApproveNew')->where('request.requested_data.name', 'Notarius idorasi'));

        $this->actingAs($world['admin'])->post("/change-requests/{$request->id}/approve-new", [
            'name' => 'Notarius idorasi',
            'type' => 'Notarius',
            'address' => 'Toshkent, Olmazor',
            'contact_name' => 'Rahimov',
            'contact_phone' => '+998711111111',
            'latitude' => 41.35,
            'longitude' => 69.21,
            'radius_meters' => 150,
        ])->assertRedirect('/change-requests');

        $organization = Organization::query()->where('name', 'Notarius idorasi')->withCoordinates()->sole();
        $this->assertTrue($organization->isActive());
        $this->assertSame(['latitude' => 41.35, 'longitude' => 69.21], $organization->coordinates());
        $request->refresh();
        $this->assertSame(ChangeRequestStatus::Approved, $request->status);
        $this->assertSame($organization->id, $request->requested_organization_id);
        $this->assertSame(AssignmentStatus::Ended, $current->fresh()->status);
        $this->assertSame($organization->id, $world['students'][0]->openAssignment()->value('organization_id'));
        $this->assertTrue(AuditLog::query()->where('action', 'organization.create')->where('entity_id', $organization->id)->exists());
    }

    public function test_reject_and_admin_cancel_keep_the_assignment(): void
    {
        [$world, $current] = $this->placed();
        $request = $this->openExisting($world);

        $this->actingAs($world['supervisor'])->post("/change-requests/{$request->id}/reject", ['note' => 'Asos yo‘q'])->assertSessionHas('success');
        $this->assertSame(ChangeRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('Asos yo‘q', $request->fresh()->review_note);

        $second = $this->openExisting($world);
        $this->actingAs($world['admin'])->post("/change-requests/{$second->id}/cancel")->assertSessionHas('success');
        $this->assertSame(ChangeRequestStatus::Cancelled, $second->fresh()->status);

        $this->assertSame(AssignmentStatus::Active, $current->fresh()->status);
        $this->assertSame(1, InternshipAssignment::query()->count());
    }

    public function test_approval_refuses_an_inactive_target_organization(): void
    {
        [$world, $current] = $this->placed();
        $request = $this->openExisting($world);
        $world['org2']->update(['status' => 'INACTIVE']);

        $this->actingAs($world['admin'])->post("/change-requests/{$request->id}/approve")->assertSessionHas('error');

        $this->assertSame(AssignmentStatus::Active, $current->fresh()->status);
        $this->assertSame(ChangeRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_sanitizer_rejects_unknown_keys_and_requires_a_name(): void
    {
        $service = app(InternshipChangeRequestService::class);

        foreach ([['name' => 'X', 'owner' => 'y'], ['address' => 'only']] as $payload) {
            try {
                $service->sanitizeNewOrganization($payload);
                $this->fail('Payload should be refused: '.json_encode($payload));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
