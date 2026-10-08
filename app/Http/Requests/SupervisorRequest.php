<?php

namespace App\Http\Requests;

use App\Models\SupervisorProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupervisorRequest extends FormRequest
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
        $userId = null;
        if ($this->route('supervisor') !== null) {
            $userId = SupervisorProfile::query()
                ->where('university_id', $this->user()->university_id)
                ->whereKey((int) $this->route('supervisor'))
                ->value('user_id');
            abort_if($userId === null, 404);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'login' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'login')->ignore($userId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$userId === null ? 'required' : 'nullable', 'string', 'min:8', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'position' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.unique' => 'Bu login band.',
            'email.unique' => 'Bu email band.',
            'login.regex' => 'Login faqat lotin harflari, raqamlar, nuqta, chiziqcha va pastki chiziqdan iborat bo‘lsin.',
        ];
    }
}
