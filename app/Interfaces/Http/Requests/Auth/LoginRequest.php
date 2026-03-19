<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // No auth needed for login
    }

    public function rules(): array
    {
        return [
            'email'       => ['required', 'email', 'max:255'],
            'password'    => ['required', 'string', 'min:1'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'remember_me' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'Email address is required.',
            'email.email'       => 'Please provide a valid email address.',
            'password.required' => 'Password is required.',
        ];
    }
}
