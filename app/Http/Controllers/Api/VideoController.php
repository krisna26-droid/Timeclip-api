<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Video;
use App\Models\Clip;
use App\Jobs\DownloadVideoJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class VideoController extends Controller
{
    /**
     * Helper privat untuk membangun URL Supabase secara konsisten
     */
    private function getFullUrl($path)
    {
        if (!$path) return null;

        $supabaseUrl    = config('filesystems.disks.supabase.url');
        $supabaseBucket = config('filesystems.disks.supabase.bucket');
        $cleanPath      = ltrim($path, '/');

        return "{$supabaseUrl}/storage/v1/object/public/{$supabaseBucket}/{$cleanPath}";
    }

    /**
     * GET /api/videos
     * List semua video milik user dengan URL file lengkap
     */
    public function index()
    {
        $videos = Auth::user()
            ->videos()
            ->latest()
            ->get()
            ->map(function ($video) {
                $video->file_url = $this->getFullUrl($video->video_path);
                return $video;
            });

        return response()->json([
            'status' => 'success',
            'data'   => $videos
        ]);
    }

    /**
     * POST /api/videos/process
     * Submit video baru untuk diproses (Download & AI Analysis)
     */
    public function store(Request $request)
    {
        $request->validate([
            'title'    => 'required|string|max:255',
            'url'      => 'required|url',
            'duration' => 'required|integer|max:1800',
        ]);

        $user = Auth::user();

        // 1. Batasi maksimal 2 proses aktif agar queue tidak overload
        $active = Video::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        if ($active >= 2) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Maksimal 2 proses aktif. Harap tunggu proses sebelumnya selesai.'
            ], 429);
        }

        // 2. Cek Kredit (Kecuali tier business)
        if ($user->tier !== 'business' && $user->remaining_credits < 10) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Kredit tidak cukup (butuh 10 kredit).'
            ], 403);
        }

        // 3. Gunakan DB Transaction agar pembuatan video & potong kredit aman (All or Nothing)
        try {
            return DB::transaction(function () use ($request, $user) {
                $video = Video::create([
                    'user_id'    => $user->id,
                    'title'      => $request->title,
                    'source_url' => $request->url,
                    'duration'   => $request->duration,
                    'status'     => 'pending',
                ]);

                // Potong kredit
                if ($user->tier !== 'business') {
                    $user->decrement('remaining_credits', 10);
                }

                // Dispatch job download ke antrean background
                DownloadVideoJob::dispatch($video);

                Log::info("Video ID {$video->id} berhasil masuk antrean oleh User ID {$user->id}");

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Video masuk antrean.',
                    'data'    => $video
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error("Gagal memproses video store: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Terjadi kesalahan sistem.'], 500);
        }
    }

    /**
     * GET /api/dashboard/stats
     * Statistik ringkas untuk UI Dashboard
     */
    public function dashboardStats()
    {
        $user = Auth::user();

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
                'tier'              => $user->tier,
                'remaining_credits' => $user->remaining_credits,
            ],
            'stats'  => $stats
        ]);
    }

    /**
     * GET /api/videos/{id}
     * Detail satu video
     */
    public function show($id)
    {
        $video = Auth::user()->videos()->find($id);

        if (!$video) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Video tidak ditemukan.'
            ], 404);
        }

        // Lampirkan URL file jika sudah ada
        $video->file_url = $this->getFullUrl($video->video_path);

        return response()->json([
            'status' => 'success',
            'data'   => $video
        ]);
    }
}
