<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    protected $fillable = ['university_id', 'name', 'status'];

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
