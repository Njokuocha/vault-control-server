<?php

namespace App\Services;

use App\Jobs\BuildArchiveIndexJob;
use App\Models\File;
use Illuminate\Validation\ValidationException;
use SplFileInfo;

class VaultShellService
{
    private string $root;

    public function __construct()
    {
        $this->root = rtrim((string) config('vault.shell_root'), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{path: string, directories: array<int, array{name: string, path: string}>, files: array<int, array{name: string, path: string, size: int, modified_at: int}>}
     */
    public function list(string $virtualPath): array
    {
        $relativePath = $this->relativePath($virtualPath);
        $directory = $this->resolve($relativePath, true);

        if ($directory === null && $relativePath === '') {
            return ['path' => '/root', 'directories' => [], 'files' => []];
        }

        if ($directory === null) {
            abort(404, 'Directory not found.');
        }

        $directories = [];
        $files = [];

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }

            $itemPath = $directory.DIRECTORY_SEPARATOR.$name;

            if (is_link($itemPath)) {
                continue;
            }

            $virtualItemPath = $this->virtualPath($relativePath === '' ? $name : $relativePath.'/'.$name);

            if (is_dir($itemPath)) {
                $directories[] = ['name' => $name, 'path' => $virtualItemPath];

                continue;
            }

            if (is_file($itemPath)) {
                $file = new SplFileInfo($itemPath);
                $files[] = [
                    'name' => $name,
                    'path' => $virtualItemPath,
                    'size' => $file->getSize(),
                    'modified_at' => $file->getMTime(),
                ];
            }
        }

        usort($directories, fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        usort($files, fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        return [
            'path' => $this->virtualPath($relativePath),
            'directories' => $directories,
            'files' => $files,
        ];
    }

    /**
     * @return array{name: string, path: string, size: int, mime: string, preview: string|null, truncated: bool, extracted: bool, archive_entries: array<int, string>, archive_entries_truncated: bool, archive_status: string|null, archive_tree: array<string, mixed>|null, archive_entries_count: int|null, archive_entry_url: string|null}
     */
    public function preview(string $virtualPath): array
    {
        $relativePath = $this->relativePath($virtualPath);
        $absolutePath = $this->resolve($relativePath, false);

        if ($absolutePath === null) {
            abort(404, 'File not found.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
        $size = filesize($absolutePath);
        $preview = null;
        $truncated = false;
        $extracted = false;
        $isArchive = $this->isZipFile($absolutePath, $mime);
        $archiveEntries = [];
        $archiveEntriesTruncated = false;
        $archiveStatus = null;
        $archiveTree = null;
        $archiveEntryUrl = null;
        $indexedFile = null;

        if ($this->isText($mime) && is_int($size)) {
            [$preview, $truncated] = $this->readTextPreview($absolutePath, $size);
        } elseif ($isArchive) {
            $indexedFile = File::query()->where('stored_path', $relativePath)->first();
            if ($indexedFile && $indexedFile->extension === 'zip') {
                if ($indexedFile->archive_status === null) {
                    $updated = File::query()->whereKey($indexedFile->id)
                        ->whereNull('archive_status')
                        ->update(['archive_status' => 'pending']);
                    if ($updated === 1) {
                        BuildArchiveIndexJob::dispatch($indexedFile->id);
                    }
                    $indexedFile->refresh();
                }
                $archiveStatus = $indexedFile->archive_status ?? 'pending';
                if ($archiveStatus === 'ready') {
                    $archiveTree = $indexedFile->archive_index;
                    $allEntries = $this->flattenArchiveTree(is_array($archiveTree) ? ($archiveTree['children'] ?? []) : []);
                    $archiveEntriesTruncated = count($allEntries) > 1000;
                    $archiveEntries = array_slice($allEntries, 0, 1000);
                    $remaining = 1000;
                    $archiveTree = [
                        'type' => 'directory',
                        'name' => '',
                        'children' => $this->limitArchiveTree(is_array($archiveTree) ? ($archiveTree['children'] ?? []) : [], $remaining),
                    ];
                    $archiveEntryUrl = route('files.archive-entry', ['file' => $indexedFile->uuid]);
                }
            } else {
                [$archiveEntries, $archiveEntriesTruncated] = $this->listArchiveEntries($absolutePath);
            }
            $extractedText = $indexedFile?->extension === 'zip' ? null : $this->extractWordDocumentText($absolutePath);
            $preview = $extractedText['text'] ?? null;
            $extracted = $extractedText !== null;
            $truncated = $extractedText['truncated'] ?? false;
        }

        return [
            'name' => basename($absolutePath),
            'path' => $this->virtualPath($relativePath),
            'size' => is_int($size) ? $size : 0,
            'mime' => $mime,
            'preview' => $preview,
            'truncated' => $truncated,
            'extracted' => $extracted,
            'is_archive' => $isArchive,
            'archive_entries' => $archiveEntries,
            'archive_entries_truncated' => $archiveEntriesTruncated,
            'archive_status' => $archiveStatus,
            'archive_tree' => $archiveTree,
            'archive_entries_count' => $indexedFile?->archive_entry_count ?? count($archiveEntries),
            'archive_entry_url' => $archiveEntryUrl,
        ];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<string>
     */
    private function flattenArchiveTree(array $nodes, string $prefix = ''): array
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
                array_push($paths, ...$this->flattenArchiveTree($node['children'] ?? [], $path));
            }
        }

        return $paths;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function limitArchiveTree(array $nodes, int &$limit): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($limit <= 0) {
                break;
            }
            $limit--;
            if (($node['type'] ?? null) === 'directory') {
                $node['children'] = $this->limitArchiveTree($node['children'] ?? [], $limit);
            }
            $result[] = $node;
        }

        return $result;
    }

    public function stream(string $virtualPath): string
    {
        $absolutePath = $this->resolve($this->relativePath($virtualPath), false);

        if ($absolutePath === null) {
            abort(404, 'File not found.');
        }

        return $absolutePath;
    }

    public function delete(string $virtualPath): string
    {
        $absolutePath = $this->resolve($this->relativePath($virtualPath), false);

        if ($absolutePath === null) {
            abort(404, 'File not found.');
        }

        $fileName = basename($absolutePath);

        if (! unlink($absolutePath)) {
            abort(500, 'Unable to delete the requested file.');
        }

        return $fileName;
    }

    public function makeDirectory(string $virtualPath): string
    {
        [$relativePath, $absolutePath] = $this->destination($virtualPath);

        if (file_exists($absolutePath) || is_link($absolutePath)) {
            abort(409, 'An entry with that name already exists.');
        }

        if (! mkdir($absolutePath, 0750)) {
            abort(500, 'Unable to create the requested directory.');
        }

        return basename($relativePath);
    }

    public function copy(string $sourcePath, string $destinationPath): string
    {
        $sourceRelative = $this->relativePath($sourcePath);
        $source = $this->resolveAny($sourceRelative);

        if ($sourceRelative === '' || $source === null) {
            abort(404, 'Source entry not found.');
        }

        [$targetRelative, $target] = $this->destinationTarget($sourceRelative, $destinationPath);

        if ($this->isSameOrChildPath($sourceRelative, $targetRelative)) {
            abort(409, 'An entry cannot be copied into itself or one of its subdirectories.');
        }

        if (file_exists($target) || is_link($target)) {
            abort(409, 'The destination already exists; entries are never overwritten.');
        }

        try {
            if (is_dir($source)) {
                if (! mkdir($target, 0750)) {
                    abort(500, 'Unable to create the destination directory.');
                }
                $this->copyDirectoryContents($source, $target);
            } elseif (! copy($source, $target)) {
                abort(500, 'Unable to copy the requested file.');
            }
        } catch (\Throwable $exception) {
            $this->removePath($target);
            if (file_exists($target) || is_link($target)) {
                throw new \RuntimeException('Failed to clean up the partial copy after an error.', 0, $exception);
            }
            throw $exception;
        }

        return $this->virtualPath($targetRelative);
    }

    public function move(string $sourcePath, string $destinationPath): string
    {
        $sourceRelative = $this->relativePath($sourcePath);
        $source = $this->resolveAny($sourceRelative);

        if ($sourceRelative === '' || $source === null) {
            abort(404, 'Source entry not found.');
        }

        [$targetRelative, $target] = $this->destinationTarget($sourceRelative, $destinationPath);

        if ($this->isSameOrChildPath($sourceRelative, $targetRelative)) {
            abort(409, 'An entry cannot be moved into itself or one of its subdirectories.');
        }

        if (file_exists($target) || is_link($target)) {
            abort(409, 'The destination already exists; entries are never overwritten.');
        }

        if (! rename($source, $target)) {
            abort(500, 'Unable to move the requested entry.');
        }

        return $this->virtualPath($targetRelative);
    }

    private function relativePath(string $virtualPath): string
    {
        $path = str_replace('\\', '/', trim($virtualPath));
        $segments = explode('/', $path);

        if (($segments[0] ?? null) === 'root') {
            array_shift($segments);
        } elseif (($segments[0] ?? null) === '') {
            array_shift($segments);

            if (($segments[0] ?? null) === 'root') {
                array_shift($segments);
            }
        } elseif ($path !== '' && $path !== '.') {
            throw ValidationException::withMessages([
                'path' => 'Paths must be inside the virtual /root directory.',
            ]);
        } else {
            $segments = [];
        }

        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($normalized === []) {
                    throw ValidationException::withMessages([
                        'path' => 'The virtual root cannot be traversed.',
                    ]);
                }

                array_pop($normalized);

                continue;
            }

            if (preg_match('/[\x00-\x1F<>:"|?*]/u', $segment) === 1) {
                throw ValidationException::withMessages([
                    'path' => 'The path contains unsupported characters.',
                ]);
            }

            $normalized[] = $segment;
        }

        return implode('/', $normalized);
    }

    private function resolve(string $relativePath, bool $directory): ?string
    {
        if ($this->root === '') {
            abort(500, 'The private shell storage root is not configured.');
        }

        $this->assertNoSymlinkSegments($relativePath);
        $candidate = $this->root.($relativePath === '' ? '' : DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($relativePath === '' && ! file_exists($candidate)) {
            return $directory ? null : null;
        }

        $root = realpath($this->root);
        $resolved = realpath($candidate);

        if ($root === false || $resolved === false || is_link($candidate)) {
            return null;
        }

        $prefix = $root.DIRECTORY_SEPARATOR;

        if ($resolved !== $root && ! str_starts_with(strtolower($resolved), strtolower($prefix))) {
            return null;
        }

        if ($directory && ! is_dir($resolved)) {
            return null;
        }

        if (! $directory && ! is_file($resolved)) {
            return null;
        }

        return $resolved;
    }

    private function resolveAny(string $relativePath): ?string
    {
        $directory = $this->resolve($relativePath, true);

        return $directory ?? $this->resolve($relativePath, false);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function destination(string $virtualPath): array
    {
        $relativePath = $this->relativePath($virtualPath);

        if ($relativePath === '') {
            abort(400, 'The virtual root cannot be used as a destination.');
        }

        $parentRelative = dirname($relativePath);
        $parentRelative = $parentRelative === '.' ? '' : $parentRelative;

        if ($parentRelative === '' && ! is_dir($this->root) && ! mkdir($this->root, 0750, true) && ! is_dir($this->root)) {
            abort(500, 'Unable to initialize the private virtual root.');
        }

        $parent = $this->resolve($parentRelative, true);

        if ($parent === null) {
            abort(404, 'The destination directory does not exist.');
        }

        return [
            $relativePath,
            $parent.DIRECTORY_SEPARATOR.basename($relativePath),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function destinationTarget(string $sourceRelative, string $virtualPath): array
    {
        $relativePath = $this->relativePath($virtualPath);
        $target = $this->destination($virtualPath)[1];

        if (is_link($target)) {
            abort(404, 'Symbolic links are not available in the virtual filesystem.');
        }

        if (is_dir($target)) {
            $relativePath = $relativePath.'/'.basename($sourceRelative);
            $target .= DIRECTORY_SEPARATOR.basename($sourceRelative);
        }

        return [$relativePath, $target];
    }

    private function assertNoSymlinkSegments(string $relativePath): void
    {
        if (is_link($this->root)) {
            abort(500, 'Symbolic-link storage roots are not supported.');
        }

        $candidate = $this->root;

        foreach (array_filter(explode('/', $relativePath), fn (string $segment): bool => $segment !== '') as $segment) {
            $candidate .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($candidate)) {
                abort(404, 'Symbolic links are not available in the virtual filesystem.');
            }
        }
    }

    private function isSameOrChildPath(string $parent, string $child): bool
    {
        $parent = strtolower(rtrim($parent, '/'));
        $child = strtolower(rtrim($child, '/'));

        return $child === $parent || str_starts_with($child, $parent.'/');
    }

    private function copyDirectoryContents(string $source, string $destination): void
    {
        foreach (scandir($source) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $sourceItem = $source.DIRECTORY_SEPARATOR.$name;
            $destinationItem = $destination.DIRECTORY_SEPARATOR.$name;

            if (is_link($sourceItem)) {
                throw new \RuntimeException('Directories containing symbolic links cannot be copied.');
            }

            if (is_dir($sourceItem)) {
                if (! mkdir($destinationItem, 0750)) {
                    throw new \RuntimeException('Unable to create a directory while copying.');
                }

                $this->copyDirectoryContents($sourceItem, $destinationItem);
            } elseif (is_file($sourceItem) && ! copy($sourceItem, $destinationItem)) {
                throw new \RuntimeException('Unable to copy a file in the requested directory.');
            }
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removePath($path.DIRECTORY_SEPARATOR.$name);
            }
        }

        rmdir($path);
    }

    private function virtualPath(string $relativePath): string
    {
        return '/root'.($relativePath === '' ? '' : '/'.$relativePath);
    }

    private function isText(string $mime): bool
    {
        return str_starts_with($mime, 'text/')
            || in_array($mime, [
                'application/json',
                'application/xml',
                'application/javascript',
                'application/x-javascript',
                'application/x-httpd-php',
                'application/x-sh',
            ], true);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function readTextPreview(string $path, int $size): array
    {
        $limit = 1024 * 1024;
        $contents = file_get_contents($path, false, null, 0, $limit);

        if ($contents === false) {
            abort(500, 'Unable to read the requested file.');
        }

        return [$contents, $size > $limit];
    }

    private function isZipFile(string $path, string $mime): bool
    {
        if ($mime === 'application/zip' || str_contains($mime, 'wordprocessingml')) {
            return true;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            abort(500, 'Unable to read the requested file.');
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return is_string($signature) && str_starts_with($signature, "PK\x03\x04");
    }

    /**
     * @return array{0: array<int, string>, 1: bool}
     */
    private function listArchiveEntries(string $path): array
    {
        if (! class_exists(\ZipArchive::class)) {
            return [[], false];
        }

        $archive = new \ZipArchive;
        if ($archive->open($path) !== true) {
            return [[], false];
        }

        try {
            $entries = [];
            $entryCount = $archive->numFiles;
            $limit = 1000;
            $nameBytes = 0;

            for ($index = 0; $index < min($entryCount, $limit); $index++) {
                $name = $archive->getNameIndex($index);
                if (! is_string($name) || $name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $name = mb_strcut($name, 0, 512, 'UTF-8');
                $nameBytes += strlen($name);
                if ($nameBytes > 262144) {
                    return [$entries, true];
                }

                $entries[] = $name;
            }

            sort($entries, SORT_NATURAL | SORT_FLAG_CASE);

            return [$entries, $entryCount > $limit];
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array{text: string, truncated: bool}|null
     */
    private function extractWordDocumentText(string $path): ?array
    {
        if (! class_exists(\ZipArchive::class) || ! class_exists(\DOMDocument::class)) {
            return null;
        }

        $archive = new \ZipArchive;

        if ($archive->open($path) !== true) {
            return null;
        }

        try {
            $entry = $archive->statName('word/document.xml');

            if (! is_array($entry) || ($entry['size'] ?? PHP_INT_MAX) > 5 * 1024 * 1024) {
                return null;
            }

            $stream = $archive->getStream('word/document.xml');

            if ($stream === false) {
                return null;
            }

            $xml = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
            fclose($stream);

            if (! is_string($xml) || strlen($xml) > 5 * 1024 * 1024 || preg_match('/<!DOCTYPE/i', $xml)) {
                return null;
            }

            $document = new \DOMDocument;
            $previousErrorMode = libxml_use_internal_errors(true);

            try {
                $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrorMode);
            }

            if (! $loaded) {
                return null;
            }

            $paragraphs = $document->getElementsByTagNameNS('*', 'p');
            $text = '';

            foreach ($paragraphs as $paragraph) {
                $text .= $this->wordParagraphText($paragraph)."\n";

                if (strlen($text) >= 1024 * 1024) {
                    return ['text' => substr($text, 0, 1024 * 1024), 'truncated' => true];
                }
            }

            return ['text' => rtrim($text), 'truncated' => false];
        } finally {
            $archive->close();
        }
    }

    private function wordParagraphText(\DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child->localName === 't') {
                $text .= $child->textContent;
            } elseif ($child->localName === 'tab') {
                $text .= "\t";
            } elseif ($child->localName === 'br' || $child->localName === 'cr') {
                $text .= "\n";
            } else {
                $text .= $this->wordParagraphText($child);
            }
        }

        return $text;
    }
}
