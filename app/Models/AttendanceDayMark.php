<?php

namespace App\Models;

use App\Enums\DayMarkKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDayMark extends Model
{
    protected $fillable = ['student_profile_id', 'assignment_id', 'local_date', 'kind', 'note', 'source', 'marked_by', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return [
            'kind' => DayMarkKind::class,
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditState(): array
    {
        return [
            'student_profile_id' => $this->student_profile_id,
            'local_date' => (string) $this->local_date,
            'kind' => $this->kind->value,
            'note' => $this->note,
            'source' => $this->source,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
