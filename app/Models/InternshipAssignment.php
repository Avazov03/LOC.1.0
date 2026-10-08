<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternshipAssignment extends Model
{
    protected $fillable = [
        'student_profile_id',
        'organization_id',
        'supervisor_profile_id',
        'internship_id',
        'start_at',
        'end_at',
        'status',
        'created_by',
        'ended_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditState(): array
    {
        return [
            'student_profile_id' => $this->student_profile_id,
            'organization_id' => $this->organization_id,
            'supervisor_profile_id' => $this->supervisor_profile_id,
            'internship_id' => $this->internship_id,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'status' => $this->status->value,
            'ended_at' => $this->ended_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(SupervisorProfile::class, 'supervisor_profile_id');
    }

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }
}
