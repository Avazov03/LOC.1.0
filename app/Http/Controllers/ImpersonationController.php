<?php

namespace App\Http\Controllers;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Supervisors\SupervisorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin opens the web panel as a supervisor of the same university, read-only (see BlockImpersonatedWrites).
 * The admin's id stays in the session so the panel can return to the admin account; both steps are audited.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, int $supervisor, SupervisorService $supervisors, AuditLogger $audit): RedirectResponse
    {
        $admin = $request->user();
        $target = $supervisors->find($admin, $supervisor)->user;

        if ($target->status !== ActiveStatus::Active) {
            throw new BusinessRuleException('Nofaol rahbar hisobiga kirib bo‘lmaydi.');
        }

        $audit->log($admin, 'auth.impersonate', $target, metadata: ['supervisor_profile_id' => $supervisor]);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);
        $request->session()->put('impersonated_profile_id', $supervisor);

        return redirect()->route('dashboard')->with('success', "Siz {$target->name} sifatida ko‘ryapsiz. Bu rejimda hech narsa o‘zgartirilmaydi.");
    }

    public function leave(Request $request, AuditLogger $audit): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);
        $profileId = $request->session()->pull('impersonated_profile_id');
        $target = $request->user();
        $admin = $adminId ? User::query()->find($adminId) : null;

        if ($admin === null || $admin->role !== UserRole::Admin || $admin->status !== ActiveStatus::Active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $audit->log($admin, 'auth.impersonate_end', $target);

        return $profileId
            ? redirect()->route('supervisors.show', $profileId)->with('success', 'Admin hisobiga qaytdingiz.')
            : redirect()->route('dashboard')->with('success', 'Admin hisobiga qaytdingiz.');
    }
}
