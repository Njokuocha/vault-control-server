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

    public function mount(Person $person, SettingService $settings): void
    {
        $this->personId = $person->exists ? $person->getKey() : null;
        $this->maxUploadMb = $settings->maxUploadMegabytes();
        $this->maxVideoUploadMb = $settings->maxVideoUploadMegabytes();
    }

    public function render(): View
    {
        $person = $this->personId === null
            ? new Person
            : Person::query()->with('images')->findOrFail($this->personId);

        return view('livewire.person-form-page', [
            'person' => $person,
        ]);
    }
}
