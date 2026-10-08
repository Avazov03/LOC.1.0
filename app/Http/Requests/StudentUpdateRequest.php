<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{7,32}$/'],
            'student_code' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
