<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceEventType;
use App\Enums\DayStatus;
use App\Enums\VerificationStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\InternshipAssignment;
use App\Models\StudentProfile;
use App\Services\Access\AccessScope;
use App\Services\Attendance\AttendanceCorrectionService;
use App\Services\Attendance\AttendanceDayQuery;
use App\Services\Attendance\AttendancePolicyService;
use App\Support\AttendanceFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance for staff (§50, §51, §93, §94). Admin sees the university, a supervisor only their own students;
 * an out-of-scope student id is 404. Corrections are admin-only (A33).
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AttendanceDayQuery $days,
        private readonly AttendanceFilters $filters,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tz = $user->university->timezone;
        $filters = $this->filters->fromRequest($request, $user, false);
        $students = $this->filters->students($user, $filters);

        $rows = $this->days->rows($students, [$filters['date']], $tz);
        $listed = DB::query()->fromSub($rows, 'x')
            ->whereNotNull('x.day_status')
            ->when($filters['status'], fn ($q, $status) => $q->where('x.day_status', $status))
            ->orderBy('x.last_name')
            ->orderBy('x.first_name')
            ->orderBy('x.student_profile_id');

        /** @var LengthAwarePaginator $page */
        $page = $listed->paginate(25)->withQueryString();
        $context = $this->context(collect($page->items())->pluck('student_profile_id')->all(), $filters['date'], $tz);

        return Inertia::render('Attendance/Index', [
            'filters' => $filters,
            'options' => $this->filters->options($user),
            'totals' => $this->days->totals($this->filters->students($user, $filters), $filters['date'], $tz),
            'rows' => $page->through(fn ($row) => $this->presentDay($row, $context, $tz)),
            'today' => $user->university->today(),
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function student(Request $request, int $student, AttendancePolicyService $policies): Response
    {
        $user = $request->user();
        $profile = $this->scope->findStudent($user, $student)->load(['currentGroup:id,name', 'university:id,timezone']);
        $tz = $profile->university->timezone;
        $today = $profile->university->today();

        $to = $this->validDate($request->query('to')) ?? $today;
        $to = min($to, $today);
        $from = $this->validDate($request->query('from')) ?? CarbonImmutable::parse($to)->subDays(13)->toDateString();
        $dates = AttendanceDayQuery::dateRange(min($from, $to), $to);
        [$from, $to] = [$dates[0], $dates[count($dates) - 1]];

        $days = $this->days->rows(StudentProfile::query()->whereKey($profile->id), $dates, $tz)
            ->orderByDesc('local_date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->local_date,
                'status' => $row->day_status,
                'first_check_in' => $this->time($row->first_check_in, $tz),
                'last_check_out' => $this->time($row->last_check_out, $tz),
                'completed_seconds' => (int) $row->completed_seconds,
                'failed_count' => (int) $row->failed_count,
            ])
            ->values();

        $sessions = AttendanceSession::query()
            ->where('student_profile_id', $profile->id)
            ->whereBetween('local_date', [$from, $to])
            ->with('assignment.organization:id,name')
            ->orderByDesc('opened_at')
            ->get()
            ->map(fn (AttendanceSession $session) => [
                'id' => $session->id,
                'date' => (string) $session->local_date,
                'status' => $session->status->value,
                'opened_at' => $session->opened_at->copy()->setTimezone($tz)->format('H:i'),
                'closed_at' => $session->closed_at?->copy()->setTimezone($tz)->format('H:i'),
                'duration_seconds' => $session->duration_seconds,
                'organization' => $session->assignment?->organization?->name,
            ]);

        $events = AttendanceEvent::query()
            ->where('student_profile_id', $profile->id)
            ->whereBetween('local_date', [$from, $to])
            ->with(['actor:id,name', 'assignment.organization:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (AttendanceEvent $event) => $this->presentEvent($event, $tz));

        $policy = $policies->resolve($profile);

        return Inertia::render('Attendance/Student', [
            'student' => [
                'id' => $profile->id,
                'name' => $profile->fullName(),
                'group' => $profile->currentGroup?->name,
                'student_code' => $profile->student_code,
                'phone' => $profile->phone,
                'status' => $profile->status->value,
            ],
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'days' => $days,
            'sessions' => $sessions,
            'events' => $events,
            'policy' => ['source' => $policy['source'], ...$policy['rules']],
            'canCorrect' => $user->isAdmin() && $policy['rules']['manual_correction_allowed'],
            'statuses' => $this->statusOptions(),
            'backUrl' => $user->isAdmin() ? '/academic/students/'.$profile->id : '/students/'.$profile->id,
        ]);
    }

    public function storeCorrection(Request $request, int $student, AttendanceCorrectionService $corrections): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $corrections->addSession($request->user(), $student, $data['date'], $data['check_in'], $data['check_out'] ?? null, $data['reason']);

        return back()->with('success', 'Tuzatish saqlandi va audit jurnaliga yozildi.');
    }

    public function closeSession(Request $request, int $session, AttendanceCorrectionService $corrections): RedirectResponse
    {
        $data = $request->validate([
            'check_out' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $corrections->closeSession($request->user(), $session, $data['check_out'], $data['reason']);

        return back()->with('success', 'Sessiya yopildi va audit jurnaliga yozildi.');
    }

    /**
     * Group and organization for the listed students on that date: the assignment in force then, else the open one.
     *
     * @param  list<int>  $ids
     * @return array<int, array{group: ?string, organization: ?string}>
     */
    private function context(array $ids, string $date, string $tz): array
    {
        if ($ids === []) {
            return [];
        }
        $dayStart = CarbonImmutable::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = CarbonImmutable::parse($date, $tz)->endOfDay()->utc();

        $students = StudentProfile::query()->whereIn('id', $ids)->with('currentGroup:id,name')->get(['id', 'current_group_id'])->keyBy('id');
        $assignments = InternshipAssignment::query()
            ->whereIn('student_profile_id', $ids)
            ->whereIn('status', ['PENDING', 'ACTIVE', 'ENDED'])
            ->with('organization:id,name')
            ->orderByDesc('start_at')
            ->get()
            ->groupBy('student_profile_id');

        $context = [];
        foreach ($ids as $id) {
            /** @var Collection<int, InternshipAssignment> $list */
            $list = $assignments->get($id, collect());
            $inForce = $list->first(fn (InternshipAssignment $a) => $a->start_at->lessThanOrEqualTo($dayEnd) && ($a->ended_at ?? $a->end_at)->greaterThanOrEqualTo($dayStart) && $a->status->value !== 'PENDING')
                ?? $list->first(fn (InternshipAssignment $a) => in_array($a->status->value, ['PENDING', 'ACTIVE'], true));
            $context[$id] = [
                'group' => $students->get($id)?->currentGroup?->name,
                'organization' => $inForce?->organization?->name,
            ];
        }

        return $context;
    }

    /**
     * @param  array<int, array{group: ?string, organization: ?string}>  $context
     * @return array<string, mixed>
     */
    private function presentDay(object $row, array $context, string $tz): array
    {
        $id = (int) $row->student_profile_id;

        return [
            'student_id' => $id,
            'name' => trim($row->last_name.' '.$row->first_name),
            'group' => $context[$id]['group'] ?? null,
            'organization' => $context[$id]['organization'] ?? null,
            'status' => $row->day_status,
            'first_check_in' => $this->time($row->first_check_in, $tz),
            'last_check_out' => $this->time($row->last_check_out, $tz),
            'completed_seconds' => (int) $row->completed_seconds,
            'open' => (int) $row->open_count > 0,
            'failed_count' => (int) $row->failed_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEvent(AttendanceEvent $event, string $tz): array
    {
        $metadata = $event->metadata ?? [];

        return [
            'id' => $event->id,
            'date' => (string) $event->local_date,
            'occurred_at' => $event->occurred_at->copy()->setTimezone($tz)->format('d.m.Y H:i:s'),
            'recorded_at' => $event->created_at?->copy()->setTimezone($tz)->format('d.m.Y H:i'),
            'type' => $event->event_type->value,
            'type_label' => $event->event_type === AttendanceEventType::ManualCorrection
                ? 'Qo‘lda tuzatish ('.(($metadata['kind'] ?? '') === 'CHECK_OUT' ? 'ketish' : 'kelish').')'
                : $event->event_type->label(),
            'verification' => $event->verification_status->value,
            'verification_label' => $event->verification_status->label(),
            'verified' => $event->verification_status === VerificationStatus::Verified,
            'distance' => $event->distance_meters,
            'accuracy' => $event->accuracy_meters,
            'radius' => $event->radius_snapshot_meters,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'source' => $event->source->value,
            'organization' => $event->assignment?->organization?->name,
            'actor' => $event->actor?->name,
            'reason' => $metadata['reason'] ?? null,
            'flags' => array_values(array_filter([
                ! empty($metadata['location_override']) ? 'Joylashuv talab qilinmagan (admin)' : null,
                ! empty($metadata['live_location']) ? 'Jonli joylashuv' : null,
                ! empty($metadata['forwarded']) ? 'Uzatilgan joylashuv' : null,
            ])),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(fn (DayStatus $status) => ['value' => $status->value, 'label' => $status->label()], DayStatus::cases());
    }

    private function time(mixed $value, string $tz): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->setTimezone($tz)->format('H:i');
    }

    private function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }
}
