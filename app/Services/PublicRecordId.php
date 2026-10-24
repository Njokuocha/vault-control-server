<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PublicRecordId
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function generate(string $modelClass, string $prefix): string
    {
        for ($length = 7; $length <= 9; $length++) {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $candidate = $prefix.'-'.$this->randomSegment($length);

                /** @var Model $modelClass */
                if (! $modelClass::query()->where('public_id', $candidate)->exists()) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException("Unable to generate a unique {$prefix} record identifier.");
    }

    private function randomSegment(int $length): string
    {
        $segment = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($index = 0; $index < $length; $index++) {
            $segment .= self::ALPHABET[random_int(0, $last)];
        }

        return $segment;
    }
}
