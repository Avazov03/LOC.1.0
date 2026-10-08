<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestType;
use App\Http\Requests\ChangeRequestRequest;
use App\Http\Requests\OrganizationRequest;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChangeRequestController extends Controller
{
    public function __construct(private readonly InternshipChangeRequestService $requests) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $timezone = $user->university->timezone;
        $status = in_array($request->query('status'), ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'], true) ? $request->query('status') : null;

        return Inertia::render('ChangeRequests/Index', [
            'requests' => $this->requests->paginate($user, $status)->through(fn ($row) => Present::changeRequest($row, $timezone)),
            'filters' => ['status' => $status],
            'role' => $user->role->value,
        ]);
    }

    /**
     * Supervisor opens a request for an in-scope student (§4.2, A24).
     */
    public function store(ChangeRequestRequest $request, int $student): RedirectResponse
    {
        $user = $request->user();

        if ($request->enum('request_type', ChangeRequestType::class) === ChangeRequestType::ExistingOrganization) {
            $this->requests->openExisting($user, $student, $request->integer('organization_id'), $request->string('reason')->toString());
        } else {
            $this->requests->openNew($user, $student, (array) $request->input('organization', []), $request->string('reason')->toString());
        }

        return back()->with('success', 'Joy o‘zgartirish so‘rovi yaratildi.');
    }

    public function approve(Request $request, int $changeRequest): RedirectResponse
    {
        $this->requests->approveExisting($request->user(), $changeRequest);

        return back()->with('success', 'So‘rov tasdiqlandi. Eski biriktirish yakunlandi, yangisi faol.');
    }

    public function reject(Request $request, int $changeRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->requests->reject($request->user(), $changeRequest, $data['note'] ?? null);

        return back()->with('success', 'So‘rov rad etildi.');
    }

    public function cancel(Request $request, int $changeRequest): RedirectResponse
    {
        $this->requests->cancel($request->user(), $changeRequest);

        return back()->with('success', 'So‘rov bekor qilindi.');
    }

    public function approveNewForm(Request $request, int $changeRequest): Response
    {
        $user = $request->user();
        $row = $this->requests->visible($user)
            ->with(['student.currentGroup', 'currentAssignment.organization', 'initiator'])
            ->findOrFail($changeRequest);

        return Inertia::render('ChangeRequests/ApproveNew', [
            'request' => Present::changeRequest($row, $user->university->timezone),
        ]);
    }

    public function approveNew(OrganizationRequest $request, int $changeRequest): RedirectResponse
    {
        $this->requests->approveNew($request->user(), $changeRequest, $request->validated());

        return redirect()->route('change-requests.index')->with('success', 'Tashkilot yaratildi va talaba unga biriktirildi.');
    }
}
