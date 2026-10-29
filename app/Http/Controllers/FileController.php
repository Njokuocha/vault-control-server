<?php

namespace App\Http\Controllers;

use App\Jobs\BuildArchiveIndexJob;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class FileController extends Controller
{
    public function preview(File $file, ?string $routePrefix = null): JsonResponse
    {
        $routePrefix ??= request()->routeIs('fm.*') ? 'fm.files' : 'files';
        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        $absolutePath = $this->privateAbsolutePath($file);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
        $size = filesize($absolutePath);
        $isZip = $file->extension === 'zip' || $mime === 'application/zip';

        if ($isZip && $file->archive_status === null) {
            $updated = File::query()
                ->whereKey($file->id)
                ->whereNull('archive_status')
                ->update(['archive_status' => 'pending']);

            if ($updated === 1) {
                BuildArchiveIndexJob::dispatch($file->id);
            }
            $file->refresh();
        }

        $preview = null;
        $truncated = false;
        if (str_starts_with($mime, 'text/') || in_array($mime, [
            'application/json',
            'application/xml',
            'application/javascript',
        ], true)) {
            [$preview, $truncated] = $this->readTextPreview($disk->readStream($file->stored_path));
        }

        $tree = $isZip && $file->archive_status === 'ready' ? $file->archive_index : null;
        if ($tree !== null) {
            $remaining = 1000;
            $tree = [
                'type' => 'directory',
                'name' => '',
                'children' => $this->limitTree($tree['children'] ?? [], $remaining),
            ];
        }

        return response()->json([
            'name' => $file->original_name,
            'size' => is_int($size) ? $size : 0,
            'mime' => $mime,
            'preview' => $preview,
            'truncated' => $truncated,
            'extracted' => false,
            'is_archive' => $isZip,
            'archive_status' => $isZip ? ($file->archive_status ?? 'pending') : null,
            'archive_tree' => $tree,
            'archive_entries_count' => $file->archive_entry_count ?? 0,
            'archive_entries_truncated' => ($file->archive_entry_count ?? 0) > 1000,
            'archive_entries' => $tree === null ? [] : $this->flattenTree($tree['children'] ?? []),
            'archive_entry_url' => $isZip ? route($routePrefix.'.archive-entry', ['file' => $file->uuid]) : null,
            'stream_url' => route($routePrefix.'.stream', ['file' => $file->uuid]),
            'download_url' => route($routePrefix.'.download', ['file' => $file->uuid]),
        ]);
    }

    public function stream(Request $request, File $file): Response
    {
        $absolutePath = $this->privateAbsolutePath($file);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';

        return $this->privateFileResponse(
            $request,
            $file->stored_path,
            $absolutePath,
            $mime,
            $file->checksum,
            $file->updated_at,
            'private, must-revalidate',
            $file->original_name,
        );
    }

    public function streamPrivatePath(
        Request $request,
        string $relativePath,
        ?string $mimeType = null,
        ?string $fileName = null,
        bool $download = false,
    ): Response {
        $absolutePath = $this->privateRelativePath($relativePath);
        $modifiedAt = filemtime($absolutePath);
        $size = filesize($absolutePath);
        if (! is_int($modifiedAt) || ! is_int($size)) {
            throw new \RuntimeException('Unable to read private media metadata.');
        }

        $mime = $mimeType ?? (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';

        return $this->privateFileResponse(
            $request,
            $relativePath,
            $absolutePath,
            $mime,
            hash('sha256', $relativePath.':'.$size.':'.$modifiedAt),
            Carbon::createFromTimestamp($modifiedAt),
            'private, no-store',
            $fileName ?? basename($relativePath),
            $download,
        );
    }

    private function privateFileResponse(
        Request $request,
        string $relativePath,
        string $absolutePath,
        string $mime,
        string $etag,
        \DateTimeInterface $lastModified,
        string $cacheControl,
        string $fileName,
        bool $download = false,
    ): Response {
        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $cacheControl,
        ];
        if (in_array($mime, ['text/html', 'image/svg+xml', 'application/xml', 'text/xml'], true)) {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'";
        }
        $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
            $download ? 'attachment' : 'inline',
            $this->safeDownloadName($fileName),
        );

        $mode = $this->sendfileMode();
        $response = $mode === 'off'
            ? response()->file($absolutePath, $headers)
            : response('', 200, $headers);
        $response->setEtag($etag);
        $response->setLastModified($lastModified);
        $response->setPrivate();

        if ($response->isNotModified($request)) {
            return $response;
        }

        if ($mode !== 'off') {
            $response->headers->set('Accept-Ranges', 'bytes');
            $response->headers->set('Content-Length', (string) filesize($absolutePath));
            $this->addSendfileHeader($response, $relativePath, $absolutePath, $mode);
        }

        return $response;
    }

    public function download(File $file): BinaryFileResponse|Response
    {
        $absolutePath = $this->privateAbsolutePath($file);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';

        return $this->privateFileResponse(
            request(),
            $file->stored_path,
            $absolutePath,
            $mime,
            $file->checksum,
            $file->updated_at,
            'private, no-store',
            $file->original_name,
            true,
        );
    }

    public function videoRendition(File $file): Response
    {
        abort_unless($file->category === 'video' && is_string($file->video_playable_path), 404);

        return $this->streamPrivatePath(request(), $file->video_playable_path, 'video/mp4');
    }

    public function videoPoster(File $file): Response
    {
        abort_unless($file->category === 'video' && is_string($file->video_poster_path), 404);

        return $this->streamPrivatePath(request(), $file->video_poster_path, 'image/jpeg');
    }

    public function archiveEntry(Request $request, File $file, ?string $routePrefix = null): JsonResponse|Response
    {
        $routePrefix ??= $request->routeIs('fm.*') ? 'fm.files' : 'files';
        abort_unless($file->extension === 'zip' || $file->mime_type === 'application/zip', 404);
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'download' => ['sometimes', 'boolean'],
        ]);
        $entryPath = $validated['path'];

        if (! $this->isSafeEntryPath($entryPath) || ! $this->treeContainsFile($file->archive_index ?? [], $entryPath)) {
            abort(404, 'Archive entry not found.');
        }

        $absolutePath = $this->privateAbsolutePath($file);
        $archive = new ZipArchive;
        abort_if($archive->open($absolutePath) !== true, 422, 'Archive contents are unavailable.');

        if (($validated['download'] ?? false) === true) {
            $probe = $archive->getStream($entryPath);
            if ($probe === false) {
                $archive->close();
                abort(422, 'This archive entry is unavailable or password protected.');
            }
            fclose($probe);
            $archive->close();

            return response()->streamDownload(function () use ($absolutePath, $entryPath): void {
                $archive = new ZipArchive;
                if ($archive->open($absolutePath) !== true) {
                    abort(422, 'Archive contents are unavailable.');
                }
                $stream = $archive->getStream($entryPath);
                if ($stream === false) {
                    $archive->close();
                    abort(422, 'This archive entry is unavailable or password protected.');
                }

                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if (! is_string($chunk)) {
                            break;
                        }
                        echo $chunk;
                    }
                } finally {
                    fclose($stream);
                    $archive->close();
                }
            }, basename($entryPath), [
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $stat = $archive->statName($entryPath);
        if (! is_array($stat)) {
            $archive->close();
            abort(404, 'Archive entry not found.');
        }
        if ((int) ($stat['size'] ?? 0) > 1048576) {
            $archive->close();

            return response()->json([
                'message' => 'This entry is too large to preview. Download the individual entry instead.',
                'download_url' => route($routePrefix.'.archive-entry', ['file' => $file, 'path' => $entryPath, 'download' => 1]),
            ], 413);
        }

        $cacheKey = 'archive-entry-preview:'.$file->uuid.':'.$file->checksum.':'.hash('sha256', $entryPath);
        $preview = Cache::remember($cacheKey, now()->addMinute(), function () use ($archive, $entryPath): ?array {
            $stream = $archive->getStream($entryPath);
            if ($stream === false) {
                return null;
            }

            try {
                $contents = stream_get_contents($stream, 1048577);
                if (! is_string($contents)) {
                    return null;
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
                if (! is_string($mime) || (! str_starts_with($mime, 'text/') && ! in_array($mime, ['application/json', 'application/xml'], true))) {
                    return ['preview_available' => false];
                }

                return ['preview_available' => true, 'content' => mb_convert_encoding($contents, 'UTF-8', 'UTF-8'), 'mime' => $mime];
            } finally {
                fclose($stream);
            }
        });
        $archive->close();

        if ($preview === null) {
            return response()->json([
                'preview_available' => false,
                'message' => 'The entry may be password protected or cannot be previewed.',
                'download_url' => route($routePrefix.'.archive-entry', ['file' => $file, 'path' => $entryPath, 'download' => 1]),
            ], 200);
        }

        return response()->json([
            ...$preview,
            'download_url' => route($routePrefix.'.archive-entry', ['file' => $file, 'path' => $entryPath, 'download' => 1]),
        ]);
    }

    /**
     * @param  resource|false  $stream
     * @return array{0: string|null, 1: bool}
     */
    private function readTextPreview(mixed $stream): array
    {
        if (! is_resource($stream)) {
            return [null, false];
        }

        try {
            $contents = stream_get_contents($stream, 1048577);
        } finally {
            fclose($stream);
        }

        if (! is_string($contents)) {
            return [null, false];
        }

        $truncated = strlen($contents) > 1048576;
        if ($truncated) {
            $contents = substr($contents, 0, 1048576);
        }

        return [mb_convert_encoding($contents, 'UTF-8', 'UTF-8'), $truncated];
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    private function treeContainsFile(array $tree, string $path): bool
    {
        foreach ($tree['children'] ?? [] as $entry) {
            if (($entry['type'] ?? null) === 'file' && is_string($entry['path'] ?? null) && hash_equals($entry['path'], $path)) {
                return true;
            }
            if (($entry['type'] ?? null) === 'directory' && $this->treeContainsFile($entry, $path)) {
                return true;
            }
        }

        return false;
    }

    private function isSafeEntryPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function flattenTree(array $nodes, string $prefix = ''): array
    {
        $paths = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['name'] ?? null)) {
                continue;
            }
            $path = $prefix === '' ? $node['name'] : $prefix.'/'.$node['name'];
            if (($node['type'] ?? null) === 'file') {
                $paths[] = $path;
            } else {
                array_push($paths, ...$this->flattenTree($node['children'] ?? [], $path));
            }
        }

        return $paths;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function limitTree(array $nodes, int &$remaining): array
    {
        $limited = [];
        foreach ($nodes as $node) {
            if ($remaining <= 0) {
                break;
            }
            $remaining--;
            if (($node['type'] ?? null) === 'directory') {
                $node['children'] = $this->limitTree($node['children'] ?? [], $remaining);
            }
            $limited[] = $node;
        }

        return $limited;
    }

    private function privateAbsolutePath(File $file): string
    {
        return $this->privateRelativePath($file->stored_path);
    }

    private function privateRelativePath(string $relativePath): string
    {
        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($relativePath));
        abort_unless(is_string($root) && is_string($path) && is_file($path), 404, 'File not found.');

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedPath = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $normalizedPrefix = DIRECTORY_SEPARATOR === '\\' ? strtolower($rootPrefix) : $rootPrefix;
        abort_unless(str_starts_with($normalizedPath, $normalizedPrefix), 404, 'File not found.');

        return $path;
    }

    private function sendfileMode(): string
    {
        $mode = config('vault.sendfile', 'off');
        if (! in_array($mode, ['off', 'nginx', 'apache'], true)) {
            throw new \InvalidArgumentException('VAULT_SENDFILE must be nginx, apache, or off.');
        }

        return $mode;
    }

    private function addSendfileHeader(Response $response, string $relativePath, string $absolutePath, string $mode): void
    {
        if ($mode === 'nginx') {
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
            $response->headers->set('X-Accel-Redirect', '/_vault/'.$encodedPath);

            return;
        }

        if ($mode === 'apache') {
            $response->headers->set('X-Sendfile', $absolutePath);
        }
    }

    private function safeDownloadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return trim($name) !== '' ? trim($name) : 'private-file';
    }
}
