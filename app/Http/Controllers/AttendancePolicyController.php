<?php

namespace App\Http\Controllers;

use App\Models\AttendancePolicy;
use App\Models\StudentGroup;
use App\Services\Attendance\AttendancePolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §79, A32: one UNIVERSITY policy plus optional GROUP overrides. Admin only.
 */
class AttendancePolicyController extends Controller
{
    public function __construct(private readonly AttendancePolicyService $policies) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Attendance/Policies', [
            'university' => $this->policies->universityRules($user),
            'overrides' => $this->policies->groupPolicies($user)->map(fn (AttendancePolicy $policy) => [
                'id' => $policy->id,
                'group_id' => $policy->scope_id,
                'group' => $policy->group?->name,
                'program' => $policy->group?->studyYear?->program?->name,
                'course' => $policy->group?->studyYear?->name,
                ...$policy->rules(),
            ])->values(),
            'groups' => StudentGroup::query()
                ->whereHas('studyYear.program.faculty', fn ($q) => $q->where('university_id', $user->university_id))
                ->with('studyYear.program:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (StudentGroup $group) => ['id' => $group->id, 'name' => $group->name.' · '.$group->studyYear?->program?->name])
                ->values(),
        ]);
    }

    public function updateUniversity(Request $request): RedirectResponse
    {
        $this->policies->saveUniversity($request->user(), $this->rules($request));

        return back()->with('success', 'Universitet davomat siyosati saqlandi.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $groupId = (int) $request->validate(['group_id' => ['required', 'integer']])['group_id'];
        $this->policies->saveGroup($request->user(), $groupId, $this->rules($request));

        return back()->with('success', 'Guruh siyosati saqlandi.');
    }

    public function deactivate(Request $request, int $policy): RedirectResponse
    {
        $this->policies->deactivateGroup($request->user(), $policy);

        return back()->with('success', 'Guruh siyosati o‘chirildi. Guruh endi universitet siyosatiga bo‘ysunadi.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request): array
    {
        return $request->validate([
            'check_in_enabled' => ['required', 'boolean'],
            'check_out_enabled' => ['required', 'boolean'],
            'minimum_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'multiple_sessions_allowed' => ['sometimes', 'boolean', 'declined'],
            'location_required' => ['required', 'boolean'],
            'accuracy_threshold_meters' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'manual_correction_allowed' => ['required', 'boolean'],
        ]);
    }
}
