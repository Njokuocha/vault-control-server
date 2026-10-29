<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class ActivityLogIndex extends Component
{
    use WithPagination;

    public string $search = '';

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
        if (in_array($property, ['search', 'fromMonth', 'toMonth'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $query = ActivityLog::query()->latest('occurred_at');
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function ($query) use ($search): void {
                $query->where('summary', 'like', $search)
                    ->orWhere('actor_name', 'like', $search)
                    ->orWhere('category', 'like', $search)
                    ->orWhere('action', 'like', $search)
                    ->orWhere('ip_address', 'like', $search);
            });
        }
        if ($this->isValidMonth($this->fromMonth)) {
            $query->where('occurred_at', '>=', Carbon::createFromFormat('Y-m', $this->fromMonth)->startOfMonth());
        }
        if ($this->isValidMonth($this->toMonth)) {
            $query->where('occurred_at', '<=', Carbon::createFromFormat('Y-m', $this->toMonth)->endOfMonth());
        }

        return view('livewire.admin.activity-log-index', [
            'logs' => $query->paginate(20),
            'months' => collect(range(0, 11))->map(fn (int $offset): array => [
                'value' => now()->subMonths($offset)->format('Y-m'),
                'label' => now()->subMonths($offset)->format('F Y'),
            ]),
        ])->extends('layouts.app', [
            'page_title' => 'Activity Logs — '.config('app.name'),
            'page_label' => 'ACTIVITY LOGS',
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
