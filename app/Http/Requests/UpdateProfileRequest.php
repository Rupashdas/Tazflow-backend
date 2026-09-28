<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest {
    public function authorize(): bool {
        return true;
    }

    public function rules(): array {
        return [
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'email'    => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
            // Changing the address hands over password resets, so it needs the password.
            'current_password' => [
                Rule::requiredIf(fn () => $this->has('email') && $this->input('email') !== $this->user()->email),
                'nullable',
                'current_password',
            ],
            'title'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'bio'      => ['sometimes', 'nullable', 'string', 'max:500'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->email))]);
        }
    }
}
