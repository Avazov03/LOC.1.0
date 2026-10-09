<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an admin works as a supervisor, the supervisor's own account stays theirs: an admin must not change
 * the password or bind a Telegram account (which would route the supervisor's notifications to the admin).
 */
class ProtectImpersonatedAccount
{
    private const PERSONAL = ['profile.password', 'profile.telegram.link', 'profile.telegram.unlink', 'profile.notifications'];

    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->hasSession()
            && $request->session()->has(ImpersonationController::SESSION_KEY)
            && in_array($request->route()?->getName(), self::PERSONAL, true)
        ) {
            return back()->with('error', 'Rahbarning paroli va Telegram sozlamalarini faqat rahbarning o‘zi o‘zgartiradi.');
        }

        return $next($request);
    }
}
