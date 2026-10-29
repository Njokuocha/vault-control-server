<?php

namespace App\Livewire\Admin;

use App\Models\AuthEvent;
use App\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class AuthHistory extends Component
{
    use WithPagination;

    public string $search = '';

    public string $bruteForce = '';

    public string $fromMonth = '';

    public string $toMonth = '';

    #[On('refresh-auth-security')]
    public function refreshSecurityData(): void
    {
        $this->resetPage();
        session()->put('security_activity_last_seen_id', (int) ActivityLog::query()->max('id'));
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'bruteForce', 'fromMonth', 'toMonth'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $query = AuthEvent::query()->latest('occurred_at');
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function ($query) use ($search): void {
                $query->where('account_name', 'like', $search)
                    ->orWhere('account_id', 'like', $search)
                    ->orWhere('ip_address', 'like', $search)
                    ->orWhere('guard', 'like', $search);
            });
        }

        if ($this->bruteForce !== '') {
            $query->where('is_brute_force', $this->bruteForce === 'yes');
        }

        if ($this->isValidMonth($this->fromMonth)) {
            $query->where('occurred_at', '>=', Carbon::createFromFormat('Y-m', $this->fromMonth)->startOfMonth());
        }
        if ($this->isValidMonth($this->toMonth)) {
            $query->where('occurred_at', '<=', Carbon::createFromFormat('Y-m', $this->toMonth)->endOfMonth());
        }

        return view('livewire.admin.auth-history', [
            'events' => $query->paginate(20),
            'months' => collect(range(0, 11))->map(fn (int $offset): array => [
                'value' => now()->subMonths($offset)->format('Y-m'),
                'label' => now()->subMonths($offset)->format('F Y'),
            ]),
        ])->extends('layouts.app', [
            'page_title' => 'Authentication History — '.config('app.name'),
            'page_label' => 'AUTHENTICATION HISTORY',
        ])->section('content');
    }

    private function isValidMonth(string $month): bool
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return false;
        }

        [$year, $monthNumber] = array_map('intval', explode('-', $month));

        return checkdate($monthNumber, 1, $year);
    }
}
