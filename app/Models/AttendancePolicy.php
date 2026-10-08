<?php

namespace App\Models;

use App\Enums\ActiveStatus;
use App\Enums\PolicyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePolicy extends Model
{
    public const RULE_FIELDS = [
        'check_in_enabled',
        'check_out_enabled',
        'minimum_duration_minutes',
        'multiple_sessions_allowed',
        'location_required',
        'accuracy_threshold_meters',
        'manual_correction_allowed',
    ];

    /**
     * A32 defaults with multiple sessions OFF (A69). Used when a university has no ACTIVE policy row.
     */
    public const DEFAULTS = [
        'check_in_enabled' => true,
        'check_out_enabled' => true,
        'minimum_duration_minutes' => null,
        'multiple_sessions_allowed' => false,
        'location_required' => true,
        'accuracy_threshold_meters' => null,
        'manual_correction_allowed' => true,
    ];

    protected $fillable = [
        'university_id',
        'scope_type',
        'scope_id',
        ...self::RULE_FIELDS,
        'status',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => PolicyScope::class,
            'status' => ActiveStatus::class,
            'check_in_enabled' => 'boolean',
            'check_out_enabled' => 'boolean',
            'multiple_sessions_allowed' => 'boolean',
            'location_required' => 'boolean',
            'manual_correction_allowed' => 'boolean',
            'minimum_duration_minutes' => 'integer',
            'accuracy_threshold_meters' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->only(self::RULE_FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    public function auditState(): array
    {
        return [
            'scope_type' => $this->scope_type->value,
            'scope_id' => $this->scope_id,
            ...$this->rules(),
            'status' => $this->status->value,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'scope_id');
    }
}
