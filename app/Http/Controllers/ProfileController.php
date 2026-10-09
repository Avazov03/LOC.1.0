<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use App\Services\Supervisors\SupervisorTelegramService;
use App\Support\Present;
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

    public function show(Request $request): Response
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
        ]);
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
