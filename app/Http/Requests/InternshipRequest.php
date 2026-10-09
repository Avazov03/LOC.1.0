<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InternshipRequest extends FormRequest
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
        return [
            'student_group_id' => ['required', 'integer'],
            'supervisor_profile_id' => ['required', 'integer'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'work_days' => ['sometimes', 'array', 'min:1', 'max:7'],
            'work_days.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }
}
