<?php

namespace App\Services\Dashboard;

use App\Enums\AssignmentStatus;
use App\Enums\ChangeRequestStatus;
use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\InternshipAssignment;
use App\Models\InternshipChangeRequest;
use App\Models\Organization;
use App\Models\StudentGroup;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Attendance\AttendanceDayQuery;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard counters (§50, §53). Every count goes through AccessScope, so a supervisor only counts
 * students and internships inside open supervisor periods.
 */
class DashboardService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AttendanceDayQuery $days,
    ) {}

    /**
     * @return list<array{label: string, value: int, icon: string, tone: string, href: string}>
     */
    public function admin(User $actor): array
    {
        $this->scope->requireAdmin($actor);
        $universityId = $actor->university_id;
        $students = $this->scope->students($actor)->select('student_profiles.id');
        $today = $actor->university->today();

        return [
            $this->stat('Talabalar', $this->scope->students($actor)->count(), 'user', 'warning', '/academic/students'),
            $this->stat('Faol amaliyotlar', $this->scope->internships($actor)->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->count(), 'briefcase', 'primary', '/internships'),
            $this->stat('Faol biriktirishlar', InternshipAssignment::query()->whereIn('student_profile_id', $students)->where('status', AssignmentStatus::Active->value)->count(), 'mapPin', 'success', '/assignments?status=ACTIVE'),
            $this->stat('Kutilayotgan so‘rovlar', InternshipChangeRequest::query()->whereIn('student_profile_id', $students)->where('status', ChangeRequestStatus::Pending->value)->count(), 'swap', 'danger', '/change-requests?status=PENDING'),
            $this->stat('Faol tashkilotlar', Organization::query()->where('university_id', $universityId)->where('status', 'ACTIVE')->count(), 'building', 'info', '/organizations?status=ACTIVE'),
            $this->stat('Fakultetlar', Faculty::query()->where('university_id', $universityId)->count(), 'layers', 'secondary', '/academic/faculties'),
            $this->stat('Guruhlar', StudentGroup::query()->whereHas('studyYear.program.faculty', fn ($q) => $q->where('university_id', $universityId))->count(), 'users', 'primary', '/academic/groups'),
            $this->stat('O‘quv yillari', AcademicYear::query()->where('university_id', $universityId)->count(), 'calendar', 'secondary', '/academic/years'),
        ];
    }

    /**
     * @return list<array{label: string, value: int, icon: string, tone: string, href: string}>
     */
    public function supervisor(User $actor): array
    {
        $students = $this->scope->students($actor)->select('student_profiles.id');
        $studentCount = $this->scope->students($actor)->count();
        $placed = $this->scope->students($actor)
            ->whereHas('assignments', fn ($q) => $q->whereIn('status', AssignmentStatus::openValues()))
            ->count();

        return [
            $this->stat('Guruhlarim', $this->scope->internships($actor)->count(), 'users', 'primary', '/my-groups'),
            $this->stat('Talabalar', $studentCount, 'user', 'info', '/my-students'),
            $this->stat('Faol biriktirishlar', InternshipAssignment::query()->whereIn('student_profile_id', $students)->where('status', AssignmentStatus::Active->value)->count(), 'mapPin', 'success', '/my-students?placement=assigned'),
            $this->stat('Biriktirilmagan', $studentCount - $placed, 'alert', 'warning', '/my-students?placement=unassigned'),
            $this->stat('Kutilayotgan so‘rovlar', InternshipChangeRequest::query()->whereIn('student_profile_id', $students)->where('status', ChangeRequestStatus::Pending->value)->count(), 'swap', 'danger', '/change-requests?status=PENDING'),
        ];
    }

    /**
     * Today's attendance in the caller's scope, one SQL aggregate (D4, A30).
     *
     * @return array<string, int>
     */
    public function attendanceToday(User $actor): array
    {
        return $this->days->totals($this->scope->students($actor), $actor->university->today(), $actor->university->timezone);
    }

    /**
     * Students expected today with nothing accepted and no staff mark yet, for the "Bugun belgilanmaganlar" block.
     *
     * @return array{total: int, rows: list<array{student_id: int, name: string, status: string}>}
     */
    public function unmarkedToday(User $actor, int $limit = 15): array
    {
        $university = $actor->university;
        $query = DB::query()
            ->fromSub($this->days->rows($this->scope->students($actor)->where('student_profiles.status', 'ACTIVE'), [$university->today()], $university->timezone), 'x')
            ->where('x.expected', 1)
            ->whereIn('x.day_status', ['ABSENT', 'LOCATION_REJECTED']);

        return [
            'total' => (clone $query)->count(),
            'rows' => $query->orderBy('x.last_name')->orderBy('x.first_name')->limit($limit)->get()
                ->map(fn ($row) => ['student_id' => (int) $row->student_profile_id, 'name' => trim($row->last_name.' '.$row->first_name), 'status' => (string) $row->day_status])
                ->all(),
        ];
    }

    /**
     * @return array{label: string, value: int, icon: string, tone: string, href: string}
     */
    private function stat(string $label, int $value, string $icon, string $tone, string $href): array
    {
        return compact('label', 'value', 'icon', 'tone', 'href');
    }
}
