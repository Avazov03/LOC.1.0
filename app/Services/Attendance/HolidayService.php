<?php

namespace App\Services\Attendance;

use App\Exceptions\BusinessRuleException;
use App\Models\UniversityHoliday;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * University-wide days off (holidays, exam days). On such a day no check-in is taken and nobody is counted ABSENT
 * (AttendanceDayQuery "expected"); anything recorded before the day was added stays as it is.
 */
class HolidayService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Last month and everything ahead, oldest first.
     *
     * @return Collection<int, UniversityHoliday>
     */
    public function list(User $actor): Collection
    {
        return UniversityHoliday::query()
            ->where('university_id', $actor->university_id)
            ->where('date', '>=', CarbonImmutable::parse($actor->university->today())->subDays(31)->toDateString())
            ->orderBy('date')
            ->get();
    }

    public function add(User $actor, string $date, string $name): UniversityHoliday
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $date, $name) {
            $exists = UniversityHoliday::query()->where('university_id', $actor->university_id)->where('date', $date)->lockForUpdate()->exists();
            if ($exists) {
                throw new BusinessRuleException('Bu sana allaqachon dam olish kuni sifatida qo‘shilgan.');
            }
            $holiday = UniversityHoliday::query()->create([
                'university_id' => $actor->university_id,
                'date' => $date,
                'name' => trim($name),
                'created_by' => $actor->id,
            ]);
            $this->audit->log($actor, 'holiday.create', $holiday, after: ['date' => $date, 'name' => $holiday->name], universityId: $actor->university_id);

            return $holiday;
        });
    }

    public function remove(User $actor, int $holidayId): void
    {
        $this->scope->requireAdmin($actor);

        DB::transaction(function () use ($actor, $holidayId) {
            $holiday = UniversityHoliday::query()->where('university_id', $actor->university_id)->lockForUpdate()->findOrFail($holidayId);
            $this->audit->log($actor, 'holiday.delete', $holiday, before: ['date' => $holiday->date, 'name' => $holiday->name], universityId: $actor->university_id);
            $holiday->delete();
        });
    }

    public function nameOn(int $universityId, string $date): ?string
    {
        return UniversityHoliday::query()->where('university_id', $universityId)->where('date', $date)->value('name');
    }
}
