<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternshipParticipant extends Model
{
    protected $fillable = ['internship_id', 'student_profile_id', 'internship_invite_id', 'joined_at', 'work_days'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'work_days' => 'integer'];
    }

    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }
}
