<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Video;
use Illuminate\Support\Facades\Auth;

class VideoController extends Controller
{
    // GET /api/videos - Library Video User
    public function index()
    {
        $videos = Auth::user()->videos()->latest()->get();
        return response()->json([
            'status' => 'success',
            'data' => $videos
        ]);
    }

    // POST /api/videos/process - Input Video Baru
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'url' => 'required|url',
            'duration' => 'required|integer|max:1800',
        ]);

        $user = Auth::user();

        // 1. Cek biaya (Fixed 10 token sesuai diskusi)
        if ($user->tier !== 'business' && $user->remaining_credits < 10) {
            return response()->json([
                'status' => 'error',
                'message' => 'Saldo tidak cukup. Butuh minimal 10 token untuk memproses video.'
            ], 403);
        }

        // 2. Proses pembuatan record video
        $video = Video::create([
            'user_id' => $user->id,
            'title' => $request->title,
            'source_url' => $request->url,
            'duration' => $request->duration,
            'status' => 'pending',
        ]);

        // 3. Potong saldo jika bukan Business
        if ($user->tier !== 'business') {
            $user->decrement('remaining_credits', 10);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Video berhasil dikirim. Saldo terpotong 10 token.',
            'data' => $video,
            'user_stats' => [
                'remaining_credits' => $user->remaining_credits,
                'tier' => $user->tier
            ]
        ], 201);
    }
}
