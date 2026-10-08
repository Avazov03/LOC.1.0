<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramNotification extends Model
{
    protected $fillable = ['key', 'student_profile_id', 'text', 'status', 'attempts', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }
}
