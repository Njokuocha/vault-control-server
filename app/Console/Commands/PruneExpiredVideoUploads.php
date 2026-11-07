<?php

namespace App\Console\Commands;

use App\Events\VideoUploadStatusChanged;
use App\Models\VideoUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneExpiredVideoUploads extends Command
{
    protected $signature = 'vault:prune-video-uploads';

    protected $description = 'Remove expired private video upload chunks and mark incomplete sessions failed.';

    public function handle(): int
    {
        $count = 0;
        VideoUpload::query()
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', ['completed', 'finalizing'])
            ->chunkById(100, function ($uploads) use (&$count): void {
                $disk = Storage::disk('local');

                foreach ($uploads as $upload) {
                    $disk->deleteDirectory('vault-chunks/'.$upload->token);
                    $count++;

                    if ($upload->status !== 'failed') {
                        $upload->forceFill([
                            'status' => 'failed',
                            'error_message' => 'This upload session expired. Start a new upload to retry.',
                        ])->save();
                        event(new VideoUploadStatusChanged($upload->token, 'failed', 0));
                    }
                }
            });

        $this->info("Pruned {$count} expired video upload session(s).");

        return self::SUCCESS;
    }
}
