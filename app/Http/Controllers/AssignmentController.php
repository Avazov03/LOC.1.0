<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignmentRequest;
use App\Services\Access\AccessScope;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\Organizations\OrganizationService;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentController extends Controller
{
    public function __construct(private readonly InternshipAssignmentService $assignments) {}

    public function index(Request $request, OrganizationService $organizations, AccessScope $scope): Response
    {
        $user = $request->user();
        $timezone = $user->university->timezone;
        $status = in_array($request->query('status'), ['PENDING', 'ACTIVE', 'ENDED', 'CANCELLED'], true) ? $request->query('status') : null;
        $internshipId = $request->integer('internship') ?: null;
        $organizationId = $request->integer('organization') ?: null;

        return Inertia::render('Assignments/Index', [
            'assignments' => $this->assignments->paginate($user, $status, $internshipId, $organizationId)
                ->through(fn ($assignment) => Present::assignment($assignment, $timezone)),
            'filters' => ['status' => $status, 'internship' => $internshipId, 'organization' => $organizationId],
            'organizations' => $organizations->activeOptions($user)->values(),
            'internships' => $scope->internships($user)->with('group:id,name')->orderByDesc('period_start')->limit(200)->get()
                ->map(fn ($internship) => ['id' => $internship->id, 'name' => $internship->group->name.' ('.$internship->period_start->format('d.m.Y').')']),
        ]);
    }

    /**
     * Admin, or a supervisor within D5 scope. The service enforces scope per student.
     */
    public function store(AssignmentRequest $request): RedirectResponse
    {
        $user = $request->user();
        $university = $user->university;

        $results = $this->assignments->assign(
            $user,
            array_map('intval', $request->input('student_ids')),
            $request->integer('organization_id'),
            $request->integer('internship_id'),
            $university->startOfLocalDay($request->string('start_date')->toString()),
            $university->endOfLocalDay($request->string('end_date')->toString()),
        );

        $created = count(array_filter($results, fn ($row) => $row['error'] === null));
        $failed = count($results) - $created;
        $message = $failed === 0 ? "{$created} ta talaba biriktirildi." : "{$created} ta biriktirildi, {$failed} ta rad etildi.";

        return back()->with([$failed === 0 ? 'success' : 'error' => $message, 'assignment_results' => $results]);
    }

    public function update(Request $request, int $assignment): RedirectResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $university = $request->user()->university;

        $this->assignments->updateDates(
            $request->user(),
            $assignment,
            ! empty($data['start_date']) ? $university->startOfLocalDay($data['start_date']) : null,
            $university->endOfLocalDay($data['end_date']),
        );

        return back()->with('success', 'Biriktirish muddati yangilandi.');
    }

    public function activate(Request $request, int $assignment): RedirectResponse
    {
        $this->assignments->activate($request->user(), $assignment);

        return back()->with('success', 'Biriktirish faollashtirildi.');
    }

    public function end(Request $request, int $assignment): RedirectResponse
    {
        $this->assignments->end($request->user(), $assignment);

        return back()->with('success', 'Biriktirish yakunlandi. Tarix saqlanadi.');
    }

    public function cancel(Request $request, int $assignment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->assignments->cancel($request->user(), $assignment, $data['reason']);

        return back()->with('success', 'Biriktirish bekor qilindi.');
    }
}
