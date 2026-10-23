<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class SettingService
{
    public function maxUploadMegabytes(): int
    {
        $configured = $this->get('max_upload_mb');

        if ($configured === null) {
            return max(0, (int) config('vault.max_upload_mb', 100));
        }

        if (! ctype_digit($configured)) {
            throw new \UnexpectedValueException('The configured upload size setting is invalid.');
        }

        return (int) $configured;
    }

    public function apiUploadsEnabled(): bool
    {
        $configured = $this->get('api_upload_enabled');

        if ($configured === null) {
            return (bool) config('vault.api_upload_enabled', true);
        }

        if (! in_array($configured, ['0', '1'], true)) {
            throw new \UnexpectedValueException('The configured API upload setting is invalid.');
        }

        return $configured === '1';
    }

    public function get(string $key): ?string
    {
        $value = Setting::query()->whereKey($key)->value('value');

        return $value === null ? null : Crypt::decryptString($value);
    }

    public function set(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => Crypt::encryptString($value)],
        );
    }
}
