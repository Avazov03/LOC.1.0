<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Append-only audit trail (§70). Call inside the same transaction as the change it records.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        ?User $actor,
        string $action,
        Model $entity,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        array $metadata = [],
        ?int $universityId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'university_id' => $universityId ?? $entity->getAttribute('university_id') ?? $actor?->university_id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => Str::snake(class_basename($entity)),
            'entity_id' => $entity->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => app()->bound('request') ? request()->ip() : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    public function lastLogin(User $user): ?CarbonInterface
    {
        $value = AuditLog::query()
            ->where('entity_type', 'user')
            ->where('entity_id', $user->id)
            ->where('action', 'auth.login')
            ->latest('created_at')
            ->value('created_at');

        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    /**
     * Latest entries for one entity, limited to the viewer's university.
     *
     * @return Collection<int, AuditLog>
     */
    public function history(User $viewer, Model $entity, int $limit = 50): Collection
    {
        return AuditLog::query()
            ->where('university_id', $viewer->university_id)
            ->where('entity_type', Str::snake(class_basename($entity)))
            ->where('entity_id', $entity->getKey())
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
