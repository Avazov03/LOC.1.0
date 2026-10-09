<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UniversityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class University extends Model
{
    /** @use HasFactory<UniversityFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'timezone', 'reminder_time'];

    public function faculties(): HasMany
    {
        return $this->hasMany(Faculty::class);
    }

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Calendar date "today" in the university timezone (A6, A7).
     */
    public function today(): string
    {
        return now($this->timezone)->toDateString();
    }

    public function startOfLocalDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->startOfDay();
    }

    public function endOfLocalDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->endOfDay();
    }

    public function localDateTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $this->timezone);
    }
}
