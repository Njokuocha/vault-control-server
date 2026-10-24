<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ManualUploadController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VaultShellController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('auth.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('auth.logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/archive/files', [DashboardController::class, 'archiveFiles'])->name('archive.files');
    Route::view('/calculator', 'calculator.index')->name('calculator.index');

    Route::get('/uploads', [ManualUploadController::class, 'create'])->name('uploads.create');
    Route::post('/uploads', [ManualUploadController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('uploads.store');

    Route::prefix('/shell')->name('shell.')->group(function () {
        Route::get('/directory', [VaultShellController::class, 'index'])->name('directory');
        Route::get('/preview', [VaultShellController::class, 'preview'])->name('preview');
        Route::get('/stream', [VaultShellController::class, 'stream'])->name('stream');
        Route::get('/download', [VaultShellController::class, 'download'])->name('download');
        Route::delete('/file', [VaultShellController::class, 'destroy'])->name('file.destroy');
        Route::post('/directory', [VaultShellController::class, 'makeDirectory'])->name('directory.create');
        Route::post('/copy', [VaultShellController::class, 'copy'])->name('copy');
        Route::post('/move', [VaultShellController::class, 'move'])->name('move');
    });

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/upload-key', [SettingsController::class, 'updateUploadKey'])
        ->name('settings.key.reset');
    Route::post('/settings/upload-policy', [SettingsController::class, 'updateUploadPolicy'])
        ->name('settings.upload-policy.update');
    Route::post('/settings/password', [SettingsController::class, 'updatePassword'])
        ->name('settings.password.update');
});
