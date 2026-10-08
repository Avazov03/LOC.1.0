<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'university_id',
        'current_group_id',
        'student_code',
        'first_name',
        'last_name',
        'phone',
        'telegram_user_id',
        'status',
    ];

    protected function casts(): array
    {
        return ['status' => StudentStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function currentGroup(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'current_group_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StudentGroupMembership::class);
    }

    public function fullName(): string
    {
        return trim($this->last_name.' '.$this->first_name);
    }
}
