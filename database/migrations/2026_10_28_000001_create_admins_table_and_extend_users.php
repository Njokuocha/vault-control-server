<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique();
            $table->string('department', 20)->nullable()->index();
            $table->string('account_status', 20)->default('active')->index();
            $table->string('profile_photo_path')->nullable();
        });

        $adminEmail = strtolower(trim((string) config('vault.admin.email')));
        if ($adminEmail === '') {
            return;
        }

        $legacyAdmin = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [$adminEmail])
            ->first();

        if ($legacyAdmin === null) {
            return;
        }

        DB::table('admins')->insertOrIgnore([
            'name' => $legacyAdmin->name,
            'email' => $legacyAdmin->email,
            'password' => $legacyAdmin->password,
            'remember_token' => $legacyAdmin->remember_token,
            'created_at' => $legacyAdmin->created_at,
            'updated_at' => $legacyAdmin->updated_at,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropIndex(['department']);
            $table->dropIndex(['account_status']);
            $table->dropColumn(['username', 'department', 'account_status', 'profile_photo_path']);
        });

        Schema::dropIfExists('admins');
    }
};
