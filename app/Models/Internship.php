<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Internship extends Model
{
    protected $fillable = [
        'university_id',
        'academic_year_id',
        'student_group_id',
        'period_start',
        'period_end',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'student_group_id');
    }

    public function supervisorPeriods(): HasMany
    {
        return $this->hasMany(InternshipSupervisorPeriod::class);
    }

    public function currentPeriod(): HasOne
    {
        return $this->hasOne(InternshipSupervisorPeriod::class)->whereNull('ends_on');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(InternshipInvite::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(InternshipParticipant::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InternshipAssignment::class);
    }
}
