<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A university-wide day off. `date` stays a plain Y-m-d string so it compares directly with local dates in SQL.
 */
class UniversityHoliday extends Model
{
    protected $fillable = ['university_id', 'date', 'name', 'created_by'];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
}
