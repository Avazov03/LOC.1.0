<?php

namespace App\Support;

use App\Enums\ActiveStatus;
use App\Enums\DayStatus;
use App\Models\Faculty;
use App\Models\Organization;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use App\Models\StudyYear;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Attendance\AttendanceDayQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * §53 filters for attendance pages, reports and exports. Every filter narrows AccessScope::students(); none widens it.
 */
class AttendanceFilters
{
    public const KEYS = ['faculty', 'program', 'course', 'group', 'internship', 'organization', 'supervisor'];

    public function __construct(private readonly AccessScope $scope) {}

    /**
     * @return array<string, mixed>
     */
    public function fromRequest(Request $request, User $actor, bool $range): array
    {
        $today = $actor->university->today();
        $filters = ['search' => mb_substr($request->string('search')->trim()->toString(), 0, 100) ?: null];
        foreach (self::KEYS as $key) {
            $filters[$key] = $request->integer($key) ?: null;
        }
        if (! $actor->isAdmin()) {
            $filters['faculty'] = $filters['program'] = $filters['course'] = $filters['supervisor'] = null;
        }
        $status = $request->input('status');
        $filters['status'] = is_string($status) && DayStatus::tryFrom($status) ? $status : null;

        if ($range) {
            $to = $this->date($request->input('to')) ?? $today;
            $from = $this->date($request->input('from')) ?? CarbonImmutable::parse($to)->subDays(6)->toDateString();
            $to = min($to, $today);
            $from = min($from, $to);
            $dates = AttendanceDayQuery::dateRange($from, $to);
            $filters['from'] = $dates[0];
            $filters['to'] = $dates[count($dates) - 1];
        } else {
            $filters['date'] = min($this->date($request->input('date')) ?? $today, $today);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<StudentProfile>
     */
    public function students(User $actor, array $filters): Builder
    {
        $query = $this->scope->students($actor);

        if ($filters['group'] ?? null) {
            $query->where('student_profiles.current_group_id', $filters['group']);
        }
        if ($filters['course'] ?? null) {
            $query->whereIn('student_profiles.current_group_id', StudentGroup::query()->select('id')->where('study_year_id', $filters['course']));
        }
        if ($filters['program'] ?? null) {
            $query->whereIn('student_profiles.current_group_id', StudentGroup::query()->select('student_groups.id')
                ->join('study_years', 'study_years.id', '=', 'student_groups.study_year_id')
                ->where('study_years.program_id', $filters['program']));
        }
        if ($filters['faculty'] ?? null) {
            $query->whereIn('student_profiles.current_group_id', StudentGroup::query()->select('student_groups.id')
                ->join('study_years', 'study_years.id', '=', 'student_groups.study_year_id')
                ->join('programs', 'programs.id', '=', 'study_years.program_id')
                ->where('programs.faculty_id', $filters['faculty']));
        }
        if ($filters['internship'] ?? null) {
            $query->whereIn('student_profiles.id', fn ($sub) => $sub->select('student_profile_id')->from('internship_participants')->where('internship_id', $filters['internship']));
        }
        if ($filters['organization'] ?? null) {
            $query->whereIn('student_profiles.id', fn ($sub) => $sub->select('student_profile_id')->from('internship_assignments')
                ->where('organization_id', $filters['organization'])
                ->whereIn('status', ['PENDING', 'ACTIVE', 'ENDED']));
        }
        if (($filters['supervisor'] ?? null) && $actor->isAdmin()) {
            $query->whereIn('student_profiles.id', fn ($sub) => $sub->select('internship_participants.student_profile_id')->from('internship_participants')
                ->join('internship_supervisor_periods', 'internship_supervisor_periods.internship_id', '=', 'internship_participants.internship_id')
                ->whereNull('internship_supervisor_periods.ends_on')
                ->where('internship_supervisor_periods.supervisor_profile_id', $filters['supervisor']));
        }
        if ($filters['search'] ?? null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filters['search'])).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(student_profiles.first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(student_profiles.last_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(student_profiles.student_code) LIKE ?', [$like])
                ->orWhere('student_profiles.phone', 'like', $like));
        }

        return $query;
    }

    /**
     * Select options limited to what the caller can see.
     *
     * @return array<string, list<array{id: int, name: string}>>
     */
    public function options(User $actor): array
    {
        $internships = $this->scope->internships($actor)->with('group:id,name')->orderByDesc('period_start')->limit(200)->get();
        $groupIds = $actor->isAdmin() ? null : $internships->pluck('student_group_id')->unique()->values()->all();
        $pairs = fn ($rows, string $label = 'name') => $rows->map(fn ($row) => ['id' => $row->id, 'name' => (string) $row->{$label}])->values()->all();

        $groups = StudentGroup::query()
            ->whereHas('studyYear.program.faculty', fn ($q) => $q->where('university_id', $actor->university_id))
            ->when($groupIds !== null, fn ($q) => $q->whereIn('id', $groupIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        $options = [
            'groups' => $pairs($groups),
            'internships' => $internships->map(fn ($internship) => ['id' => $internship->id, 'name' => $internship->group->name.' ('.$internship->period_start->format('d.m.Y').')'])->values()->all(),
            'organizations' => $pairs(Organization::query()
                ->where('university_id', $actor->university_id)
                ->when(! $actor->isAdmin(), fn ($q) => $q->whereIn('id', fn ($sub) => $sub->select('organization_id')->from('internship_assignments')
                    ->whereIn('student_profile_id', $this->scope->students($actor)->select('student_profiles.id'))))
                ->orderBy('name')
                ->get(['id', 'name'])),
        ];

        if ($actor->isAdmin()) {
            $options['faculties'] = $pairs(Faculty::query()->where('university_id', $actor->university_id)->where('status', ActiveStatus::Active->value)->orderBy('name')->get(['id', 'name']));
            $options['programs'] = $pairs(Program::query()->whereHas('faculty', fn ($q) => $q->where('university_id', $actor->university_id))->orderBy('name')->get(['id', 'name']));
            $options['courses'] = StudyYear::query()
                ->whereHas('program.faculty', fn ($q) => $q->where('university_id', $actor->university_id))
                ->with('program:id,name')
                ->orderBy('course_number')
                ->get()
                ->map(fn ($course) => ['id' => $course->id, 'name' => $course->program->name.' · '.$course->name])
                ->all();
            $options['supervisors'] = SupervisorProfile::query()
                ->where('university_id', $actor->university_id)
                ->with('user:id,name')
                ->get()
                ->map(fn ($profile) => ['id' => $profile->id, 'name' => (string) $profile->user?->name])
                ->sortBy('name')
                ->values()
                ->all();
        }

        return $options;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
