<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {
    Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]))->name('health');
    Route::get('/configuration', fn () => response()->json(['data' => ApplicationSetting::values()]))->name('configuration');
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('register');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('login');
    });
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/media', [MediaController::class, 'index'])->middleware(CheckAbilities::class.':media:read')->name('media.index');
        Route::post('/media', [MediaController::class, 'store'])->middleware(CheckAbilities::class.':media:write')->name('media.store');
        Route::get('/media/{media}', [MediaController::class, 'show'])->middleware(CheckAbilities::class.':media:read')->name('media.show');
        Route::delete('/media/{media}', [MediaController::class, 'destroy'])->middleware(CheckAbilities::class.':media:write')->name('media.destroy');
        Route::post('/media/{media}/replace', [MediaController::class, 'replace'])->middleware(CheckAbilities::class.':media:write')->name('media.replace');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [ProfileController::class, 'show'])->middleware(CheckAbilities::class.':profile:read')->name('me');
        Route::patch('/me', [ProfileController::class, 'update'])->middleware(CheckAbilities::class.':profile:write')->name('me.update');
    });
});
