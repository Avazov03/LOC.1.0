<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'university_id',
        'name',
        'type',
        'address',
        'location',
        'radius_meters',
        'status',
        'contact_name',
        'contact_phone',
        'contact_position',
        'website',
        'created_by',
    ];

    protected $hidden = ['location', 'location_wkt'];

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'radius_meters' => 'integer',
        ];
    }

    /**
     * @param  Builder<Organization>  $query
     */
    public function scopeWithCoordinates(Builder $query): void
    {
        $query->select('organizations.*')->addSelect(Geo::wktSelect('organizations.location'));
    }

    /**
     * Requires the withCoordinates scope.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function coordinates(): ?array
    {
        return Geo::parse($this->getAttribute('location_wkt'));
    }

    public function isActive(): bool
    {
        return $this->status === ActiveStatus::Active;
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InternshipAssignment::class);
    }
}
