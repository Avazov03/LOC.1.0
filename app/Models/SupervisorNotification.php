<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisorNotification extends Model
{
    protected $fillable = ['key', 'supervisor_profile_id', 'text', 'payload', 'status', 'attempts', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(SupervisorProfile::class, 'supervisor_profile_id');
    }
}
