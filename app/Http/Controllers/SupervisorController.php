<?php

namespace App\Http\Controllers;

use App\Enums\ActiveStatus;
use App\Http\Requests\SupervisorRequest;
use App\Models\AuditLog;
use App\Models\InternshipSupervisorPeriod;
use App\Models\SupervisorProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Supervisors\SupervisorService;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupervisorController extends Controller
{
    public function __construct(private readonly SupervisorService $supervisors) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString() ?: null;

        return Inertia::render('Supervisors/Index', [
            'supervisors' => $this->supervisors->paginate($request->user(), $search)->through(fn (SupervisorProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->user->name,
                'login' => $profile->user->login,
                'email' => $profile->user->email,
                'phone' => $profile->phone,
                'position' => $profile->position,
                'status' => $profile->user->status->value,
                'open_internships_count' => $profile->open_internships_count,
            ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function show(Request $request, int $supervisor, AuditLogger $audit): Response
    {
        $user = $request->user();
        $timezone = $user->university->timezone;
        ['profile' => $profile, 'periods' => $periods, 'open_students' => $openStudents] = $this->supervisors->detail($user, $supervisor);

        return Inertia::render('Supervisors/Show', [
            'supervisor' => [
                'id' => $profile->id,
                'name' => $profile->user->name,
                'login' => $profile->user->login,
                'email' => $profile->user->email,
                'phone' => $profile->phone,
                'position' => $profile->position,
                'status' => $profile->user->status->value,
                'last_login_at' => Present::dateTime($audit->lastLogin($profile->user), $timezone),
                'open_students' => $openStudents,
            ],
            'periods' => $periods->map(fn (InternshipSupervisorPeriod $period) => [
                'id' => $period->id,
                'internship_id' => $period->internship_id,
                'group' => $period->internship->group->name,
                'program' => $period->internship->group->studyYear->program->name,
                'year' => $period->internship->academicYear->name,
                'period_start' => $period->internship->period_start->toDateString(),
                'period_end' => $period->internship->period_end->toDateString(),
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on?->toDateString(),
                'participants_count' => $period->internship->participants_count,
            ])->values(),
            'history' => $audit->history($user, $profile)->map(fn (AuditLog $log) => Present::auditLog($log, $timezone))->values(),
        ]);
    }

    public function store(SupervisorRequest $request): RedirectResponse
    {
        $this->supervisors->create($request->user(), $request->validated());

        return back()->with('success', 'Rahbar qo‘shildi.');
    }

    public function update(SupervisorRequest $request, int $supervisor): RedirectResponse
    {
        $this->supervisors->update($request->user(), $supervisor, $request->validated());

        return back()->with('success', 'Rahbar yangilandi.');
    }

    public function status(Request $request, int $supervisor): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(ActiveStatus::class)]]);
        $this->supervisors->setStatus($request->user(), $supervisor, ActiveStatus::from($data['status']));

        return back()->with('success', $data['status'] === 'ACTIVE' ? 'Rahbar faollashtirildi.' : 'Rahbar nofaol qilindi.');
    }
}
