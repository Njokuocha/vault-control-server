<?php

namespace App\Support;

class ChunkUploadSize
{
    public function bytes(): int
    {
        $configured = max(1, (int) config('vault.chunk_size_mb', 5)) * 1024 * 1024;
        $uploadLimit = $this->phpLimitBytes('upload_max_filesize');
        $postLimit = $this->phpLimitBytes('post_max_size');
        if ($postLimit > 0 && $postLimit <= 262144) {
            throw new \RuntimeException('PHP post_max_size is too small to accept an upload chunk.');
        }

        $limits = array_filter([
            $configured,
            $uploadLimit > 0 ? $uploadLimit : null,
            $postLimit > 0 ? $postLimit - 262144 : null,
        ]);
        $chunkSize = (int) min($limits);

        if ($chunkSize < 1) {
            throw new \RuntimeException('The PHP request limits do not allow an upload chunk.');
        }

        return $chunkSize;
    }

    private function phpLimitBytes(string $setting): int
    {
        $value = (string) ini_get($setting);

        return function_exists('ini_parse_quantity') ? max(0, (int) ini_parse_quantity($value)) : 0;
    }
}
