<?php

namespace App\Services\Attendance;

use App\Enums\ActiveStatus;
use App\Enums\SessionStatus;
use App\Models\AttendanceSession;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use App\Models\SupervisorNotification;
use App\Models\SupervisorProfile;
use App\Models\University;
use App\Services\Access\AccessScope;
use App\Services\Notifications\StudentNotifier;
use App\Services\Notifications\SupervisorNotifier;
use Carbon\CarbonImmutable;

/**
 * Once the university's reminder time (default 18:00, local) has passed: students whose session is still open get a
 * check-out reminder, and each linked supervisor gets one digest of students with nothing recorded today.
 * Runs every few minutes; the notification keys make every repeat a no-op.
 */
class DailyReminderService
{
    /** Statuses the digest lists: nothing accepted today and no staff mark. */
    private const UNMARKED = ['ABSENT', 'LOCATION_REJECTED'];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AttendanceDayQuery $days,
        private readonly StudentNotifier $students,
        private readonly SupervisorNotifier $supervisors,
    ) {}

    /**
     * @return array{reminders: int, digests: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $totals = ['reminders' => 0, 'digests' => 0];

        University::query()->each(function (University $university) use ($now, &$totals) {
            $local = $now->setTimezone($university->timezone);
            $time = $university->reminder_time ?: '18:00';
            if ($local->format('H:i') < $time) {
                return;
            }
            $today = $local->toDateString();
            $cutoff = CarbonImmutable::parse("{$today} {$time}", $university->timezone)->utc();

            AttendanceSession::query()
                ->where('status', SessionStatus::Open->value)
                ->where('local_date', $today)
                ->where('opened_at', '<', $cutoff)
                ->whereIn('student_profile_id', StudentProfile::query()->select('id')->where('university_id', $university->id))
                ->each(function (AttendanceSession $session) use (&$totals) {
                    $totals['reminders'] += (int) $this->students->checkoutReminder($session);
                });

            SupervisorProfile::query()
                ->where('university_id', $university->id)
                ->whereNotNull('telegram_user_id')
                ->whereHas('user', fn ($query) => $query->where('status', ActiveStatus::Active->value))
                ->whereNotIn('id', SupervisorNotification::query()->select('supervisor_profile_id')->where('key', 'like', 'digest:%:'.$today))
                ->with('user')
                ->each(function (SupervisorProfile $supervisor) use ($today, $university, &$totals) {
                    $this->digest($supervisor, $today, $university->timezone);
                    $totals['digests']++;
                });
        });

        return $totals;
    }

    private function digest(SupervisorProfile $supervisor, string $today, string $timezone): void
    {
        $students = $this->scope->students($supervisor->user)->where('student_profiles.status', 'ACTIVE');
        $rows = $this->days->rows($students, [$today], $timezone)
            ->where('expected', 1)
            ->whereIn('day_status', self::UNMARKED)
            ->get(['student_profile_id', 'last_name', 'first_name', 'current_group_id']);
        $groups = StudentGroup::query()->whereIn('id', $rows->pluck('current_group_id')->filter()->unique())->pluck('name', 'id');

        $this->supervisors->digest(
            $supervisor,
            $today,
            $rows->map(fn ($row) => [
                'id' => (int) $row->student_profile_id,
                'name' => trim($row->last_name.' '.$row->first_name),
                'group' => $groups[$row->current_group_id] ?? 'Guruhsiz',
            ])->sortBy([['group', 'asc'], ['name', 'asc']])->values()->all(),
            rtrim((string) config('app.url'), '/').'/attendance?date='.$today.'&status=ABSENT',
        );
    }
}
