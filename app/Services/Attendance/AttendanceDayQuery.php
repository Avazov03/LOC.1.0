<?php

namespace App\Services\Attendance;

use App\Models\StudentProfile;
use App\Support\WorkDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single day-status formula (D4, A30, ATTENDANCE-RULES §8), evaluated in SQL so dashboards, lists, reports,
 * exports and the bot agree and nothing loads thousands of rows into PHP to count them.
 *
 * One row per (student, local date). day_status is null when the student had nothing expected and nothing recorded.
 * A day is expected only inside an ACTIVE/ENDED assignment and on the student's work days.
 */
class AttendanceDayQuery
{
    /** A full academic year, so long internships fit in one report. */
    public const MAX_RANGE_DAYS = 366;

    private const STATUS_SQL = <<<'SQL'
        CASE
            WHEN d.mark_kind = 'PRESENT' THEN 'PRESENT'
            WHEN d.open_count > 0 THEN 'INCOMPLETE'
            WHEN d.completed_count > 0 THEN 'PRESENT'
            WHEN d.mark_kind = 'EXCUSED' THEN 'EXCUSED'
            WHEN d.incomplete_count > 0 THEN 'INCOMPLETE'
            WHEN d.rejected_count > 0 THEN 'LOCATION_REJECTED'
            WHEN d.expected = 1 OR d.failed_count > 0 THEN 'ABSENT'
            ELSE NULL
        END
        SQL;

    /**
     * @param  EloquentBuilder<StudentProfile>|Builder  $students  already scoped to what the caller may see
     * @param  list<string>  $dates  local dates (Y-m-d) in $timezone
     */
    public function rows(EloquentBuilder|Builder $students, array $dates, string $timezone): Builder
    {
        $scoped = clone ($students instanceof EloquentBuilder ? $students->toBase() : $students);
        $scopeIds = (clone $scoped)->reorder()->select('student_profiles.id');
        // The caller's conditions stay inside this subquery, so unqualified columns never clash with the joins below.
        $base = DB::query()->fromSub(
            $scoped->reorder()->select(['student_profiles.id', 'student_profiles.last_name', 'student_profiles.first_name', 'student_profiles.current_group_id', 'student_profiles.university_id']),
            'student_profiles',
        );
        $range = $dates === [] ? ['1970-01-01', '1970-01-01'] : [min($dates), max($dates)];

        // Sessions, events and marks are counted once per (student, day) for the range and joined, instead of
        // a correlated subquery per row and column: a long report over 1,000 students stays one pass.
        $sessions = DB::table('attendance_sessions as s')
            ->whereIn('s.student_profile_id', $scopeIds)
            ->whereBetween('s.local_date', $range)
            ->groupBy('s.student_profile_id', 's.local_date')
            ->select('s.student_profile_id', 's.local_date')
            ->selectRaw("SUM(CASE WHEN s.status = 'OPEN' THEN 1 ELSE 0 END) AS open_count")
            ->selectRaw("SUM(CASE WHEN s.status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed_count")
            ->selectRaw("SUM(CASE WHEN s.status = 'INCOMPLETE' THEN 1 ELSE 0 END) AS incomplete_count")
            ->selectRaw("SUM(CASE WHEN s.status = 'COMPLETED' THEN COALESCE(s.duration_seconds, 0) ELSE 0 END) AS completed_seconds")
            ->selectRaw('MIN(s.opened_at) AS first_check_in')
            ->selectRaw('MAX(s.closed_at) AS last_check_out');
        $events = DB::table('attendance_events as e')
            ->whereIn('e.student_profile_id', (clone $scopeIds))
            ->whereBetween('e.local_date', $range)
            ->groupBy('e.student_profile_id', 'e.local_date')
            ->select('e.student_profile_id', 'e.local_date')
            ->selectRaw("SUM(CASE WHEN e.verification_status = 'OUTSIDE_RADIUS' THEN 1 ELSE 0 END) AS rejected_count")
            ->selectRaw("SUM(CASE WHEN e.event_type IN ('FAILED_CHECK_IN', 'FAILED_CHECK_OUT') THEN 1 ELSE 0 END) AS failed_count");
        // At most one active mark per (student, day) (partial unique index), so this join never multiplies rows.
        $marks = DB::table('attendance_day_marks as m')
            ->whereIn('m.student_profile_id', (clone $scopeIds))
            ->whereBetween('m.local_date', $range)
            ->whereNull('m.revoked_at')
            ->select('m.student_profile_id', 'm.local_date', 'm.kind', 'm.id', 'm.note');

        $base = $base
            ->crossJoinSub($this->days($dates, $timezone), 'days')
            ->leftJoinSub($sessions, 'sa', fn ($join) => $join->on('sa.student_profile_id', '=', 'student_profiles.id')->on('sa.local_date', '=', 'days.d'))
            ->leftJoinSub($events, 'ea', fn ($join) => $join->on('ea.student_profile_id', '=', 'student_profiles.id')->on('ea.local_date', '=', 'days.d'))
            ->leftJoinSub($marks, 'mk', fn ($join) => $join->on('mk.student_profile_id', '=', 'student_profiles.id')->on('mk.local_date', '=', 'days.d'))
            ->select([
                'student_profiles.id as student_profile_id',
                'student_profiles.last_name',
                'student_profiles.first_name',
                'student_profiles.current_group_id',
                'days.d as local_date',
                'mk.kind as mark_kind',
                'mk.id as mark_id',
                'mk.note as mark_note',
            ])
            ->selectRaw($this->aggregatesSql());

        // Wrapped once more so callers can filter and sort on day_status (PostgreSQL does not see SELECT aliases in WHERE).
        $withStatus = DB::query()->fromSub($base, 'd')->select('d.*')->selectRaw(self::STATUS_SQL.' AS day_status');

        return DB::query()->fromSub($withStatus, 'r')->select('r.*');
    }

    /**
     * Status totals for one date, e.g. a dashboard card row.
     *
     * @param  EloquentBuilder<StudentProfile>|Builder  $students
     * @return array<string, int>
     */
    public function totals(EloquentBuilder|Builder $students, string $date, string $timezone): array
    {
        $counts = DB::query()
            ->fromSub($this->rows($students, [$date], $timezone), 'r')
            ->whereNotNull('r.day_status')
            ->groupBy('r.day_status')
            ->selectRaw('r.day_status, COUNT(*) AS total')
            ->pluck('total', 'day_status');

        $totals = [];
        foreach (['PRESENT', 'INCOMPLETE', 'LOCATION_REJECTED', 'EXCUSED', 'ABSENT'] as $status) {
            $totals[$status] = (int) ($counts[$status] ?? 0);
        }
        $totals['EXPECTED'] = array_sum($totals);

        return $totals;
    }

    /**
     * Per-student counts over a date range (reports and CSV).
     *
     * @param  EloquentBuilder<StudentProfile>|Builder  $students
     * @param  list<string>  $dates
     */
    public function summary(EloquentBuilder|Builder $students, array $dates, string $timezone): Builder
    {
        return DB::query()
            ->fromSub($this->rows($students, $dates, $timezone), 'r')
            ->groupBy('r.student_profile_id', 'r.last_name', 'r.first_name', 'r.current_group_id')
            ->havingRaw('SUM(CASE WHEN r.day_status IS NOT NULL THEN 1 ELSE 0 END) > 0')
            ->select('r.student_profile_id', 'r.last_name', 'r.first_name', 'r.current_group_id')
            ->selectRaw("SUM(CASE WHEN r.day_status = 'PRESENT' THEN 1 ELSE 0 END) AS present_days")
            ->selectRaw("SUM(CASE WHEN r.mark_kind = 'PRESENT' THEN 1 ELSE 0 END) AS marked_days")
            ->selectRaw("SUM(CASE WHEN r.day_status = 'EXCUSED' THEN 1 ELSE 0 END) AS excused_days")
            ->selectRaw("SUM(CASE WHEN r.day_status = 'INCOMPLETE' THEN 1 ELSE 0 END) AS incomplete_days")
            ->selectRaw("SUM(CASE WHEN r.day_status = 'LOCATION_REJECTED' THEN 1 ELSE 0 END) AS rejected_days")
            ->selectRaw("SUM(CASE WHEN r.day_status = 'ABSENT' THEN 1 ELSE 0 END) AS absent_days")
            ->selectRaw('SUM(r.failed_count) AS failed_attempts')
            ->selectRaw('SUM(r.completed_seconds) AS completed_seconds');
    }

    /**
     * Inclusive list of local dates, capped so a report cannot ask for an unbounded scan.
     *
     * @return list<string>
     */
    public static function dateRange(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) >= self::MAX_RANGE_DAYS) {
            $start = $end->subDays(self::MAX_RANGE_DAYS - 1);
        }

        $dates = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * One row per local date: d, its UTC bounds ds/de, and wbit, the date's weekday bit in a work-days mask.
     *
     * @param  list<string>  $dates
     */
    private function days(array $dates, string $timezone): Builder
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        if (DB::getDriverName() === 'pgsql' && count($dates) > 1 && $this->contiguous($dates)) {
            // A generated series keeps a year-long report one small statement instead of a 366-way UNION.
            return DB::query()
                ->fromRaw("generate_series(CAST(? AS date), CAST(? AS date), interval '1 day') AS g(day)", [$dates[0], $dates[count($dates) - 1]])
                ->selectRaw(
                    'CAST(g.day AS date) AS d, '
                    .'(CAST(g.day AS date)::timestamp AT TIME ZONE ?) AS ds, '
                    ."((CAST(g.day AS date) + 1)::timestamp AT TIME ZONE ? - interval '1 second') AS de, "
                    .'(1 << (EXTRACT(ISODOW FROM g.day)::int - 1)) AS wbit',
                    [$timezone, $timezone],
                );
        }

        $select = DB::getDriverName() === 'pgsql'
            ? 'CAST(? AS date) AS d, CAST(? AS timestamptz) AS ds, CAST(? AS timestamptz) AS de, CAST(? AS integer) AS wbit'
            : '? AS d, ? AS ds, ? AS de, ? AS wbit';

        $union = null;
        foreach ($dates as $date) {
            $local = CarbonImmutable::parse($date, $timezone);
            $query = DB::query()->selectRaw($select, [
                $date,
                $local->startOfDay()->utc()->format('Y-m-d H:i:s'),
                $local->endOfDay()->utc()->format('Y-m-d H:i:s'),
                WorkDays::bit($local->dayOfWeekIso),
            ]);
            $union = $union === null ? $query : $union->unionAll($query);
        }

        return $union ?? DB::query()->selectRaw($select, ['1970-01-01', '1970-01-01 00:00:00', '1970-01-01 00:00:00', 0])->whereRaw('1 = 0');
    }

    /**
     * @param  list<string>  $sorted
     */
    private function contiguous(array $sorted): bool
    {
        $first = CarbonImmutable::parse($sorted[0]);

        return $first->addDays(count($sorted) - 1)->toDateString() === $sorted[count($sorted) - 1];
    }

    private function aggregatesSql(): string
    {
        return implode(', ', [
            'COALESCE(sa.open_count, 0) AS open_count',
            'COALESCE(sa.completed_count, 0) AS completed_count',
            'COALESCE(sa.incomplete_count, 0) AS incomplete_count',
            'COALESCE(sa.completed_seconds, 0) AS completed_seconds',
            'sa.first_check_in',
            'sa.last_check_out',
            'COALESCE(ea.rejected_count, 0) AS rejected_count',
            'COALESCE(ea.failed_count, 0) AS failed_count',
            // Expected: an assignment in force that day, and the day is one of the student's work days
            // (the per-student override, else the internship's days).
            'CASE WHEN EXISTS (SELECT 1 FROM internship_assignments a'
            .' JOIN internships i ON i.id = a.internship_id'
            .' LEFT JOIN internship_participants p ON p.internship_id = a.internship_id AND p.student_profile_id = a.student_profile_id'
            ." WHERE a.student_profile_id = student_profiles.id AND a.status IN ('ACTIVE', 'ENDED')"
            .' AND a.start_at <= days.de AND COALESCE(a.ended_at, a.end_at) >= days.ds'
            .' AND (COALESCE(p.work_days, i.work_days) & days.wbit) <> 0'
            .') THEN 1 ELSE 0 END AS expected',
        ]);
    }
}
