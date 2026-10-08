<?php

namespace App\Http\Controllers;

use App\Http\Requests\InternshipRequest;
use App\Models\Internship;
use App\Models\InternshipInvite;
use App\Models\InternshipSupervisorPeriod;
use App\Services\Academic\AcademicStructureService;
use App\Services\Access\AccessScope;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\Internships\InternshipService;
use App\Services\Internships\InviteService;
use App\Services\Organizations\OrganizationService;
use App\Services\Supervisors\SupervisorService;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InternshipController extends Controller
{
    public function __construct(
        private readonly InternshipService $internships,
        private readonly InviteService $invites,
        private readonly AccessScope $scope,
    ) {}

    public function index(Request $request, AcademicStructureService $academic, SupervisorService $supervisors): Response
    {
        $user = $request->user();

        return Inertia::render('Internships/Index', [
            'internships' => $this->internships->paginate($user)->through(fn (Internship $internship) => [
                'id' => $internship->id,
                'group' => $internship->group->name,
                'program' => $internship->group->studyYear->program->name,
                'course' => $internship->group->studyYear->name,
                'year' => $internship->academicYear->name,
                'period_start' => $internship->period_start->toDateString(),
                'period_end' => $internship->period_end->toDateString(),
                'supervisor' => $internship->currentPeriod?->supervisor?->user?->name,
                'participants_count' => $internship->participants_count,
                'active_assignments_count' => $internship->active_assignments_count,
            ]),
            'groups' => $academic->groups($user)->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->studyYear->academicYear->name.' / '.$group->studyYear->program->name.' / '.$group->studyYear->name.' / '.$group->name,
            ])->values(),
            'supervisors' => $supervisors->activeOptions($user)->values(),
        ]);
    }

    public function store(InternshipRequest $request): RedirectResponse
    {
        $internship = $this->internships->create(
            $request->user(),
            $request->integer('student_group_id'),
            $request->integer('supervisor_profile_id'),
            $request->string('period_start')->toString(),
            $request->string('period_end')->toString(),
        );

        return redirect()->route('internships.show', $internship->id)->with('success', 'Amaliyot guruhi yaratildi.');
    }

    public function show(Request $request, int $internship, OrganizationService $organizations, SupervisorService $supervisors, InternshipAssignmentService $assignments): Response
    {
        $user = $request->user();
        $timezone = $user->university->timezone;
        $model = $this->scope->findInternship($user, $internship);
        $model->load(['group.studyYear.program', 'academicYear', 'supervisorPeriods' => fn ($query) => $query->with('supervisor.user:id,name')->orderByDesc('id')]);

        return Inertia::render('Internships/Show', [
            'internship' => [
                'id' => $model->id,
                'group' => $model->group->name,
                'program' => $model->group->studyYear->program->name,
                'course' => $model->group->studyYear->name,
                'year' => $model->academicYear->name,
                'period_start' => $model->period_start->toDateString(),
                'period_end' => $model->period_end->toDateString(),
            ],
            'periods' => $model->supervisorPeriods->map(fn (InternshipSupervisorPeriod $period) => [
                'id' => $period->id,
                'supervisor' => $period->supervisor->user->name,
                'supervisor_profile_id' => $period->supervisor_profile_id,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on?->toDateString(),
            ]),
            'invites' => $this->invites->forInternship($model)->map(fn (InternshipInvite $invite) => [
                'id' => $invite->id,
                'status' => $invite->effectiveStatus()->value,
                'supervisor' => $invite->supervisor?->user?->name,
                'created_at' => Present::dateTime($invite->created_at, $timezone),
                'expires_at' => Present::dateTime($invite->expires_at, $timezone),
                'closed_at' => Present::dateTime($invite->closed_at, $timezone),
            ]),
            'participants' => $this->internships->participants($model)->map(fn ($participant) => Present::participant($participant, $timezone)),
            'history' => $assignments->query($user)
                ->where('internship_id', $model->id)
                ->with(['student:id,first_name,last_name', 'organization:id,name'])
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn ($assignment) => Present::assignment($assignment, $timezone)),
            'organizations' => $organizations->activeOptions($user)->values(),
            'supervisors' => $supervisors->activeOptions($user)->values(),
            'canManage' => true,
        ]);
    }

    public function update(Request $request, int $internship): RedirectResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);
        $this->internships->updateDates($request->user(), $internship, $data['period_start'], $data['period_end']);

        return back()->with('success', 'Amaliyot muddati yangilandi.');
    }

    public function replaceSupervisor(Request $request, int $internship): RedirectResponse
    {
        $data = $request->validate(['supervisor_profile_id' => ['required', 'integer']]);
        $this->internships->replaceSupervisor($request->user(), $internship, (int) $data['supervisor_profile_id']);

        return back()->with('success', 'Rahbar almashtirildi. Avvalgi rahbarlik tarixi saqlandi.');
    }

    public function storeInvite(Request $request, int $internship): RedirectResponse
    {
        $data = $request->validate(['expires_at' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        $user = $request->user();
        $expiresAt = ! empty($data['expires_at']) ? $user->university->localDateTime($data['expires_at']) : null;

        ['token' => $token] = $this->invites->create($user, $internship, $expiresAt);
        $botUsername = config('services.telegram.bot_username');

        return back()->with([
            'success' => 'Taklif havolasi yaratildi. Uni hozir nusxalang: qayta ko‘rsatilmaydi.',
            'invite_link' => $botUsername ? "https://t.me/{$botUsername}?start={$token}" : $token,
        ]);
    }

    public function closeInvite(Request $request, int $invite): RedirectResponse
    {
        $this->invites->close($request->user(), $invite);

        return back()->with('success', 'Havola yopildi. Qo‘shilgan talabalar saqlanib qoladi.');
    }
}
