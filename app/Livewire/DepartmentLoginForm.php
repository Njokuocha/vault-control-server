<?php

namespace App\Livewire;

use App\Models\Department;
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
        $this->department = Department::query()->where('code', $department)->value('code')
            ?? abort(404);
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
        return view('livewire.department-login-form', [
            'departmentName' => Department::query()->where('code', $this->department)->value('name') ?? abort(404),
        ]);
    }
}
