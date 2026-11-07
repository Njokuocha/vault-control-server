<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('rank');
            $table->string('rank_short', 20)->unique();
            $table->timestamps();
        });

        foreach ([
            ['code' => 'cfm', 'name' => 'Case File Management', 'rank' => 'Case Manager', 'rank_short' => 'CFM'],
            ['code' => 'pm', 'name' => 'Profile Management', 'rank' => 'Profile Manager', 'rank_short' => 'PM'],
            ['code' => 'fm', 'name' => 'File Management', 'rank' => 'File Manager', 'rank_short' => 'FM'],
        ] as $department) {
            DB::table('departments')->insert([
                ...$department,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->after('department')->constrained()->restrictOnDelete();
        });

        foreach (DB::table('departments')->get(['id', 'code']) as $department) {
            DB::table('users')
                ->where('department', $department->code)
                ->update(['department_id' => $department->id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
