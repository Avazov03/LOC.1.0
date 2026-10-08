<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    protected $fillable = ['university_id', 'user_id', 'filters', 'status', 'path', 'rows', 'error', 'finished_at'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'rows' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
