<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use App\Jobs\DownloadVideoJob;

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
            'duration' => 'required|integer|max:1800', // Maksimal 30 menit [cite: 244]
        ]);

        $user = Auth::user();

        // Validasi Concurrency: Maksimal 2 proses aktif 
        $activeProcesses = Video::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        if ($activeProcesses >= 2) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda memiliki 2 proses yang sedang berjalan. Tunggu hingga selesai.'
            ], 429);
        }

        // Validasi Saldo Kredit [cite: 246, 247]
        if ($user->tier !== 'business' && $user->remaining_credits < 10) {
            return response()->json([
                'status' => 'error',
                'message' => 'Saldo tidak cukup. Butuh 10 kredit.'
            ], 403);
        }

        $video = Video::create([
            'user_id' => $user->id,
            'title' => $request->title,
            'source_url' => $request->url,
            'duration' => $request->duration,
            'status' => 'pending',
        ]);

        DownloadVideoJob::dispatch($video);

        if ($user->tier !== 'business') {
            $user->decrement('remaining_credits', 10);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Video berhasil masuk antrean.',
            'data' => $video
        ], 201);
    }
}
