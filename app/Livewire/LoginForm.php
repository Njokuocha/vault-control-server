<?php

namespace App\Livewire;

use App\Models\ActivityLog;
use App\Services\AdminLoginService;
use App\Services\AdminTwoFactorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LoginForm extends Component
{
    public bool $otpPending = false;

    public string $otpCode = '';

    public function mount(AdminTwoFactorService $twoFactor): void
    {
        $this->otpPending = $twoFactor->pendingDetails() !== null;
    }

    public function authenticate(mixed $email, mixed $password, mixed $remember = false): mixed
    {
        $validated = Validator::make(
            ['email' => $email, 'password' => $password, 'remember' => $remember],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string'],
                'remember' => ['boolean'],
            ],
        )->validate();

        try {
            $requiresOtp = app(AdminLoginService::class)->attempt(
                $validated['email'],
                $validated['password'],
                (bool) ($validated['remember'] ?? false),
                request()->ip(),
            );
            if ($requiresOtp) {
                $this->otpPending = true;

                return null;
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return null;
        }

        session()->regenerate();

        return $this->redirectIntended(route('dashboard.index'));
    }

    public function verifyOtp(AdminTwoFactorService $twoFactor): mixed
    {
        $validated = Validator::make(
            ['otpCode' => $this->otpCode],
            ['otpCode' => ['required', 'digits:6']],
        )->validate();

        try {
            $twoFactor->verify($validated['otpCode'], (string) request()->ip());
        } catch (ValidationException $exception) {
            $this->otpPending = $twoFactor->pendingDetails() !== null;
            $this->setErrorBag($exception->errors());

            return null;
        }

        session()->regenerate();
        session(['security_activity_last_seen_id' => (int) ActivityLog::query()->max('id')]);

        return $this->redirectIntended(route('dashboard.index'));
    }

    public function cancelOtp(AdminTwoFactorService $twoFactor): void
    {
        $twoFactor->cancel();
        $this->otpPending = false;
        $this->otpCode = '';
        $this->resetErrorBag('otpCode');
    }

    public function render(): View
    {
        return view('livewire.login-form', [
            'pendingChallenge' => app(AdminTwoFactorService::class)->pendingDetails(),
        ]);
    }
}
