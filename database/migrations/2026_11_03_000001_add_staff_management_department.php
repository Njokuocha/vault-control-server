<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('departments')->insertOrIgnore([
            'code' => 'sm',
            'name' => 'Staff Management',
            'rank' => 'Staff Manager',
            'rank_short' => 'SM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $departmentId = DB::table('departments')->where('code', 'sm')->value('id');
        if ($departmentId !== null && ! DB::table('users')->where('department_id', $departmentId)->exists()) {
            DB::table('departments')->where('id', $departmentId)->delete();
        }
    }
};
