<?php

namespace App\Console\Commands;

use App\Models\UploadTransfer;
use App\Services\UploadTransferStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneExpiredUploadTransfers extends Command
{
    protected $signature = 'vault:prune-upload-transfers';

    protected $description = 'Remove expired private upload chunks and mark incomplete transfers failed.';

    public function handle(UploadTransferStatus $statusUpdates): int
    {
        $count = 0;
        UploadTransfer::query()
            ->where('expires_at', '<=', now())
            ->whereIn('status', ['uploading', 'pending', 'processing', 'failed', 'cancelled'])
            ->chunkById(100, function ($uploads) use (&$count, $statusUpdates): void {
                foreach ($uploads as $upload) {
                    if (in_array($upload->status, ['failed', 'cancelled'], true)) {
                        Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$upload->token);
                        $count++;

                        continue;
                    }

                    $updated = UploadTransfer::query()
                        ->whereKey($upload->id)
                        ->whereIn('status', ['uploading', 'pending', 'processing'])
                        ->update([
                            'status' => 'failed',
                            'error_message' => 'This upload session expired. Start a new upload to retry.',
                            'updated_at' => now(),
                        ]);
                    if ($updated !== 1) {
                        continue;
                    }

                    Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$upload->token);
                    $count++;
                    $upload->refresh();
                    $statusUpdates->broadcast($upload);
                }
            });

        $this->info("Pruned {$count} expired upload transfer(s).");

        return self::SUCCESS;
    }
}
