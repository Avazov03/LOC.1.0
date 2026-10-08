<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicYearStatus;
use App\Enums\ActiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcademicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'faculties.store', 'faculties.update' => [
                'name' => ['required', 'string', 'max:255'],
                'status' => ['sometimes', Rule::enum(ActiveStatus::class)],
            ],
            'programs.store', 'programs.update' => [
                'faculty_id' => ['required', 'integer'],
                'name' => ['required', 'string', 'max:255'],
                'status' => ['sometimes', Rule::enum(ActiveStatus::class)],
            ],
            'years.store', 'years.update' => [
                'name' => ['required', 'string', 'max:32'],
                'starts_on' => ['required', 'date'],
                'ends_on' => ['required', 'date'],
                'status' => ['sometimes', Rule::enum(AcademicYearStatus::class)],
            ],
            'study-years.store' => [
                'program_id' => ['required', 'integer'],
                'academic_year_id' => ['required', 'integer'],
                'course_number' => ['required', 'integer', 'min:1', 'max:8'],
                'name' => ['required', 'string', 'max:100'],
            ],
            'study-years.update' => [
                'course_number' => ['required', 'integer', 'min:1', 'max:8'],
                'name' => ['required', 'string', 'max:100'],
            ],
            'groups.store' => [
                'study_year_id' => ['required', 'integer'],
                'name' => ['required', 'string', 'max:100'],
                'code' => ['required', 'string', 'max:32'],
            ],
            'groups.update' => [
                'name' => ['required', 'string', 'max:100'],
                'code' => ['required', 'string', 'max:32'],
            ],
            default => [],
        };
    }
}
