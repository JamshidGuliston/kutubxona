<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name'   => ['sometimes', 'string', 'min:2', 'max:255'],
            'locale' => ['sometimes', 'string', 'in:uz,ru,en'],
            'bio'    => ['sometimes', 'nullable', 'string', 'max:500'],
            'avatar' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.max'   => 'Avatar must be smaller than 2MB.',
            'avatar.mimes' => 'Avatar must be a jpg, png, or webp image.',
        ];
    }
}
