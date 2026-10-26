<?php

namespace App\Livewire;

use App\Models\Person;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class PeopleIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $search = trim($this->search);
        $page = $this->getPage();
        $people = app(VersionedCache::class)->remember(
            'people',
            'index:'.hash('sha256', mb_strtolower($search)).":page:{$page}",
            now()->addSeconds(30),
            function () use ($search) {
                return Person::query()
                    ->withCount(['images', 'cases'])
                    ->when($search !== '', function ($query) use ($search): void {
                        $query->where(function ($query) use ($search): void {
                            $query->where('public_id', 'like', '%'.$search.'%')
                                ->orWhere('name', 'like', '%'.$search.'%')
                                ->orWhere('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%')
                                ->orWhere('surname', 'like', '%'.$search.'%')
                                ->orWhere('state', 'like', '%'.$search.'%')
                                ->orWhere('citizenship', 'like', '%'.$search.'%');
                        });
                    })
                    ->latest()
                    ->paginate(24);
            },
        );
        $totalPeople = app(VersionedCache::class)->remember(
            'people',
            'index-total',
            now()->addSeconds(30),
            fn (): int => Person::query()->count(),
        );

        return view('livewire.people-index', [
            'people' => $people,
            'totalPeople' => $totalPeople,
        ]);
    }
}
