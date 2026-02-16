<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MockController;

/*
|--------------------------------------------------------------------------
| API ASLI (Tersambung ke Database)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);


/*
|--------------------------------------------------------------------------
| MOCK API (Untuk Kebutuhan Testing Frontend)
|--------------------------------------------------------------------------
| Prefix: /api/mock/...
*/
Route::prefix('mock')->group(function () {
    Route::post('/login', [MockController::class, 'login']);
    Route::post('/register', [MockController::class, 'register']);
    Route::get('/videos', [MockController::class, 'indexVideos']);
    Route::get('/videos/{id}/clips', [MockController::class, 'getClips']);
    Route::post('/clips/{id}/ask-ai', [MockController::class, 'askAi']);
});