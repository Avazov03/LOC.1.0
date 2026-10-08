<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendTelegramNotification;
use App\Models\InternshipAssignment;
use App\Models\TelegramNotification;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use App\Services\Notifications\StudentNotifier;
use App\Telegram\TelegramApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsInternships;
use Tests\Concerns\TalksToBot;
use Tests\TestCase;

class NotificationsAndOpsTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;
    use TalksToBot;

    public function test_assignment_notifies_the_student_once_after_commit(): void
    {
        $world = $this->world();
        $student = $world['students'][0];

        $this->actingAs($world['admin'])->post('/assignments', $this->assignPayload($world['internship'], $world['org'], [$student->id]))->assertSessionHasNoErrors();

        $notification = TelegramNotification::query()->sole();
        $this->assertSame('SENT', $notification->status);
        $this->assertSame((int) $student->telegram_user_id, $this->bot()->sent[0]['chat_id']);
        $this->assertStringContainsString($world['org']->name, $this->bot()->lastText());
        $this->assertStringNotContainsString('41.31', $this->bot()->lastText());

        $assignment = InternshipAssignment::query()->sole();
        app(StudentNotifier::class)->assignmentCreated($assignment);
        $this->assertSame(1, TelegramNotification::query()->count(), 'The unique key makes a repeat a no-op.');
        $this->assertCount(1, $this->bot()->sent);
    }

    public function test_a_rolled_back_transaction_never_notifies(): void
    {
        $world = $this->world();
        $assignment = $this->placement($world['internship'], $world['students'][0], $world['org']);

        try {
            DB::transaction(function () use ($assignment) {
                app(StudentNotifier::class)->assignmentCreated($assignment);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, TelegramNotification::query()->count());
        $this->assertSame([], $this->bot()->sent);
    }

    public function test_change_request_decision_is_sent_to_the_student(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->placement($world['internship'], $student, $world['org']);
        $request = app(InternshipChangeRequestService::class)->openExisting($world['supervisor'], $student->id, $world['org2']->id, 'Yaqinroq joy');

        $this->actingAs($world['supervisor'])->post("/change-requests/{$request->id}/approve")->assertSessionHas('success');

        $this->assertTrue(TelegramNotification::query()->where('key', "change_request:{$request->id}:APPROVED")->exists());
        $this->assertStringContainsString('tasdiqlandi', implode("\n", $this->bot()->texts()));
    }

    public function test_blocked_students_are_skipped_and_failures_are_recorded(): void
    {
        Queue::fake();
        $world = $this->world();
        $student = $world['students'][0];
        $assignment = $this->placement($world['internship'], $student, $world['org']);
        app(StudentNotifier::class)->assignmentCreated($assignment);
        $notification = TelegramNotification::query()->sole();
        Queue::assertPushed(SendTelegramNotification::class);

        $student->update(['status' => 'BLOCKED']);
        app()->call([new SendTelegramNotification($notification->id), 'handle']);
        $this->assertSame('SKIPPED', $notification->fresh()->status);
        $this->assertSame([], $this->bot()->sent);

        $notification->refresh()->forceFill(['status' => 'PENDING'])->save();
        $student->update(['status' => 'ACTIVE']);
        $this->bot()->failSends = true;
        $job = new SendTelegramNotification($notification->id);
        try {
            app()->call([$job, 'handle']);
            $this->fail('A send failure must surface so the queue retries.');
        } catch (TelegramApiException $exception) {
            $job->failed($exception);
        }
        $notification->refresh();
        $this->assertSame('FAILED', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame(5, $job->tries);
    }

    // ------------------------------------------------------------ ops

    public function test_health_endpoint_reports_ok(): void
    {
        $this->getJson('/health')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_forwarded_https_is_trusted_only_from_configured_proxies(): void
    {
        $forwarded = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'loc.example.uz', 'HTTP_X_FORWARDED_PORT' => '443'];

        // Absolute URLs: the test client otherwise builds the next URL from the previous request's root.
        $this->withServerVariables($forwarded)->get('http://localhost/dashboard')->assertRedirect('http://localhost/login');

        try {
            config(['app.trusted_proxies' => '10.0.0.0/8, 192.168.0.1']);
            (new AppServiceProvider($this->app))->boot();

            $this->withServerVariables($forwarded)->get('http://localhost/dashboard')->assertRedirect('https://loc.example.uz/login');
            $this->withServerVariables([...$forwarded, 'REMOTE_ADDR' => '203.0.113.9'])->get('http://localhost/dashboard')->assertRedirect('http://localhost/login');
        } finally {
            TrustProxies::flushState();
        }
    }

    public function test_admin_ensure_creates_and_refreshes_the_admin_from_environment(): void
    {
        config(['app.bootstrap_admin.login' => 'Avazov', 'app.bootstrap_admin.password' => 'secret-pass-1', 'app.bootstrap_admin.name' => 'Avazov']);

        $this->artisan('admin:ensure')->assertSuccessful();
        $admin = User::query()->where('login', 'Avazov')->sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('secret-pass-1', $admin->password));

        config(['app.bootstrap_admin.password' => 'secret-pass-2']);
        $this->artisan('admin:ensure')->assertSuccessful();
        $this->assertTrue(Hash::check('secret-pass-2', $admin->fresh()->password));
        $this->assertSame(1, User::query()->where('login', 'Avazov')->count());

        $this->post('/login', ['login' => 'Avazov', 'password' => 'secret-pass-2'])->assertRedirect('/dashboard');
    }

    public function test_admin_ensure_refuses_weak_or_missing_values_and_non_admin_logins(): void
    {
        config(['app.bootstrap_admin.login' => '', 'app.bootstrap_admin.password' => '']);
        $this->artisan('admin:ensure')->assertFailed();

        config(['app.bootstrap_admin.login' => 'Avazov', 'app.bootstrap_admin.password' => 'short']);
        $this->artisan('admin:ensure')->assertFailed();

        $world = $this->world();
        $world['supervisor']->update(['login' => 'rahbarx']);
        config(['app.bootstrap_admin.login' => 'rahbarx', 'app.bootstrap_admin.password' => 'long-enough-1']);
        $this->artisan('admin:ensure')->assertFailed();
        $this->assertSame(UserRole::Supervisor, $world['supervisor']->fresh()->role);
    }

    public function test_webhook_command_requires_https_and_a_strong_secret(): void
    {
        config(['services.telegram.webhook_secret' => 'short']);
        $this->artisan('telegram:webhook', ['url' => 'https://example.com/telegram/webhook'])->assertFailed();

        config(['services.telegram.webhook_secret' => str_repeat('a', 32)]);
        $this->artisan('telegram:webhook', ['url' => 'http://example.com/telegram/webhook'])->assertFailed();
        $this->artisan('telegram:webhook', ['url' => 'https://example.com/telegram/webhook'])->assertSuccessful();
        $this->artisan('telegram:webhook', ['--info' => true])->assertSuccessful();
    }

    public function test_scheduled_commands_are_registered(): void
    {
        $this->artisan('schedule:list')->assertSuccessful()
            ->expectsOutputToContain('attendance:close-stale')
            ->expectsOutputToContain('assignments:activate-due')
            ->expectsOutputToContain('invites:expire');
    }
}
