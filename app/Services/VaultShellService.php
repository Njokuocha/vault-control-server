<?php

namespace App\Services;

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
     * @return array{name: string, path: string, size: int, mime: string, preview: string|null, truncated: bool}
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

        if ($this->isText($mime) && is_int($size)) {
            $limit = 1024 * 1024;
            $contents = file_get_contents($absolutePath, false, null, 0, $limit);

            if ($contents === false) {
                abort(500, 'Unable to read the requested file.');
            }

            $preview = $contents;
            $truncated = $size > $limit;
        }

        return [
            'name' => basename($absolutePath),
            'path' => $this->virtualPath($relativePath),
            'size' => is_int($size) ? $size : 0,
            'mime' => $mime,
            'preview' => $preview,
            'truncated' => $truncated,
        ];
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
}
