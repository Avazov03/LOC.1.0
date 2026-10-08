<?php

namespace App\Services\Academic;

use App\Models\University;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * University name and timezone (A37). Instants are stored in UTC (A51), so a timezone change only changes
 * how dates are shown and how new local dates are read; nothing stored is rewritten.
 */
class UniversitySettingsService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function update(User $actor, string $name, string $timezone): University
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $name, $timezone) {
            $university = University::query()->lockForUpdate()->findOrFail($actor->university_id);
            $before = ['name' => $university->name, 'timezone' => $university->timezone];
            $after = ['name' => trim($name), 'timezone' => $timezone];

            if ($before !== $after) {
                $university->update($after);
                $this->audit->log($actor, 'university.update', $university, $before, $after, universityId: $university->id);
            }

            return $university;
        });
    }
}
