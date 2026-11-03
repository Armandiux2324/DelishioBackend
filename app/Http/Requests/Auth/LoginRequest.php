<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required_without:login', 'string', 'email', 'max:255'],
            'login' => ['required_without:email', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
