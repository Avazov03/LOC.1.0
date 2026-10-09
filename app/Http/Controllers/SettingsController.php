<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Academic\UniversitySettingsService;
use App\Services\Audit\AuditLogger;
use App\Support\Present;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request, AuditLogger $audit): Response
    {
        $user = $request->user();
        $university = $user->university;

        return Inertia::render('Settings', [
            'university' => ['name' => $university->name, 'slug' => $university->slug, 'timezone' => $university->timezone, 'reminder_time' => $university->reminder_time],
            'timezones' => DateTimeZone::listIdentifiers(),
            'history' => $audit->history($user, $university, 20)->map(fn (AuditLog $log) => Present::auditLog($log, $university->timezone))->values(),
        ]);
    }

    public function update(Request $request, UniversitySettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'reminder_time' => ['sometimes', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);
        $settings->update($request->user(), $data['name'], $data['timezone'], $data['reminder_time'] ?? null);

        return back()->with('success', 'Sozlamalar saqlandi.');
    }
}
