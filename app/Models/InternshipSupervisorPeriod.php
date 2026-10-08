<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternshipSupervisorPeriod extends Model
{
    protected $fillable = ['internship_id', 'supervisor_profile_id', 'starts_on', 'ends_on', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(SupervisorProfile::class, 'supervisor_profile_id');
    }
}
