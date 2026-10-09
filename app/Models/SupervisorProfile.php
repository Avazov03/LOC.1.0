<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupervisorProfile extends Model
{
    protected $fillable = ['user_id', 'university_id', 'phone', 'position', 'notify_check_events'];

    protected $hidden = ['telegram_link_hash'];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'telegram_linked_at' => 'datetime',
            'telegram_link_expires_at' => 'datetime',
            'notify_check_events' => 'boolean',
        ];
    }

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
