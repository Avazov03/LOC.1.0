<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternshipChangeRequest extends Model
{
    protected $fillable = [
        'student_profile_id',
        'current_assignment_id',
        'request_type',
        'requested_organization_id',
        'requested_organization_data',
        'reason',
        'status',
        'initiated_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'request_type' => ChangeRequestType::class,
            'status' => ChangeRequestStatus::class,
            'requested_organization_data' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditState(): array
    {
        return [
            'status' => $this->status->value,
            'request_type' => $this->request_type->value,
            'current_assignment_id' => $this->current_assignment_id,
            'requested_organization_id' => $this->requested_organization_id,
            'reviewed_by' => $this->reviewed_by,
            'review_note' => $this->review_note,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function currentAssignment(): BelongsTo
    {
        return $this->belongsTo(InternshipAssignment::class, 'current_assignment_id');
    }

    public function requestedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'requested_organization_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
