<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Totp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:05', 'UTC'));
    }

    private function code(User $user, int $shift = 0): string
    {
        return Totp::at((string) $user->fresh()->two_factor_secret, intdiv(now()->getTimestamp(), Totp::PERIOD) + $shift);
    }

    /**
     * @return array{0: User, 1: list<string>}
     */
    private function enabled(): array
    {
        $user = User::factory()->supervisor()->create(['login' => 'rahbar-1']);
        $this->actingAs($user)->post('/profile/two-factor', ['current_password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => $this->code($user)])->assertSessionHas('recovery_codes');
        $codes = session('recovery_codes');
        $this->post('/logout');
        $this->travel(Totp::PERIOD * 2)->seconds();

        return [$user->fresh(), $codes];
    }

    public function test_codes_match_the_rfc_6238_vector(): void
    {
        // RFC 6238 appendix B, SHA-1 secret "12345678901234567890" at T = 59 s: 94287082 → last six digits.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::at($secret, 1));
        $this->assertSame(1, Totp::match($secret, '287 082', 59));
        $this->assertNull(Totp::match($secret, '287082', 59 + Totp::PERIOD * 3));
        $this->assertSame(32, strlen(Totp::secret()));
        $this->assertStringStartsWith('otpauth://totp/Amaliyot:rahbar-1?secret=', Totp::uri('ABC', 'Amaliyot', 'rahbar-1'));
    }

    public function test_user_turns_on_two_factor_with_password_and_a_valid_code(): void
    {
        $user = User::factory()->supervisor()->create();

        $this->actingAs($user)->post('/profile/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertNull($user->fresh()->two_factor_secret);

        $this->actingAs($user)->post('/profile/two-factor', ['current_password' => 'password'])->assertSessionHasNoErrors();
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.setup.secret', $user->fresh()->two_factor_secret)
            ->has('twoFactor.setup.uri'));
        $this->assertNotSame($user->fresh()->two_factor_secret, $user->fresh()->getRawOriginal('two_factor_secret'), 'The secret is encrypted at rest.');

        $this->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHas('error');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->post('/profile/two-factor/confirm', ['code' => $this->code($user)])->assertSessionHas('recovery_codes');
        $this->assertCount(8, session('recovery_codes'));
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('twoFactor.enabled', true)->where('twoFactor.setup', null)->where('twoFactor.remaining_codes', 8));
        $this->assertSame(1, AuditLog::query()->where('action', 'user.two_factor_enable')->count());
    }

    public function test_login_asks_for_the_code_and_a_code_cannot_be_replayed(): void
    {
        [$user] = $this->enabled();

        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/two-factor-challenge')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $code = $this->code($user);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('totp', AuditLog::query()->where('action', 'auth.login')->latest('id')->first()->metadata['two_factor']);

        $this->post('/logout');
        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        [$user, $codes] = $this->enabled();

        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => strtolower($codes[0])])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);

        $this->post('/logout');
        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_challenge_needs_a_fresh_password_step_and_is_throttled(): void
    {
        [$user] = $this->enabled();

        $this->get('/two-factor-challenge')->assertRedirect('/login');
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');

        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password']);
        $this->travel(301)->seconds();
        $this->post('/two-factor-challenge', ['code' => $this->code($user)])->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', ['login' => 'rahbar-1', 'password' => 'password']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }
        $this->post('/two-factor-challenge', ['code' => $this->code($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_disable_needs_the_password_and_admin_or_artisan_can_reset(): void
    {
        [$user] = $this->enabled();
        $this->actingAs($user)->delete('/profile/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->actingAs($user)->delete('/profile/two-factor', ['current_password' => 'password'])->assertSessionHas('success');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $world = $this->world();
        $supervisor = $world['profile']->user;
        $supervisor->forceFill(['two_factor_secret' => Totp::secret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($world['admin'])->get("/supervisors/{$world['profile']->id}")->assertInertia(fn (Assert $page) => $page->where('supervisor.two_factor_enabled', true));
        $this->actingAs($supervisor)->post("/supervisors/{$world['profile']->id}/two-factor-reset")->assertForbidden();
        $this->actingAs($world['admin'])->post("/supervisors/{$world['profile']->id}/two-factor-reset")->assertSessionHas('success');
        $this->assertFalse($supervisor->fresh()->hasTwoFactor());
        $this->assertSame($world['admin']->id, AuditLog::query()->where('action', 'user.two_factor_reset')->sole()->actor_user_id);

        $world['admin']->forceFill(['two_factor_secret' => Totp::secret(), 'two_factor_confirmed_at' => now()])->save();
        $this->artisan('user:two-factor-reset', ['login' => $world['admin']->login])->assertSuccessful();
        $this->assertFalse($world['admin']->fresh()->hasTwoFactor());
    }

    public function test_impersonating_admin_cannot_touch_the_supervisors_second_factor(): void
    {
        $world = $this->world();
        $this->actingAs($world['admin'])->post("/supervisors/{$world['profile']->id}/impersonate");

        $this->from('/profile')->post('/profile/two-factor', ['current_password' => 'password'])->assertSessionHas('error');
        $this->assertNull($world['profile']->user->fresh()->two_factor_secret);
    }
}
