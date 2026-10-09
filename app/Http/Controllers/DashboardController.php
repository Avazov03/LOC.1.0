<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'stats' => $user->isAdmin() ? $dashboard->admin($user) : $dashboard->supervisor($user),
            'timezone' => $user->university->timezone,
            'attendance' => $dashboard->attendanceToday($user),
            'unmarked' => $dashboard->unmarkedToday($user),
            'reminderTime' => $user->university->reminder_time,
            'today' => $user->university->today(),
        ]);
    }
}
