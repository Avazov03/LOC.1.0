<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an admin views the panel as a supervisor, only reads are allowed. Marks, passwords, Telegram links
 * and exports would otherwise be recorded as the supervisor's own actions.
 */
class BlockImpersonatedWrites
{
    private const ALLOWED = ['impersonate.leave', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->hasSession()
            && $request->session()->has(ImpersonationController::SESSION_KEY)
            && ! $request->isMethodSafe()
            && ! in_array($request->route()?->getName(), self::ALLOWED, true)
        ) {
            return back()->with('error', 'Rahbar sifatida ko‘rish rejimida o‘zgartirish mumkin emas. Avval admin hisobiga qayting.');
        }

        return $next($request);
    }
}
