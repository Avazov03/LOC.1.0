<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\AssignmentStatus;
use App\Enums\StudentStatus;
use App\Services\Students\StudentAccessException;
use App\Services\Students\StudentContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

/**
 * The read contract Phase 3 will call (§4.3, §64, A10, A48). Keyed by Telegram user id only, no Telegram API involved.
 */
class StudentContextServiceTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    private function service(): StudentContextService
    {
        return app(StudentContextService::class);
    }

    public function test_profile_returns_own_context_without_coordinates(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->placement($world['internship'], $student, $world['org']);

        $profile = $this->service()->profile($student->telegram_user_id);

        $this->assertSame($student->id, $profile['student_id']);
        $this->assertSame('Aliyev Talaba', $profile['name']);
        $this->assertSame('Guruh A', $profile['group']);
        $this->assertSame('Yo‘nalish A', $profile['program']);
        $this->assertSame('Sud', $profile['assignment']['organization']);
        $this->assertSame('ACTIVE', $profile['assignment']['status']);
        $this->assertSame('Rahbar', $profile['assignment']['supervisor']);
        $encoded = json_encode($profile);
        foreach (['latitude', 'longitude', 'radius', 'location', 'telegram'] as $key) {
            $this->assertStringNotContainsString($key, $encoded);
        }
    }

    public function test_unknown_inactive_and_blocked_students_get_the_same_denial(): void
    {
        $world = $this->world();
        [$inactive, $blocked] = $world['students'];
        $inactive->update(['status' => StudentStatus::Inactive]);
        $blocked->update(['status' => StudentStatus::Blocked]);

        foreach ([999_999_999, $inactive->telegram_user_id, $blocked->telegram_user_id] as $telegramUserId) {
            try {
                $this->service()->profile($telegramUserId);
                $this->fail("Telegram user {$telegramUserId} must be denied.");
            } catch (StudentAccessException $exception) {
                $this->assertSame(StudentAccessException::ACCESS_DENIED, $exception->reason);
            }
        }
    }

    public function test_deactivated_student_user_account_is_denied(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $student->user()->update(['status' => ActiveStatus::Inactive]);

        $this->expectException(StudentAccessException::class);
        $this->service()->student($student->telegram_user_id);
    }

    public function test_active_assignment_is_required_and_pending_does_not_count(): void
    {
        $world = $this->world();
        $student = $world['students'][0];

        try {
            $this->service()->activeAssignment($student->telegram_user_id);
            $this->fail('No assignment yet.');
        } catch (StudentAccessException $exception) {
            $this->assertSame(StudentAccessException::NO_ACTIVE_ASSIGNMENT, $exception->reason);
            $this->assertSame('Sizda hozir faol amaliyot biriktirilmagan.', $exception->getMessage());
        }

        $pending = $this->placement($world['internship'], $student, $world['org'], AssignmentStatus::Pending);
        $profile = $this->service()->profile($student->telegram_user_id);
        $this->assertSame('PENDING', $profile['assignment']['status']);
        try {
            $this->service()->activeAssignment($student->telegram_user_id);
            $this->fail('PENDING is not an active assignment.');
        } catch (StudentAccessException $exception) {
            $this->assertSame(StudentAccessException::NO_ACTIVE_ASSIGNMENT, $exception->reason);
        }

        $pending->update(['status' => AssignmentStatus::Active]);
        $this->assertSame($pending->id, $this->service()->activeAssignment($student->telegram_user_id)->id);
    }

    public function test_ended_assignment_is_not_the_active_one(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->placement($world['internship'], $student, $world['org'], AssignmentStatus::Ended);

        $this->assertNull($this->service()->profile($student->telegram_user_id)['assignment']);
        $this->expectException(StudentAccessException::class);
        $this->service()->activeAssignment($student->telegram_user_id);
    }
}
