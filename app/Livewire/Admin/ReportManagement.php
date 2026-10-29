<?php

namespace App\Livewire\Admin;

use App\Events\AgencyReportUpdated;
use App\Models\Admin;
use App\Models\Report;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ReportAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ReportManagement extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public ?int $confirmingDeleteId = null;

    public function mount(ReportAccess $access): void
    {
        abort_unless($access->isAdministrator($access->actor()), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleHidden(int $id, ReportAccess $access): void
    {
        $actor = $access->actor();
        abort_unless($access->isAdministrator($actor), 403);

        $report = Report::query()->findOrFail($id);
        $report->is_hidden = ! $report->is_hidden;
        $report->save();
        app(ActivityLogger::class)->record(
            'report-system',
            $report->is_hidden ? 'report.hidden' : 'report.restored',
            $report->is_hidden ? 'An agency report was hidden from staff view.' : 'An agency report was restored to staff view.',
            ['report_id' => $report->id, 'report_uuid' => $report->uuid],
            $actor,
        );
        event(new AgencyReportUpdated($report->id, $report->is_hidden ? 'hidden' : 'restored'));
        $this->dispatch('alert', type: 'success', message: $report->is_hidden ? 'Report hidden from staff view.' : 'Report restored to staff view.');
    }

    public function requestDelete(int $id, ReportAccess $access): void
    {
        abort_unless($access->isAdministrator($access->actor()), 403);
        $this->confirmingDeleteId = Report::query()->findOrFail($id)->id;
    }

    public function cancelDelete(ReportAccess $access): void
    {
        abort_unless($access->isAdministrator($access->actor()), 403);
        $this->confirmingDeleteId = null;
    }

    public function deleteReport(ReportAccess $access): void
    {
        $actor = $access->actor();
        abort_unless($access->isAdministrator($actor), 403);
        abort_if($this->confirmingDeleteId === null, 403);

        $report = Report::query()->with('media')->findOrFail($this->confirmingDeleteId);
        if ($report->cases()->exists() || $report->people()->exists()) {
            $this->dispatch('alert', type: 'error', message: 'This report is linked to a case or person record and cannot be deleted. Remove its record links first.');
            $this->confirmingDeleteId = null;

            return;
        }

        $paths = $report->media->pluck('path')->all();
        $commentCount = $report->comments()->count();
        if ($paths !== [] && ! Storage::disk(config('vault.upload_disk', 'vault'))->delete($paths)) {
            throw new \RuntimeException('The report attachments could not be removed from private storage.');
        }

        DB::transaction(fn () => $report->delete());
        app(ActivityLogger::class)->record(
            'report-system',
            'report.deleted',
            'An unlinked agency report was deleted by an administrator.',
            [
                'report_id' => $report->id,
                'report_uuid' => $report->uuid,
                'comment_count' => $commentCount,
                'media_count' => count($paths),
            ],
            $actor,
        );
        event(new AgencyReportUpdated($report->id, 'deleted'));
        $this->confirmingDeleteId = null;
        $this->dispatch('alert', type: 'success', message: 'Report deleted.');
    }

    #[On('agency-report-updated')]
    public function refreshReports(ReportAccess $access): void
    {
        abort_unless($access->isAdministrator($access->actor()), 403);
    }

    public function render(ReportAccess $access): View
    {
        $actor = $access->actor();
        abort_unless($access->isAdministrator($actor), 403);
        $reports = Report::query()
            ->with('reporter')
            ->withCount(['comments', 'media', 'cases', 'people'])
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('uuid', 'like', $term)
                        ->orWhere('body', 'like', $term)
                        ->orWhereHasMorph('reporter', [User::class, Admin::class], function ($query) use ($term): void {
                            $query->where('name', 'like', $term)->orWhere('email', 'like', $term);
                        });
                });
            })
            ->latest('updated_at')
            ->paginate(20);
        $confirmingReport = $this->confirmingDeleteId
            ? Report::query()->withCount(['comments', 'media', 'cases', 'people'])->find($this->confirmingDeleteId)
            : null;

        return view('livewire.admin.report-management', [
            'reports' => $reports,
            'confirmingReport' => $confirmingReport,
        ])->extends('layouts.app', [
            'page_title' => 'Report Control — '.config('app.name'),
            'page_label' => 'REPORT CONTROL',
        ])->section('content');
    }
}
