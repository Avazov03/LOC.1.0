<?php

namespace App\Services\Reports;

use App\Enums\DayMarkKind;
use App\Enums\DayStatus;
use App\Jobs\GenerateAttendanceReport;
use App\Models\InternshipAssignment;
use App\Models\ReportExport;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Attendance\AttendanceDayQuery;
use App\Support\AttendanceFilters;
use App\Telegram\BotText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A36: reports are the filtered attendance summary and a CSV of the caller's own scope.
 */
class AttendanceReportService
{
    public const TYPES = ['summary', 'daily'];

    private const CHUNK = 200;

    public function __construct(
        private readonly AttendanceFilters $filters,
        private readonly AttendanceDayQuery $days,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function request(User $actor, array $filters, string $type): ReportExport
    {
        $export = ReportExport::query()->create([
            'university_id' => $actor->university_id,
            'user_id' => $actor->id,
            'filters' => [...$filters, 'type' => in_array($type, self::TYPES, true) ? $type : 'summary'],
            'status' => 'PENDING',
        ]);
        GenerateAttendanceReport::dispatch($export->id)->afterCommit();

        return $export;
    }

    /**
     * Streams rows straight to a file in chunks of students, so memory stays flat for a whole university.
     * Scope is re-evaluated at run time: a user who lost access gets an empty file, never someone else's data.
     */
    public function build(ReportExport $export): void
    {
        $user = User::query()->with('university')->findOrFail($export->user_id);
        $filters = $export->filters;
        $tz = $user->university->timezone;
        $dates = AttendanceDayQuery::dateRange($filters['from'], $filters['to']);
        $daily = ($filters['type'] ?? 'summary') === 'daily';

        $path = 'exports/'.$export->id.'-'.Str::random(24).'.csv';
        $disk = Storage::disk('local');
        $disk->makeDirectory('exports');
        $handle = fopen($disk->path($path), 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $daily
            ? ['Sana', 'F.I.Sh.', 'Talaba ID', 'Guruh', 'Tashkilot', 'Holat', 'Kelish', 'Ketish', 'Davomiylik', 'Davomiylik (daqiqa)', 'Rahbar belgisi', 'Izoh', 'Rad etilgan urinishlar']
            : ['F.I.Sh.', 'Talaba ID', 'Telefon', 'Guruh', 'Tashkilot', 'Keldi', 'shundan rahbar belgilagan', 'Sababli', 'Yakunlanmagan', 'Joylashuv rad etildi', 'Kelmadi', 'Rad etilgan urinishlar', 'Jami vaqt', 'Jami soat'], ',', '"', '');

        $rows = 0;
        $this->filters->students($user, $filters)
            ->select('student_profiles.id')
            ->orderBy('student_profiles.id')
            ->chunk(self::CHUNK, function ($chunk) use ($handle, $dates, $tz, $daily, $filters, &$rows) {
                $ids = $chunk->pluck('id')->all();
                $students = StudentProfile::query()->whereIn('id', $ids)->with('currentGroup:id,name')->get()->keyBy('id');
                $organizations = $this->organizations($ids);
                $scope = StudentProfile::query()->whereIn('student_profiles.id', $ids);

                if ($daily) {
                    $query = $this->days->rows($scope, $dates, $tz)->whereNotNull('day_status')
                        ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('day_status', $status))
                        ->orderBy('local_date')->orderBy('last_name')->orderBy('first_name');
                    foreach ($query->cursor() as $row) {
                        $student = $students->get($row->student_profile_id);
                        fputcsv($handle, array_map([$this, 'cell'], [
                            (string) $row->local_date,
                            $student?->fullName(),
                            $student?->student_code,
                            $student?->currentGroup?->name,
                            $organizations[$row->student_profile_id] ?? null,
                            DayStatus::from($row->day_status)->label(),
                            $this->time($row->first_check_in, $tz),
                            $this->time($row->last_check_out, $tz),
                            BotText::duration((int) $row->completed_seconds),
                            intdiv((int) $row->completed_seconds, 60),
                            $row->mark_kind !== null ? DayMarkKind::from($row->mark_kind)->label() : null,
                            $row->mark_note,
                            (int) $row->failed_count,
                        ]), ',', '"', '');
                        $rows++;
                    }

                    return;
                }

                foreach ($this->days->summary($scope, $dates, $tz)->orderBy('r.last_name')->orderBy('r.first_name')->get() as $row) {
                    $student = $students->get($row->student_profile_id);
                    fputcsv($handle, array_map([$this, 'cell'], [
                        $student?->fullName(),
                        $student?->student_code,
                        $student?->phone,
                        $student?->currentGroup?->name,
                        $organizations[$row->student_profile_id] ?? null,
                        (int) $row->present_days,
                        (int) $row->marked_days,
                        (int) $row->excused_days,
                        (int) $row->incomplete_days,
                        (int) $row->rejected_days,
                        (int) $row->absent_days,
                        (int) $row->failed_attempts,
                        BotText::duration((int) $row->completed_seconds),
                        round(((int) $row->completed_seconds) / 3600, 2),
                    ]), ',', '"', '');
                    $rows++;
                }
            });
        fclose($handle);

        $export->forceFill(['status' => 'DONE', 'path' => $path, 'rows' => $rows, 'finished_at' => now(), 'error' => null])->save();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function organizations(array $ids): array
    {
        return InternshipAssignment::query()
            ->whereIn('student_profile_id', $ids)
            ->whereIn('status', ['PENDING', 'ACTIVE', 'ENDED'])
            ->with('organization:id,name')
            ->orderBy('start_at')
            ->get()
            ->mapWithKeys(fn (InternshipAssignment $assignment) => [$assignment->student_profile_id => $assignment->organization?->name])
            ->all();
    }

    /**
     * CSV formula injection guard: a cell that a spreadsheet would execute is prefixed with an apostrophe.
     */
    public function cell(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function time(mixed $value, string $tz): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->setTimezone($tz)->format('H:i');
    }
}
