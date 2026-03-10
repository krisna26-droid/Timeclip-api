<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\ClipController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TranscriptionController;

// Import Controller Admin Baru
use App\Http\Controllers\Api\Admin\AdminStatsController;
use App\Http\Controllers\Api\Admin\AdminUserController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::get('/auth/github/redirect', [AuthController::class, 'githubRedirect']);
Route::get('/auth/github/callback', [AuthController::class, 'githubCallback']);

/*
|--------------------------------------------------------------------------
| Protected Routes (Authenticated Users)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth & Profile
    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard User
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // User Credits Detail
    Route::get('/user/credits', function (Request $request) {
        $user   = $request->user();
        $maxCap = [
            'free'     => 10,
            'starter'  => 100,
            'pro'      => 300,
            'business' => 'unlimited'
        ];
        return response()->json([
            'remaining_credits' => $user->remaining_credits,
            'tier'              => $user->tier,
            'max_cap'           => $maxCap[$user->tier] ?? 10,
            'last_reset'        => $user->last_reset_date
        ]);
    });

    // Video Management
    Route::get('/videos', [VideoController::class, 'index']);
    Route::post('/videos/process', [VideoController::class, 'store']);
    Route::get('/videos/{id}', [VideoController::class, 'show']);

    // Transcription Editor
    Route::get('/videos/{video_id}/transcription', [TranscriptionController::class, 'show']);
    Route::put('/videos/{video_id}/transcription', [TranscriptionController::class, 'update']);
    Route::post('/videos/{video_id}/transcription/rerender', [TranscriptionController::class, 'rerender']);

    // Clips Management
    Route::get('/clips/gallery', [ClipController::class, 'gallery']);
    Route::get('/videos/{video_id}/clips', [ClipController::class, 'index']);
    Route::get('/clips/{id}', [ClipController::class, 'show']);
    Route::put('/clips/{id}', [ClipController::class, 'update']);
    Route::get('/clips/{id}/stream', [ClipController::class, 'stream']);
    Route::get('/clips/{id}/download', [ClipController::class, 'download'])->name('clips.download');
    Route::post('/clips/{id}/rerender', [ClipController::class, 'rerender']);
    Route::get('/clips/{id}/subtitle', [ClipController::class, 'showSubtitle']);
    Route::put('/clips/{id}/subtitle', [ClipController::class, 'updateSubtitle']);

    // AI Agent Tool
    Route::post('/videos/{video_id}/ask-ai', [ClipController::class, 'askAI']);

    /*
    |--------------------------------------------------------------------------
    | Admin Routes (Hanya untuk role 'admin')
    |--------------------------------------------------------------------------
    */
    Route::middleware(['admin'])->prefix('admin')->group(function () {

        // Stats & System Monitoring
        Route::get('/stats', [AdminStatsController::class, 'index']);
        Route::get('/logs', [AdminStatsController::class, 'latestLogs']);

        // User Management CRUD
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);

        // Manual Credit Control
        Route::post('/users/{id}/adjust-credits', [AdminUserController::class, 'adjustCredits']);
    });
});
