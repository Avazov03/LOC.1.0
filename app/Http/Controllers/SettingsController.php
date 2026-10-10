<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\UniversityHoliday;
use App\Services\Academic\UniversitySettingsService;
use App\Services\Attendance\HolidayService;
use App\Services\Audit\AuditLogger;
use App\Support\Present;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Request $request, AuditLogger $audit, HolidayService $holidays): Response
    {
        $user = $request->user();
        $university = $user->university;

        return Inertia::render('Settings', [
            'university' => ['name' => $university->name, 'slug' => $university->slug, 'timezone' => $university->timezone, 'reminder_time' => $university->reminder_time],
            'holidays' => $holidays->list($user)->map(fn (UniversityHoliday $holiday) => ['id' => $holiday->id, 'date' => (string) $holiday->date, 'name' => $holiday->name])->values(),
            'today' => $university->today(),
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

    public function storeHoliday(Request $request, HolidayService $holidays): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $holidays->add($request->user(), $data['date'], $data['name']);

        return back()->with('success', 'Dam olish kuni qo‘shildi. Shu kuni talabalar «Kelmadi» deb hisoblanmaydi.');
    }

    public function destroyHoliday(Request $request, int $holiday, HolidayService $holidays): RedirectResponse
    {
        $holidays->remove($request->user(), $holiday);

        return back()->with('success', 'Dam olish kuni o‘chirildi.');
    }
}
