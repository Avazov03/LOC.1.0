<?php

namespace App\Http\Controllers;

use App\Enums\ActiveStatus;
use App\Http\Requests\OrganizationRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\Audit\AuditLogger;
use App\Services\Organizations\OrganizationService;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString() ?: null;
        $status = in_array($request->query('status'), ['ACTIVE', 'INACTIVE'], true) ? $request->query('status') : null;

        return Inertia::render('Organizations/Index', [
            'organizations' => $this->organizations->paginate($request->user(), $search, $status)->through(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
                'address' => $organization->address,
                'radius_meters' => $organization->radius_meters,
                'contact_name' => $organization->contact_name,
                'contact_phone' => $organization->contact_phone,
                'status' => $organization->status->value,
                'active_assignments_count' => $organization->active_assignments_count,
            ]),
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Organizations/Form', ['organization' => null]);
    }

    public function store(OrganizationRequest $request): RedirectResponse
    {
        $this->organizations->create($request->user(), $request->validated());

        return redirect()->route('organizations.index')->with('success', 'Tashkilot qo‘shildi.');
    }

    public function edit(Request $request, int $organization): Response
    {
        $model = $this->organizations->find($request->user(), $organization);

        return Inertia::render('Organizations/Form', [
            'organization' => ['id' => $model->id, ...$this->organizations->state($model)],
        ]);
    }

    public function update(OrganizationRequest $request, int $organization): RedirectResponse
    {
        $this->organizations->update($request->user(), $organization, $request->validated());

        return redirect()->route('organizations.show', $organization)->with('success', 'Tashkilot yangilandi.');
    }

    public function show(Request $request, int $organization, InternshipAssignmentService $assignments, AuditLogger $audit): Response
    {
        $user = $request->user();
        $timezone = $user->university->timezone;
        $model = $this->organizations->find($user, $organization);

        return Inertia::render('Organizations/Show', [
            'organization' => ['id' => $model->id, ...$this->organizations->state($model)],
            'assignments' => $assignments->query($user)
                ->where('organization_id', $model->id)
                ->with(['student:id,first_name,last_name', 'internship.group:id,name', 'supervisor.user:id,name'])
                ->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 WHEN 'PENDING' THEN 1 ELSE 2 END")
                ->orderByDesc('start_at')
                ->paginate(25)
                ->withQueryString()
                ->through(fn ($assignment) => Present::assignment($assignment, $timezone)),
            'history' => $audit->history($user, $model)->map(fn (AuditLog $log) => Present::auditLog($log, $timezone))->values(),
        ]);
    }

    public function status(Request $request, int $organization): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(ActiveStatus::class)]]);
        $this->organizations->setStatus($request->user(), $organization, ActiveStatus::from($data['status']));

        return back()->with('success', $data['status'] === 'ACTIVE' ? 'Tashkilot faollashtirildi.' : 'Tashkilot nofaol qilindi. Mavjud biriktirishlar saqlanadi, yangilari qabul qilinmaydi.');
    }
}
