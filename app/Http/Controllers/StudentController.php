<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatus;
use App\Http\Requests\StudentUpdateRequest;
use App\Models\AuditLog;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use App\Services\Access\AccessScope;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\Audit\AuditLogger;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use App\Services\Students\StudentService;
use App\Support\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Student directory for admins (§12, §13) and the scoped student list for supervisors (§50, §94).
 * Students never sign in to the web (A9).
 */
class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
        private readonly AccessScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $this->filters($request);

        return Inertia::render('Academic/Students', [
            'students' => $this->students->paginate($user, $filters)->through(fn (StudentProfile $student) => Present::studentRow($student)),
            'filters' => $filters,
            'groups' => StudentGroup::query()
                ->whereHas('studyYear.program.faculty', fn ($q) => $q->where('university_id', $user->university_id))
                ->orderBy('name')
                ->get(['id', 'name']),
            'internships' => $this->internshipOptions($request),
        ]);
    }

    public function supervisorIndex(Request $request): Response
    {
        $filters = $this->filters($request);
        unset($filters['group']);

        return Inertia::render('Supervisor/Students', [
            'students' => $this->students->paginate($request->user(), $filters)->through(fn (StudentProfile $student) => Present::studentRow($student)),
            'filters' => $filters,
            'internships' => $this->internshipOptions($request),
        ]);
    }

    public function show(Request $request, int $student, InternshipAssignmentService $assignments, InternshipChangeRequestService $changeRequests, AuditLogger $audit): Response
    {
        $user = $request->user();
        $timezone = $user->university->timezone;
        $profile = $this->students->find($user, $student);

        return Inertia::render('Academic/StudentShow', [
            'student' => Present::studentDetail($profile, $timezone, true),
            'assignments' => $assignments->query($user)
                ->where('student_profile_id', $profile->id)
                ->with(['organization:id,name', 'internship.group:id,name', 'supervisor.user:id,name'])
                ->orderByDesc('start_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn ($assignment) => Present::assignment($assignment, $timezone)),
            'changeRequests' => $changeRequests->visible($user)
                ->where('student_profile_id', $profile->id)
                ->with(['student.currentGroup', 'currentAssignment.organization:id,name', 'requestedOrganization:id,name', 'initiator:id,name,role', 'reviewer:id,name'])
                ->orderByDesc('id')
                ->get()
                ->map(fn ($row) => Present::changeRequest($row, $timezone)),
            'history' => $audit->history($user, $profile)->map(fn (AuditLog $log) => Present::auditLog($log, $timezone))->values(),
        ]);
    }

    public function update(StudentUpdateRequest $request, int $student): RedirectResponse
    {
        $data = $request->validated();
        $this->students->update($request->user(), $student, $data, $data['reason'] ?? null);

        return back()->with('success', 'Talaba ma’lumotlari yangilandi.');
    }

    public function status(Request $request, int $student): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(StudentStatus::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $this->students->setStatus($request->user(), $student, StudentStatus::from($data['status']), $data['reason']);

        return back()->with('success', 'Talaba holati o‘zgartirildi.');
    }

    /**
     * @return array{search: ?string, group: ?int, internship: ?int, status: ?string, placement: ?string}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status');
        $placement = $request->query('placement');

        return [
            'search' => mb_substr($request->string('search')->trim()->toString(), 0, 100) ?: null,
            'group' => $request->integer('group') ?: null,
            'internship' => $request->integer('internship') ?: null,
            'status' => is_string($status) && StudentStatus::tryFrom($status) ? $status : null,
            'placement' => in_array($placement, StudentService::PLACEMENT_FILTERS, true) ? $placement : null,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function internshipOptions(Request $request): array
    {
        return $this->scope->internships($request->user())
            ->with('group:id,name')
            ->orderByDesc('period_start')
            ->limit(200)
            ->get()
            ->map(fn ($internship) => ['id' => $internship->id, 'name' => $internship->group->name.' ('.$internship->period_start->format('d.m.Y').')'])
            ->all();
    }
}
