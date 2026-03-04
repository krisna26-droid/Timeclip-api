<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\ClipController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TranscriptionController;

/*
|--------------------------------------------------------------------------
| API PUBLIC (Tanpa Login)
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::get('/auth/github/redirect', [AuthController::class, 'githubRedirect']);
Route::get('/auth/github/callback', [AuthController::class, 'githubCallback']);

/*
|--------------------------------------------------------------------------
| API PROTECTED (Wajib Bearer Token Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // 🔥 TAMBAHAN UTAMA: Pintu Utama Dashboard (Tahap 11)
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // 1. User Management & Credits (Tahap 3)
    Route::get('/user/credits', function (Request $request) {
        $user = $request->user();
        $maxCap = [
            'free' => 10,
            'starter' => 100,
            'pro' => 300,
            'business' => 'unlimited'
        ];
        return response()->json([
            'remaining_credits' => $user->remaining_credits,
            'tier' => $user->tier,
            'max_cap' => $maxCap[$user->tier] ?? 10,
            'last_reset' => $user->last_reset_date
        ]);
    });

    // 2. Library Video & Tracker (Tahap 4 & 11)
    Route::get('/videos', [VideoController::class, 'index']);
    Route::post('/videos/process', [VideoController::class, 'store']);
    Route::get('/videos/{id}', [VideoController::class, 'show']);

    // 3. Clip Management & AI Gallery (Tahap 12)
    Route::get('/clips/gallery', [ClipController::class, 'gallery']); // ✅ DIPINDAH KE ATAS
    Route::get('/videos/{video_id}/clips', [ClipController::class, 'index']);
    Route::get('/clips/{id}', [ClipController::class, 'show']);

    // 4. AI Agent Feature (Tahap 7)
    Route::post('/videos/{video_id}/ask-ai', [ClipController::class, 'askAI']);

    // 5. Transcription
    Route::get('/videos/{video_id}/transcription', [TranscriptionController::class, 'show']);
    Route::put('/videos/{video_id}/transcription', [TranscriptionController::class, 'update']);
});