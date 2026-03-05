<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Jobs\DownloadVideoJob;
use App\Models\Clip;

class VideoController extends Controller
{
    // GET /api/videos
    public function index()
    {
        $videos = Auth::user()
            ->videos()
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $videos
        ]);
    }

    // POST /api/videos/process
    public function store(Request $request)
    {
        $request->validate([
            'title'    => 'required|string|max:255',
            'url'      => 'required|url',
            'duration' => 'required|integer|max:1800',
        ]);

        $user = Auth::user();

        // 🔹 Batasi maksimal 2 proses aktif
        $active = Video::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        if ($active >= 2) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Maksimal 2 proses aktif.'
            ], 429);
        }

        // 🔹 Cek Kredit
        if ($user->tier !== 'business' && $user->remaining_credits < 10) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Kredit tidak cukup (butuh 10).'
            ], 403);
        }

        $video = Video::create([
            'user_id'   => $user->id,
            'title'     => $request->title,
            'source_url' => $request->url,
            'duration'  => $request->duration,
            'status'    => 'pending',
        ]);

        // Dispatch job download
        DownloadVideoJob::dispatch($video);

        // Potong kredit setelah job masuk antrean
        if ($user->tier !== 'business') {
            $user->decrement('remaining_credits', 10);
        }

        Log::info("Video masuk antrean.", [
            'video_id' => $video->id
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Video masuk antrean.',
            'data'    => $video
        ], 201);
    }

    public function dashboardStats()
    {
        $user = Auth::user();

        // Hitung ringkasan video berdasarkan status
        $stats = [
            'total_videos'     => $user->videos()->count(),
            'processing_now'   => $user->videos()->whereIn('status', ['pending', 'processing'])->count(),
            'completed_videos' => $user->videos()->where('status', 'completed')->count(),
            'total_clips'      => Clip::whereHas('video', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->count(),
        ];

        return response()->json([
            'status' => 'success',
            'user'   => [
                'name'              => $user->name,
                'tier'              => $user->tier, //
                'remaining_credits' => $user->remaining_credits, //
            ],
            'stats'  => $stats
        ]);
    }
    public function show($id)
    {
        $video = Auth::user()->videos()->find($id);

        if (!$video) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Video tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $video
        ]);
    }
}
