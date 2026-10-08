<?php

namespace App\Http\Requests;

use App\Services\Assignments\InternshipAssignmentService;
use Illuminate\Foundation\Http\FormRequest;

class AssignmentRequest extends FormRequest
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
            'internship_id' => ['required', 'integer'],
            'organization_id' => ['required', 'integer'],
            'student_ids' => ['required', 'array', 'min:1', 'max:'.InternshipAssignmentService::MAX_BATCH],
            'student_ids.*' => ['integer', 'distinct'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_ids.required' => 'Kamida bitta talabani tanlang.',
            'student_ids.max' => 'Bir martada '.InternshipAssignmentService::MAX_BATCH.' tadan ortiq talaba tanlab bo‘lmaydi.',
        ];
    }
}
