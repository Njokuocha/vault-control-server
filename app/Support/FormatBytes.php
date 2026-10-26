<?php

namespace App\Support;

class FormatBytes
{
    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;

        foreach ($units as $unit) {
            if ($size < 1024 || $unit === 'TB') {
                return number_format($size, 1).' '.$unit;
            }

            $size /= 1024;
        }

        return number_format($size, 1).' TB';
    }
}
