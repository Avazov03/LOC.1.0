<?php

namespace Tests\Feature;

use App\Enums\InviteStatus;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\InternshipAssignment;
use App\Models\InternshipInvite;
use App\Models\InternshipParticipant;
use App\Models\StudentGroupMembership;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Internships\InviteService;
use App\Services\Onboarding\OnboardingException;
use App\Services\Onboarding\StudentOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class InviteOnboardingTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    private function createInvite(array $world, ?string $expiresAt = null): string
    {
        $response = $this->actingAs($world['admin'])->post('/internships/'.$world['internship']->id.'/invites', ['expires_at' => $expiresAt]);
        $response->assertSessionHas('invite_link');

        return (string) session('invite_link');
    }

    public function test_invite_stores_only_the_hash_and_shows_the_token_once(): void
    {
        $world = $this->world();
        $token = $this->createInvite($world);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $invite = InternshipInvite::query()->sole();
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertSame($world['profile']->id, $invite->supervisor_profile_id);
        $this->assertSame($world['group']->id, $invite->student_group_id);

        foreach (InternshipInvite::query()->toBase()->get()->first() as $value) {
            $this->assertNotSame($token, $value);
        }
        $this->assertStringNotContainsString($token, json_encode(AuditLog::query()->get()->toArray()));

        $this->actingAs($world['admin'])->get('/internships/'.$world['internship']->id)
            ->assertInertia(fn ($page) => $page->has('invites.0', fn ($row) => $row->where('status', 'ACTIVE')->missing('token_hash')->etc()));
    }

    public function test_invite_link_uses_the_public_bot_username_when_configured(): void
    {
        config(['services.telegram.bot_username' => 'demo_bot']);
        $world = $this->world();

        $link = $this->createInvite($world);

        $this->assertMatchesRegularExpression('#^https://t\.me/demo_bot\?start=[A-Za-z0-9_-]{43}$#', $link);
    }

    public function test_onboarding_writes_user_profile_membership_and_participant_without_an_assignment(): void
    {
        $world = $this->world();
        $token = $this->createInvite($world);
        $service = app(StudentOnboardingService::class);

        $context = $service->context($token);
        $this->assertSame($world['group']->name, $context['group']);

        $profile = $service->join($token, 777001, 'Jasur', 'Karimov', '+998901234567', 'S-1');

        $this->assertSame(UserRole::Student, User::query()->find($profile->user_id)->role);
        $this->assertNull(User::query()->find($profile->user_id)->password);
        $this->assertSame($world['group']->id, $profile->current_group_id);
        $membership = StudentGroupMembership::query()->where('student_profile_id', $profile->id)->sole();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame(InternshipInvite::query()->value('id'), $membership->internship_invite_id);
        $this->assertTrue(InternshipParticipant::query()->where('internship_id', $world['internship']->id)->where('student_profile_id', $profile->id)->exists());
        $this->assertSame(0, InternshipAssignment::query()->where('student_profile_id', $profile->id)->count());
    }

    public function test_duplicate_telegram_id_never_creates_a_second_student(): void
    {
        $world = $this->world();
        $token = $this->createInvite($world);
        $service = app(StudentOnboardingService::class);
        $service->join($token, 777002, 'Jasur', 'Karimov', '+998901234567');
        $before = StudentProfile::query()->count();

        try {
            $service->join($token, 777002, 'Boshqa', 'Ism', '+998907654321');
            $this->fail('Second join must be refused.');
        } catch (OnboardingException $exception) {
            $this->assertSame(OnboardingException::ALREADY_REGISTERED, $exception->reason);
        }

        $this->assertSame($before, StudentProfile::query()->count());
        $this->assertSame(1, StudentProfile::query()->where('telegram_user_id', 777002)->count());
    }

    public function test_duplicate_student_code_is_refused(): void
    {
        $world = $this->world();
        $token = $this->createInvite($world);
        $service = app(StudentOnboardingService::class);
        $service->join($token, 777010, 'A', 'B', '+998900000010', 'CODE-1');

        $this->expectExceptionObject(new OnboardingException(OnboardingException::STUDENT_CODE_TAKEN));
        $service->join($token, 777011, 'C', 'D', '+998900000011', 'CODE-1');
    }

    public function test_closed_invite_refuses_new_students_and_keeps_existing_ones(): void
    {
        $world = $this->world();
        $token = $this->createInvite($world);
        $service = app(StudentOnboardingService::class);
        $joined = $service->join($token, 777003, 'Jasur', 'Karimov', '+998901234567');
        $invite = InternshipInvite::query()->sole();

        $this->actingAs($world['admin'])->post("/invites/{$invite->id}/close")->assertSessionHas('success');
        $this->assertSame(InviteStatus::Closed, $invite->fresh()->status);
        $this->assertNotNull($invite->fresh()->closed_at);
        $this->actingAs($world['admin'])->post("/invites/{$invite->id}/close")->assertSessionHas('error');

        try {
            $service->join($token, 777004, 'Yangi', 'Talaba', '+998900000000');
            $this->fail('Closed invite must refuse.');
        } catch (OnboardingException $exception) {
            $this->assertSame(OnboardingException::INVITE_CLOSED, $exception->reason);
        }

        $this->assertNull(StudentProfile::query()->where('telegram_user_id', 777004)->first());
        $this->assertTrue(InternshipParticipant::query()->where('student_profile_id', $joined->id)->exists());
        $this->assertSame(MembershipStatus::Active, StudentGroupMembership::query()->where('student_profile_id', $joined->id)->sole()->status);
    }

    public function test_expired_invite_refuses_even_before_the_scheduler_runs(): void
    {
        $world = $this->world();
        $token = $this->createInvite($world, now('Asia/Tashkent')->addHour()->format('Y-m-d\TH:i'));
        $invite = InternshipInvite::query()->sole();
        $this->travel(2)->hours();

        $this->assertSame(InviteStatus::Active, $invite->fresh()->status);
        $this->assertSame(InviteStatus::Expired, $invite->fresh()->effectiveStatus());

        try {
            app(StudentOnboardingService::class)->join($token, 777005, 'A', 'B', '+998900000005');
            $this->fail('Expired invite must refuse.');
        } catch (OnboardingException $exception) {
            $this->assertSame(OnboardingException::INVITE_EXPIRED, $exception->reason);
        }
        $this->assertSame(0, StudentProfile::query()->where('telegram_user_id', 777005)->count());

        $this->artisan('invites:expire')->assertSuccessful();
        $this->assertSame(InviteStatus::Expired, $invite->fresh()->status);
    }

    public function test_unknown_token_is_invalid(): void
    {
        $this->world();

        $this->expectExceptionObject(new OnboardingException(OnboardingException::INVALID_INVITE));
        app(StudentOnboardingService::class)->context('not-a-real-token');
    }

    public function test_past_expiry_and_inactive_supervisor_are_refused(): void
    {
        $world = $this->world();

        $this->actingAs($world['admin'])->post('/internships/'.$world['internship']->id.'/invites', [
            'expires_at' => now('Asia/Tashkent')->subHour()->format('Y-m-d\TH:i'),
        ])->assertSessionHas('error');

        $world['supervisor']->update(['status' => 'INACTIVE']);
        $this->actingAs($world['admin'])->post('/internships/'.$world['internship']->id.'/invites')->assertSessionHas('error');

        $this->assertSame(0, InternshipInvite::query()->count());
    }

    public function test_hash_helper_is_sha256(): void
    {
        $this->assertSame(hash('sha256', 'abc'), InviteService::hash('abc'));
    }
}
