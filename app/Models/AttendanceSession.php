<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    protected $fillable = [
        'student_profile_id',
        'assignment_id',
        'local_date',
        'check_in_event_id',
        'check_out_event_id',
        'duration_seconds',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditState(): array
    {
        return [
            'local_date' => $this->local_date,
            'status' => $this->status->value,
            'check_in_event_id' => $this->check_in_event_id,
            'check_out_event_id' => $this->check_out_event_id,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
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

    public function checkInEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class, 'check_in_event_id');
    }

    public function checkOutEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class, 'check_out_event_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class, 'session_id');
    }
}
