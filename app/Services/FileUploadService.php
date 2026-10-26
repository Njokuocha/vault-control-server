<?php

namespace App\Services;

use App\Events\FileUploaded;
use App\Jobs\BuildArchiveIndexJob;
use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FileUploadService
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function maximumBytes(): int
    {
        return $this->settings->maxUploadMegabytes() * 1024 * 1024;
    }

    public function assertUploadsAllowed(): void
    {
        if ($this->settings->maxUploadMegabytes() === 0) {
            throw ValidationException::withMessages([
                'file' => 'Uploads are disabled because the maximum upload size is set to 0 MB.',
            ]);
        }
    }

    public function assertWithinLimit(UploadedFile $upload): void
    {
        $this->assertUploadsAllowed();
        $mimeType = $this->detectedMimeType($upload);

        if (str_starts_with($mimeType, 'video/')) {
            $videoMaxMb = $this->settings->maxVideoUploadMegabytes();
            if ($videoMaxMb > 0 && $upload->getSize() > $videoMaxMb * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'file' => "Video files must be no larger than {$videoMaxMb} MB.",
                ]);
            }
        }

        $maxBytes = $this->maximumBytes();

        if ($upload->getSize() > $maxBytes) {
            $maxMb = $this->settings->maxUploadMegabytes();
            throw ValidationException::withMessages([
                'file' => "Each file must be no larger than {$maxMb} MB.",
            ]);
        }
    }

    public function store(UploadedFile $upload, ?string $sourceIp, string $uploadMethod): File
    {
        if (! in_array($uploadMethod, ['api', 'manual'], true)) {
            throw new \InvalidArgumentException('The upload method must be api or manual.');
        }

        $this->assertWithinLimit($upload);

        $realPath = $upload->getRealPath();
        $checksum = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        $mimeType = $this->detectedMimeType($upload);

        if (! is_string($checksum)) {
            throw new \RuntimeException('Unable to calculate the uploaded file checksum.');
        }

        $originalName = $this->safeOriginalName($upload->getClientOriginalName());
        $extension = $this->safeExtension($upload->getClientOriginalExtension());
        $uuid = (string) Str::uuid();
        $directory = now()->format('Y/m');
        $storedName = $uuid.($extension === null ? '' : '.'.$extension);
        $storedPath = null;

        try {
            $storedPath = Storage::disk(config('vault.upload_disk', 'vault'))
                ->putFileAs($directory, $upload, $storedName);

            if (! is_string($storedPath) || $storedPath === '') {
                throw new \RuntimeException('The private storage disk did not save the uploaded file.');
            }

            $file = DB::transaction(fn (): File => File::query()->create([
                'uuid' => $uuid,
                'original_name' => $originalName,
                'stored_path' => $storedPath,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'category' => $this->category($mimeType, $extension),
                'upload_method' => $uploadMethod,
                'size' => $upload->getSize(),
                'checksum' => $checksum,
                'is_editable' => false,
                'source_ip' => $sourceIp,
                'archive_status' => $extension === 'zip' ? 'pending' : null,
            ]));
        } catch (Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk(config('vault.upload_disk', 'vault'))->delete($storedPath);
            }

            throw $exception;
        }

        event(new FileUploaded(
            $file->uuid,
            $file->original_name,
            $file->category,
            $file->created_at?->format('Y-m') ?? now()->format('Y-m'),
        ));

        if ($extension === 'zip' && $file->category === 'archive') {
            BuildArchiveIndexJob::dispatch($file->id)->afterCommit();
        }

        return $file;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'unnamed-file' : Str::limit($name, 255, '');
    }

    private function safeExtension(string $extension): ?string
    {
        $extension = strtolower($extension);

        return preg_match('/^[a-z0-9]{1,20}$/', $extension) === 1 ? $extension : null;
    }

    private function category(string $mimeType, ?string $extension): string
    {
        if (str_starts_with($mimeType, 'image/')) return 'image';
        if (str_starts_with($mimeType, 'video/')) return 'video';
        if (str_starts_with($mimeType, 'audio/')) return 'audio';
        if ($mimeType === 'application/pdf') return 'pdf';
        if (str_starts_with($mimeType, 'text/')) {
            return in_array($extension, ['php', 'js', 'ts', 'css', 'html', 'xml', 'json', 'py', 'sql'], true)
                ? 'code'
                : 'text';
        }
        if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'], true)) return 'archive';

        return 'other';
    }

    private function detectedMimeType(UploadedFile $upload): string
    {
        $realPath = $upload->getRealPath();
        $mimeType = is_string($realPath) ? (new \finfo(FILEINFO_MIME_TYPE))->file($realPath) : false;

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }
}
