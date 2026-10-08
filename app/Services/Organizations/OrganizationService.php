<?php

namespace App\Services\Organizations;

use App\Enums\ActiveStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use App\Support\Geo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only organization management (§16–§18, §55, §56). Supervisors and students never reach this service.
 */
class OrganizationService
{
    private const FIELDS = ['name', 'type', 'address', 'contact_name', 'contact_phone', 'contact_position', 'website'];

    public function __construct(private readonly AuditLogger $audit, private readonly AccessScope $scope) {}

    public function paginate(User $actor, ?string $search, ?string $status): LengthAwarePaginator
    {
        return $this->query($actor)
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query->where('status', 'ACTIVE')])
            ->when($search, fn (Builder $query, string $term) => $query->where(
                fn (Builder $q) => $q->whereLike('name', "%{$term}%")->orWhereLike('address', "%{$term}%"),
            ))
            ->when($status, fn (Builder $query, string $value) => $query->where('status', $value))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Picker for assignment forms. No coordinates leave the admin form (API-CONTRACT §2).
     *
     * @return Collection<int, array{id: int, name: string, address: string}>
     */
    public function activeOptions(User $actor): Collection
    {
        return $this->query($actor)
            ->where('status', ActiveStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name', 'address'])
            ->map(fn (Organization $organization) => ['id' => $organization->id, 'name' => $organization->name, 'address' => $organization->address]);
    }

    public function find(User $actor, int $id): Organization
    {
        return $this->query($actor)->withCoordinates()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  validated fields plus latitude, longitude, radius_meters
     */
    public function create(User $actor, array $data): Organization
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $data) {
            $organization = Organization::query()->create([
                ...$this->attributes($data),
                'university_id' => $actor->university_id,
                'status' => $data['status'] ?? ActiveStatus::Active->value,
                'location' => Geo::point((float) $data['latitude'], (float) $data['longitude']),
                'radius_meters' => (int) $data['radius_meters'],
                'created_by' => $actor->id,
            ]);
            $organization = $this->query($actor)->withCoordinates()->findOrFail($organization->id);

            $this->audit->log($actor, 'organization.create', $organization, null, $this->state($organization));

            return $organization;
        });
    }

    /**
     * Location and radius changes are separate audit actions (§70). Past events keep their own snapshots (§55, §56).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, int $id, array $data): Organization
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $id, $data) {
            $organization = $this->query($actor)->withCoordinates()->lockForUpdate()->findOrFail($id);
            $before = $this->state($organization);

            $attributes = $this->attributes($data);
            if (isset($data['status'])) {
                $attributes['status'] = $data['status'];
            }

            $newLatitude = round((float) $data['latitude'], 7);
            $newLongitude = round((float) $data['longitude'], 7);
            $locationChanged = $before['latitude'] !== $newLatitude || $before['longitude'] !== $newLongitude;
            $radiusChanged = $before['radius_meters'] !== (int) $data['radius_meters'];

            if ($locationChanged) {
                $attributes['location'] = Geo::point($newLatitude, $newLongitude);
            }
            $attributes['radius_meters'] = (int) $data['radius_meters'];

            $organization->update($attributes);
            $organization = $this->query($actor)->withCoordinates()->findOrFail($id);
            $after = $this->state($organization);

            $fieldsBefore = array_intersect_key($before, array_flip(self::FIELDS));
            $fieldsAfter = array_intersect_key($after, array_flip(self::FIELDS));
            if ($fieldsBefore !== $fieldsAfter) {
                $this->audit->log($actor, 'organization.update', $organization, $fieldsBefore, $fieldsAfter);
            }
            if ($locationChanged) {
                $this->audit->log(
                    $actor,
                    'organization.location_update',
                    $organization,
                    ['latitude' => $before['latitude'], 'longitude' => $before['longitude']],
                    ['latitude' => $after['latitude'], 'longitude' => $after['longitude']],
                );
            }
            if ($radiusChanged) {
                $this->audit->log($actor, 'organization.radius_update', $organization, ['radius_meters' => $before['radius_meters']], ['radius_meters' => $after['radius_meters']]);
            }
            if ($before['status'] !== $after['status']) {
                $this->audit->log($actor, 'organization.status_change', $organization, ['status' => $before['status']], ['status' => $after['status']]);
            }

            return $organization;
        });
    }

    /**
     * ACTIVE ⇄ INACTIVE (§134, A21). Never deleted. Existing assignments are not touched; new ones are refused.
     */
    public function setStatus(User $actor, int $id, ActiveStatus $status): Organization
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $id, $status) {
            $organization = $this->query($actor)->lockForUpdate()->findOrFail($id);
            $before = $organization->status;
            if ($before !== $status) {
                $organization->update(['status' => $status]);
                $this->audit->log($actor, 'organization.status_change', $organization, ['status' => $before->value], ['status' => $status->value]);
            }

            return $organization;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function state(Organization $organization): array
    {
        $coordinates = $organization->coordinates();

        return [
            'name' => $organization->name,
            'type' => $organization->type,
            'address' => $organization->address,
            'contact_name' => $organization->contact_name,
            'contact_phone' => $organization->contact_phone,
            'contact_position' => $organization->contact_position,
            'website' => $organization->website,
            'status' => $organization->status->value,
            'latitude' => $coordinates['latitude'] ?? null,
            'longitude' => $coordinates['longitude'] ?? null,
            'radius_meters' => $organization->radius_meters,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'address' => $data['address'],
            'contact_name' => $data['contact_name'],
            'contact_phone' => $data['contact_phone'],
            'contact_position' => ($data['contact_position'] ?? null) ?: null,
            'website' => ($data['website'] ?? null) ?: null,
        ];
    }

    /**
     * @return Builder<Organization>
     */
    private function query(User $actor): Builder
    {
        return Organization::query()->where('organizations.university_id', $actor->university_id);
    }
}
