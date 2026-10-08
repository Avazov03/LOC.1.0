<?php

namespace App\Models;

use App\Enums\InviteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternshipInvite extends Model
{
    protected $fillable = [
        'internship_id',
        'token_hash',
        'academic_year_id',
        'student_group_id',
        'supervisor_profile_id',
        'created_by',
        'status',
        'expires_at',
        'closed_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'status' => InviteStatus::class,
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * A stored ACTIVE row whose expires_at has passed behaves as EXPIRED (A18).
     */
    public function effectiveStatus(): InviteStatus
    {
        if ($this->status === InviteStatus::Active && $this->expires_at !== null && $this->expires_at->isPast()) {
            return InviteStatus::Expired;
        }

        return $this->status;
    }

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'student_group_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(SupervisorProfile::class, 'supervisor_profile_id');
    }
}
