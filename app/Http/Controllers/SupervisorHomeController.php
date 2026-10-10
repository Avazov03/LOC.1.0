<?php

namespace App\Http\Controllers;

use App\Models\Internship;
use App\Services\Access\AccessScope;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use App\Services\Internships\InternshipService;
use App\Services\Organizations\OrganizationService;
use App\Services\Students\StudentService;
use App\Support\Present;
use App\Support\WorkDays;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supervisor pages. Every query goes through AccessScope; anything outside the open supervisor periods is 404.
 */
class SupervisorHomeController extends Controller
{
    public function __construct(private readonly AccessScope $scope) {}

    public function groups(Request $request): Response
    {
        $internships = $this->scope->internships($request->user())
            ->with(['group.studyYear.program', 'academicYear'])
            ->withCount([
                'participants',
                'assignments as active_assignments_count' => fn ($query) => $query->where('status', 'ACTIVE'),
            ])
            ->orderByDesc('period_start')
            ->get();

        return Inertia::render('Supervisor/Groups', [
            'internships' => $internships->map(fn (Internship $internship) => [
                'id' => $internship->id,
                'group_id' => $internship->student_group_id,
                'group' => $internship->group->name,
                'program' => $internship->group->studyYear->program->name,
                'course' => $internship->group->studyYear->name,
                'year' => $internship->academicYear->name,
                'period_start' => $internship->period_start->toDateString(),
                'period_end' => $internship->period_end->toDateString(),
                'participants_count' => $internship->participants_count,
                'active_assignments_count' => $internship->active_assignments_count,
            ]),
        ]);
    }

    public function group(Request $request, int $internship, InternshipService $internships, OrganizationService $organizations): Response
    {
        $user = $request->user();
        $timezone = $user->university->timezone;
        $model = $this->scope->findInternship($user, $internship);
        $model->load(['group.studyYear.program', 'academicYear']);
        $siblings = $this->scope->internships($user)
            ->with('group:id,name')
            ->withCount('participants')
            ->orderByDesc('period_start')
            ->get();

        return Inertia::render('Supervisor/GroupShow', [
            'internship' => [
                'id' => $model->id,
                'group_id' => $model->student_group_id,
                'group' => $model->group->name,
                'program' => $model->group->studyYear->program->name,
                'course' => $model->group->studyYear->name,
                'year' => $model->academicYear->name,
                'period_start' => $model->period_start->toDateString(),
                'period_end' => $model->period_end->toDateString(),
                'work_days' => WorkDays::toDays($model->work_days),
                'work_days_label' => WorkDays::label($model->work_days),
            ],
            'participants' => $internships->participants($model)->map(fn ($participant) => Present::participant($participant, $timezone)),
            'organizations' => $organizations->activeOptions($user)->values(),
            'groups' => $siblings->map(fn (Internship $row) => ['id' => $row->id, 'group' => $row->group->name, 'participants_count' => $row->participants_count])->values(),
        ]);
    }

    public function student(
        Request $request,
        int $student,
        InternshipAssignmentService $assignments,
        InternshipChangeRequestService $changeRequests,
        OrganizationService $organizations,
        StudentService $students,
    ): Response {
        $user = $request->user();
        $timezone = $user->university->timezone;
        $profile = $students->find($user, $student);
        $visibleInternships = $this->scope->internships($user)->pluck('internships.id');
        $profile->setRelation('participations', $profile->participations->whereIn('internship_id', $visibleInternships)->values());

        return Inertia::render('Supervisor/StudentShow', [
            'student' => Present::studentDetail($profile, $timezone, false),
            'assignments' => $assignments->query($user)
                ->where('student_profile_id', $profile->id)
                ->with(['organization:id,name', 'internship.group:id,name', 'supervisor.user:id,name'])
                ->orderByDesc('start_at')
                ->get()
                ->map(fn ($assignment) => Present::assignment($assignment, $timezone)),
            'changeRequests' => $changeRequests->visible($user)
                ->where('student_profile_id', $profile->id)
                ->with(['student.currentGroup', 'currentAssignment.organization:id,name', 'requestedOrganization:id,name', 'initiator:id,name,role', 'reviewer:id,name'])
                ->orderByDesc('id')
                ->get()
                ->map(fn ($row) => Present::changeRequest($row, $timezone)),
            'organizations' => $organizations->activeOptions($user)->values(),
        ]);
    }
}
