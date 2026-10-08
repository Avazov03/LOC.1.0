<?php

namespace App\Models;

use App\Enums\AttendanceEventType;
use App\Enums\EventSource;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable fact (§76, A34). The database rejects UPDATE and DELETE.
 */
class AttendanceEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'student_profile_id',
        'assignment_id',
        'session_id',
        'event_type',
        'verification_status',
        'latitude',
        'longitude',
        'accuracy_meters',
        'distance_meters',
        'organization_latitude_snapshot',
        'organization_longitude_snapshot',
        'radius_snapshot_meters',
        'occurred_at',
        'local_date',
        'source',
        'telegram_update_id',
        'actor_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AttendanceEventType::class,
            'verification_status' => VerificationStatus::class,
            'source' => EventSource::class,
            'occurred_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
            'distance_meters' => 'float',
            'organization_latitude_snapshot' => 'float',
            'organization_longitude_snapshot' => 'float',
            'radius_snapshot_meters' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class, 'assignment_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
