<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\VersionedCache;
use Illuminate\Support\Facades\Crypt;

class SettingService
{
    public function maxUploadMegabytes(): int
    {
        return $this->integerSetting('max_upload_mb', 'max_upload_mb');
    }

    public function maxVideoUploadMegabytes(): int
    {
        return $this->integerSetting('max_video_upload_mb', 'max_video_upload_mb');
    }

    private function integerSetting(string $key, string $configKey): int
    {
        $configured = $this->get($key);

        if ($configured === null) {
            return max(0, (int) config("vault.{$configKey}", 0));
        }

        if (! ctype_digit($configured)) {
            throw new \UnexpectedValueException("The configured {$key} setting is invalid.");
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
        $value = app(VersionedCache::class)->remember(
            'settings',
            'setting:'.$key,
            now()->addMinutes(5),
            fn (): mixed => Setting::query()->whereKey($key)->value('value'),
        );

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
