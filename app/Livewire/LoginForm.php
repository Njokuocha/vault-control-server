<?php

namespace App\Livewire;

use App\Services\AdminLoginService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LoginForm extends Component
{
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
            app(AdminLoginService::class)->attempt(
                $validated['email'],
                $validated['password'],
                (bool) ($validated['remember'] ?? false),
                request()->ip(),
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return null;
        }

        session()->regenerate();

        return $this->redirectIntended(route('dashboard.index'));
    }

    public function render(): View
    {
        return view('livewire.login-form');
    }
}
