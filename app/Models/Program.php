<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $fillable = ['faculty_id', 'name', 'status'];

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function studyYears(): HasMany
    {
        return $this->hasMany(StudyYear::class);
    }
}
