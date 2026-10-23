<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class SettingService
{
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
