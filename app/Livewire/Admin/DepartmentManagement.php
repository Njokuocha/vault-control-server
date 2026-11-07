<?php

namespace App\Livewire\Admin;

use App\Models\Department;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DepartmentManagement extends Component
{
    public array $rows = [];

    #[Locked]
    public array $savedRows = [];

    public string $search = '';

    public function mount(): void
    {
        $this->loadRows();
    }

    public function addRow(): void
    {
        $this->search = '';
        $this->rows[] = [
            'id' => null,
            'name' => '',
            'rank_short' => '',
            'rank' => '',
            'staff_count' => 0,
            'created_at' => null,
        ];
    }

    public function removeNewRow(int $index): void
    {
        abort_unless(isset($this->rows[$index]) && $this->rows[$index]['id'] === null, 403);
        array_splice($this->rows, $index, 1);
        $this->rows = array_values($this->rows);
    }

    #[Computed]
    public function hasChanges(): bool
    {
        return $this->normalizedRows($this->rows) !== $this->savedRows;
    }

    public function save(): void
    {
        abort_unless($this->hasChanges(), 403);
        $this->resetValidation();

        $this->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.rank' => ['required', 'string', 'max:120'],
        ]);

        $shortRanks = [];
        foreach ($this->rows as $index => $row) {
            $this->validate([
                "rows.$index.rank_short" => [
                    'required',
                    'string',
                    'max:20',
                    'alpha_dash:ascii',
                    Rule::unique('departments', 'rank_short')->ignore($row['id']),
                ],
            ]);
            $shortRank = strtoupper(trim($row['rank_short']));
            if (in_array($shortRank, $shortRanks, true)) {
                $this->addError("rows.$index.rank_short", 'Each department must have a unique short rank.');
            }
            $shortRanks[] = $shortRank;

            if ($row['id'] === null) {
                $code = Str::lower($shortRank);
                if (in_array($code, ['admin', 'api', 'login', 'storage'], true)
                    || Department::query()->where('code', $code)->exists()) {
                    $this->addError("rows.$index.rank_short", 'This short rank is already in use or reserved.');
                }
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->rows as $row) {
                $isCreating = $row['id'] === null;
                $department = $row['id'] === null
                    ? new Department(['code' => Str::lower(trim($row['rank_short']))])
                    : Department::query()->findOrFail((int) $row['id']);
                $department->name = trim($row['name']);
                $department->rank = trim($row['rank']);
                $department->rank_short = strtoupper(trim($row['rank_short']));
                $department->save();
                if (! $isCreating && ! $department->wasChanged()) {
                    continue;
                }
                app(ActivityLogger::class)->record(
                    'staff-management',
                    $isCreating ? 'department.created' : 'department.updated',
                    $isCreating ? "Department {$department->name} was created." : "Department {$department->name} was updated.",
                    [
                        'department_id' => $department->getKey(),
                        'department_code' => $department->code,
                        'rank_short' => $department->rank_short,
                        'changed_fields' => array_keys($department->getChanges()),
                    ],
                );
            }
        });

        $this->loadRows();
        $this->dispatch('alert', type: 'success', message: 'Department records saved.');
    }

    public function render(): View
    {
        $search = mb_strtolower(trim($this->search));
        $visibleRows = collect($this->rows)->filter(function (array $row) use ($search): bool {
            if ($search === '') {
                return true;
            }

            return str_contains(mb_strtolower($row['name']), $search)
                || str_contains(mb_strtolower($row['rank_short']), $search)
                || str_contains(mb_strtolower($row['rank']), $search);
        });

        return view('livewire.admin.department-management', [
            'visibleRows' => $visibleRows,
        ])->extends('layouts.app', [
            'page_title' => 'Department Management — '.config('app.name'),
            'page_label' => 'DEPARTMENT MANAGEMENT',
        ])->section('content');
    }

    private function loadRows(): void
    {
        $this->rows = Department::query()
            ->withCount('staff')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'rank_short' => $department->rank_short,
                'rank' => $department->rank,
                'staff_count' => $department->staff_count,
                'created_at' => $department->created_at?->format('M d, Y'),
            ])
            ->all();

        $this->savedRows = $this->normalizedRows($this->rows);
        $this->resetValidation();
        unset($this->hasChanges);
    }

    private function normalizedRows(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'name' => trim($row['name']),
            'rank_short' => strtoupper(trim($row['rank_short'])),
            'rank' => trim($row['rank']),
        ], $rows);
    }
}
