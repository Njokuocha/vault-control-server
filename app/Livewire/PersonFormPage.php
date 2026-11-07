<?php

namespace App\Livewire;

use App\Models\Person;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PersonFormPage extends Component
{
    #[Locked]
    public ?int $personId = null;

    public int $maxUploadMb;

    public int $maxVideoUploadMb;

    #[Locked]
    public string $routePrefix = 'people';

    #[Locked]
    public string $authToken = 'unauthed';

    public function mount(
        Person $person,
        SettingService $settings,
        string $routePrefix = 'people',
        string $authToken = 'unauthed',
    ): void {
        $this->personId = $person->exists ? $person->getKey() : null;
        $this->maxUploadMb = $settings->maxUploadMegabytes();
        $this->maxVideoUploadMb = $settings->maxVideoUploadMegabytes();
        $this->routePrefix = $routePrefix;
        $this->authToken = $authToken;
    }

    public function render(): View
    {
        $person = $this->personId === null
            ? new Person
            : Person::query()->with('images.file')->findOrFail($this->personId);

        return view('livewire.person-form-page', [
            'person' => $person,
            'routePrefix' => $this->routePrefix,
            'authToken' => $this->authToken,
        ]);
    }
}
