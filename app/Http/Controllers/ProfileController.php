<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use App\Services\Supervisors\SupervisorTelegramService;
use App\Support\Present;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in staff user's own page: password, and for a supervisor the Telegram link and notification settings.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly SupervisorTelegramService $telegram) {}

    public function show(Request $request, TwoFactorService $twoFactor): Response
    {
        $user = $request->user();
        $profile = $user->supervisorProfile;

        return Inertia::render('Profile', [
            'user' => ['name' => $user->name, 'login' => $user->login, 'email' => $user->email, 'role' => $user->role->value],
            'supervisor' => $profile ? [
                'phone' => $profile->phone,
                'position' => $profile->position,
                'telegram_linked' => $profile->telegram_user_id !== null,
                'telegram_linked_at' => Present::dateTime($profile->telegram_linked_at, $user->university->timezone),
                'notify_check_events' => $profile->notify_check_events,
            ] : null,
            'reminderTime' => $user->university->reminder_time,
            'botUsername' => config('services.telegram.bot_username'),
            'twoFactor' => [
                'enabled' => $user->hasTwoFactor(),
                'remaining_codes' => $twoFactor->remainingCodes($user),
                // The secret is shown only to its owner and only until set-up is confirmed.
                'setup' => ! $user->hasTwoFactor() && $user->two_factor_secret !== null ? [
                    'secret' => $user->two_factor_secret,
                    'uri' => Totp::uri($user->two_factor_secret, (string) config('app.name'), $user->login),
                ] : null,
            ],
        ]);
    }

    public function twoFactorBegin(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $twoFactor->begin($request->user());

        return back()->with('success', 'Ilovada QR kodni skanerlang va hosil bo‘lgan 6 xonali kodni kiriting.');
    }

    public function twoFactorCancel(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $twoFactor->cancel($request->user());

        return back();
    }

    public function twoFactorConfirm(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $twoFactor->confirm($request->user(), $data['code']);

        return back()->with(['success' => 'Ikki bosqichli himoya yoqildi.', 'recovery_codes' => $codes]);
    }

    public function twoFactorCodes(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $codes = $twoFactor->regenerateCodes($request->user());

        return back()->with(['success' => 'Yangi zaxira kodlar yaratildi. Eskilari endi ishlamaydi.', 'recovery_codes' => $codes]);
    }

    public function twoFactorDisable(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $twoFactor->disable($request->user());

        return back()->with('success', 'Ikki bosqichli himoya o‘chirildi.');
    }

    public function password(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8), 'different:current_password'],
        ]);
        $user = $request->user();

        DB::transaction(function () use ($user, $data, $audit) {
            $user->forceFill(['password' => $data['password']])->save();
            $audit->log($user, 'user.password_change', $user);
        });
        $request->session()->regenerate();

        return back()->with('success', 'Parol o‘zgartirildi.');
    }

    public function telegramLink(Request $request): RedirectResponse
    {
        $profile = $request->user()->supervisorProfile()->firstOrFail();

        return back()->with([
            'success' => 'Havola tayyor. Uni oching va botda «Start» tugmasini bosing. Havola '.SupervisorTelegramService::LINK_HOURS.' soat amal qiladi.',
            'telegram_link' => $this->telegram->createLink($request->user(), $profile),
        ]);
    }

    public function telegramUnlink(Request $request): RedirectResponse
    {
        $this->telegram->unlink($request->user(), $request->user()->supervisorProfile()->firstOrFail());

        return back()->with('success', 'Telegram uzildi. Endi bildirishnomalar kelmaydi.');
    }

    public function notifications(Request $request): RedirectResponse
    {
        $data = $request->validate(['notify_check_events' => ['required', 'boolean']]);
        $this->telegram->setNotifyCheckEvents($request->user(), $request->user()->supervisorProfile()->firstOrFail(), (bool) $data['notify_check_events']);

        return back()->with('success', 'Bildirishnoma sozlamasi saqlandi.');
    }
}
