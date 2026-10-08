<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\AttendanceReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAttendanceReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $exportId) {}

    public function handle(AttendanceReportService $reports): void
    {
        $export = ReportExport::query()->find($this->exportId);
        if ($export === null || $export->status === 'DONE') {
            return;
        }
        $export->forceFill(['status' => 'RUNNING'])->save();
        $reports->build($export);
    }

    public function failed(?Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->exportId)->update([
            'status' => 'FAILED',
            'error' => 'Hisobotni tayyorlab bo‘lmadi.',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
