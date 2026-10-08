<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupervisorProfile extends Model
{
    protected $fillable = ['user_id', 'university_id', 'phone', 'position'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(InternshipSupervisorPeriod::class);
    }
}
