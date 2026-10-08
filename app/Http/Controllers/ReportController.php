<?php

namespace App\Http\Controllers;

use App\Models\ReportExport;
use App\Services\Attendance\AttendanceDayQuery;
use App\Services\Reports\AttendanceReportService;
use App\Support\AttendanceFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance report over a date range (A36, §53). Same scope as the attendance pages.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly AttendanceFilters $filters,
        private readonly AttendanceDayQuery $days,
        private readonly AttendanceReportService $reports,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tz = $user->university->timezone;
        $filters = $this->filters->fromRequest($request, $user, true);
        $dates = AttendanceDayQuery::dateRange($filters['from'], $filters['to']);

        $summary = $this->days->summary($this->filters->students($user, $filters), $dates, $tz);
        $page = DB::query()->fromSub($summary, 's')
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->orderBy('s.student_profile_id')
            ->paginate(25)
            ->withQueryString();

        $totals = DB::query()->fromSub($summary, 's')->selectRaw(
            'COUNT(*) AS students, COALESCE(SUM(present_days), 0) AS present, COALESCE(SUM(partial_days), 0) AS partial, COALESCE(SUM(incomplete_days), 0) AS incomplete, '.
            'COALESCE(SUM(rejected_days), 0) AS rejected, COALESCE(SUM(absent_days), 0) AS absent, COALESCE(SUM(failed_attempts), 0) AS failed, COALESCE(SUM(completed_seconds), 0) AS seconds'
        )->first();

        $ids = collect($page->items())->pluck('student_profile_id')->all();
        $organizations = $this->reports->organizations($ids);
        $groups = DB::table('student_profiles')->leftJoin('student_groups', 'student_groups.id', '=', 'student_profiles.current_group_id')
            ->whereIn('student_profiles.id', $ids)->pluck('student_groups.name', 'student_profiles.id');

        return Inertia::render('Reports/Index', [
            'filters' => $filters,
            'options' => $this->filters->options($user),
            'totals' => array_map('intval', (array) $totals),
            'rows' => $page->through(fn ($row) => [
                'student_id' => (int) $row->student_profile_id,
                'name' => trim($row->last_name.' '.$row->first_name),
                'group' => $groups[$row->student_profile_id] ?? null,
                'organization' => $organizations[$row->student_profile_id] ?? null,
                'present' => (int) $row->present_days,
                'partial' => (int) $row->partial_days,
                'incomplete' => (int) $row->incomplete_days,
                'rejected' => (int) $row->rejected_days,
                'absent' => (int) $row->absent_days,
                'failed' => (int) $row->failed_attempts,
                'seconds' => (int) $row->completed_seconds,
            ]),
            'exports' => ReportExport::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (ReportExport $export) => [
                    'id' => $export->id,
                    'status' => $export->status,
                    'type' => $export->filters['type'] ?? 'summary',
                    'from' => $export->filters['from'] ?? null,
                    'to' => $export->filters['to'] ?? null,
                    'rows' => $export->rows,
                    'created_at' => $export->created_at->copy()->setTimezone($tz)->format('d.m.Y H:i'),
                ]),
            'maxDays' => AttendanceDayQuery::MAX_RANGE_DAYS,
            'today' => $user->university->today(),
        ]);
    }

    public function export(Request $request): RedirectResponse
    {
        $user = $request->user();
        $filters = $this->filters->fromRequest($request, $user, true);
        $type = $request->string('type')->toString();
        $this->reports->request($user, $filters, $type);

        return back()->with('success', 'Hisobot navbatga qo‘yildi. Tayyor bo‘lgach, quyidagi ro‘yxatdan yuklab oling.');
    }

    public function download(Request $request, int $export): StreamedResponse
    {
        $row = ReportExport::query()->where('user_id', $request->user()->id)->findOrFail($export);
        abort_unless($row->status === 'DONE' && $row->path && Storage::disk('local')->exists($row->path), 404);

        $name = 'davomat-'.($row->filters['type'] ?? 'summary').'-'.($row->filters['from'] ?? '').'-'.($row->filters['to'] ?? '').'.csv';

        return Storage::disk('local')->download($row->path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
