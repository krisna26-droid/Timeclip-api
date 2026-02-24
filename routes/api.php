<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\ClipController;

/*
|--------------------------------------------------------------------------
| API PUBLIC (Tanpa Login)
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->name('login');

/*
|--------------------------------------------------------------------------
| API PROTECTED (Wajib Bearer Token Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // 1. User Management & Credits
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
            'max_cap' => $maxCap[$user->tier] ?? 10
        ]);
    });

    // 2. Library Video (Master Video)
    Route::get('/videos', [VideoController::class, 'index']);
    Route::post('/videos/process', [VideoController::class, 'store']);

    // 3. Clip Management & AI Agent
    // Mengambil semua klip dari satu video spesifik
    Route::get('/videos/{video_id}/clips', [ClipController::class, 'index']);
    
    // 🔥 TAHAP 7: Ask AI Agent (Mencari momen spesifik via chat/query)
    // Endpoint: POST /api/videos/{video_id}/ask-ai
    Route::post('/videos/{video_id}/ask-ai', [ClipController::class, 'askAI']);
    
    // Mengambil detail satu klip (untuk Editor/Preview)
    Route::get('/clips/{id}', [ClipController::class, 'show']);
});