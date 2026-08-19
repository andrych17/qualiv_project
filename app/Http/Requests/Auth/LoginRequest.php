<?php

namespace App\Http\Requests\Auth;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
        $this->ensureIsNotRateLimited();

        $email = Str::lower($this->string('email')->toString());

        $lookups = TenantUserLookup::query()->where('email', $email)->orderBy('tenant_id')->get();

        if ($lookups->isEmpty()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $requestedTenantId = $this->string('tenant_id')->toString();
        $lookup = $requestedTenantId !== ''
            ? $lookups->firstWhere('tenant_id', $requestedTenantId)
            : $lookups->first();

        if (! $lookup) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $tenant = Tenant::query()->find($lookup->tenant_id);

        if (! $tenant) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        tenancy()->initialize($tenant);

        if (! Auth::attempt(['email' => $email, 'password' => $this->string('password')->toString()], $this->boolean('remember'))) {
            tenancy()->end();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            tenancy()->end();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        $this->session()->put('tenant_id', (string) $tenant->getTenantKey());

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email').'|'.$this->ip()));
    }
}
