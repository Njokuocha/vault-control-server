<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('vault.admin.email')));
        $password = config('vault.admin.password');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Set a valid ADMIN_EMAIL in the environment before seeding.');
        }

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set an ADMIN_PASSWORD of at least 12 characters in the environment before seeding.');
        }

        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => config('vault.admin.name', 'Administrator'),
                'password' => $password,
            ],
        );

        if (! Setting::query()->whereKey('upload_key')->exists()) {
            $uploadKey = config('vault.initial_upload_key');
            $uploadKey = is_string($uploadKey) && $uploadKey !== '' ? $uploadKey : Str::random(64);

            app(\App\Services\SettingService::class)->set('upload_key', $uploadKey);
        }
    }
}
