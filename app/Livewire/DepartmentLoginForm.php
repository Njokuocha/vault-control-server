<?php

namespace App\Livewire;

use App\Services\DepartmentLoginService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DepartmentLoginForm extends Component
{
    #[Locked]
    public string $department;

    public function mount(string $department): void
    {
        abort_unless(in_array($department, ['cfm', 'pm', 'fm'], true), 404);
        $this->department = $department;
    }

    public function authenticate(mixed $username, mixed $password, mixed $remember = false): mixed
    {
        $validated = Validator::make(
            ['username' => $username, 'password' => $password, 'remember' => $remember],
            [
                'username' => ['required', 'string', 'max:80'],
                'password' => ['required', 'string'],
                'remember' => ['boolean'],
            ],
        )->validate();

        try {
            app(DepartmentLoginService::class)->attempt(
                $validated['username'],
                $validated['password'],
                $this->department,
                (bool) ($validated['remember'] ?? false),
                request()->ip(),
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return null;
        }

        session()->regenerate();

        return $this->redirectIntended('/'.$this->department);
    }

    public function render(): View
    {
        $departmentNames = [
            'cfm' => 'Case File Management',
            'pm' => 'Profile Management',
            'fm' => 'File Management',
        ];

        return view('livewire.department-login-form', [
            'departmentName' => $departmentNames[$this->department],
        ]);
    }
}
