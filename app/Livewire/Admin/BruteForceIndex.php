<?php

namespace App\Livewire\Admin;

use App\Models\BruteForceRecord;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class BruteForceIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $confirmingDeleteId = null;

    #[On('refresh-auth-security')]
    public function refreshSecurityData(): void
    {
        $this->resetPage();
        session()->put('security_activity_last_seen_id', (int) ActivityLog::query()->max('id'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = BruteForceRecord::query()->findOrFail($id)->id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(int $id): void
    {
        abort_unless($this->confirmingDeleteId === $id, 403);
        $record = BruteForceRecord::query()->findOrFail($id);
        $record->delete();
        app(ActivityLogger::class)->record(
            'security',
            'brute-force.deleted',
            "Brute-force record for {$record->ip_address} was deleted.",
            [
                'record_id' => $record->getKey(),
                'ip_address' => $record->ip_address,
                'account_name' => $record->account_name,
                'failed_attempts' => $record->failed_attempts,
            ],
        );
        $this->confirmingDeleteId = null;
        $this->dispatch('alert', type: 'success', message: 'Brute-force record deleted.');
    }

    public function render(): View
    {
        $query = BruteForceRecord::query()->latest('detected_at');
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function ($query) use ($search): void {
                $query->where('account_name', 'like', $search)
                    ->orWhere('account_id', 'like', $search)
                    ->orWhere('ip_address', 'like', $search);
            });
        }

        return view('livewire.admin.brute-force-index', [
            'records' => $query->paginate(20),
            'deleteTarget' => $this->confirmingDeleteId ? BruteForceRecord::query()->find($this->confirmingDeleteId) : null,
        ])->extends('layouts.app', [
            'page_title' => 'Brute-Force Records — '.config('app.name'),
            'page_label' => 'BRUTE-FORCE RECORDS',
        ])->section('content');
    }
}
