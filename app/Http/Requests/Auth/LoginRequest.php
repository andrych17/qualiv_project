<?php

namespace App\Http\Requests\Auth;

use App\Services\CentralAuthService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'tenant_id' => ['nullable', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        /** @var CentralAuthService $authService */
        $authService = app(CentralAuthService::class);

        $authService->authenticate(
            credentials: [
                'email' => $this->string('email')->toString(),
                'password' => $this->string('password')->toString(),
                'tenant_id' => $this->input('tenant_id'),
            ],
            remember: $this->boolean('remember'),
            request: $this,
        );
    }
}
