<?php

use App\Http\Controllers\Api\UploadController;
use App\Http\Middleware\VerifyUploadKey;
use Illuminate\Support\Facades\Route;

Route::post('/upload', [UploadController::class, 'store'])
    ->middleware(['throttle:60,1', VerifyUploadKey::class])
    ->name('api.upload');
