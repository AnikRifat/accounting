<?php

namespace App\Http\Requests\Api;

use App\Models\ApplicationSetting;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends LoginRequest
{
    public function authorize(): bool
    {
        return ApplicationSetting::values()['registration_enabled'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->numbers()],
        ];
    }
}
