<?php

namespace App\Http\Requests;

use App\Enums\ChangeRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The organization payload is passed through untouched so the service can refuse coordinate keys (A23).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request_type' => ['required', Rule::enum(ChangeRequestType::class)],
            'organization_id' => ['required_if:request_type,EXISTING_ORGANIZATION', 'nullable', 'integer'],
            'organization' => ['required_if:request_type,NEW_ORGANIZATION', 'nullable', 'array'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
