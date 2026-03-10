<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\Clip;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage; // Wajib untuk integrasi Supabase
use Carbon\Carbon;
use Carbon\Carbon as CarbonCarbon;

class DashboardController extends Controller
{
    /**
     * TAHAP 11 & 12: Integrasi Dashboard Utama Selengkap-lengkapnya.
     * Endpoint: GET /api/dashboard
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        // --- LOGIKA RESET KREDIT OTOMATIS (Tahap 3 & 15) ---
        $today = Carbon::today()->toDateString();
        if ($user->tier === 'free' && $user->last_reset_date !== $today) {
            $user->update([
                'remaining_credits' => 10,
                'last_reset_date' => $today
            ]);
        }

        // --- 1. RINGKASAN PROFIL & KREDIT ---
        $profile = [
            'name' => $user->name,
            'email' => $user->email,
            'tier' => strtoupper((string) $user->tier),
            'credits' => [
                'remaining' => $user->remaining_credits,
                'max_capacity' => $this->getMaxCredits((string) $user->tier),
                'is_low' => $user->remaining_credits < 5,
            ],
            'last_reset' => $user->last_reset_date
        ];

        // --- 2. STATISTIK GLOBAL (Counters) ---
        $counters = [
            'total_videos_processed' => $user->videos()->count(),
            'total_clips_generated' => Clip::whereHas('video', fn($q) => $q->where('user_id', $user->id))->count(),
            'active_tasks' => $user->videos()->whereIn('status', ['pending', 'processing'])->count(),
        ];

        // --- 3. TRACKER PROGRES AKTIF (Tahap 11) ---
        $activeProcesses = Video::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->with('transcription:id,video_id,created_at')
            ->latest()
            ->get()
            ->map(function (Video $video) {
                return [
                    'id' => $video->id,
                    'title' => $video->title,
                    'status' => $video->status,
                    'progress_percentage' => $this->calculateProgress($video),
                    'step' => $this->getCurrentStepName($video),
                    'created_at' => $video->created_at ? $video->created_at->diffForHumans() : null,
                ];
            });

        // --- 4. TOP CLIPS GALLERY (FIX SUPABASE - Tahap 12) ---
        $topClips = Clip::whereHas('video', fn($q) => $q->where('user_id', $user->id))
            ->where('status', 'ready')
            ->orderBy('viral_score', 'desc')
            ->limit(8)
            ->get()
            ->map(fn(Clip $clip) => [
                'id' => $clip->id,
                'title' => $clip->title,
                'viral_score' => $clip->viral_score,
                // UPDATE: Mengambil URL Publik dari Supabase
                'video_url' => $clip->clip_path ? Storage::url('supabase/' . $clip->clip_path) : null,
                'thumbnail_url' => $clip->thumbnail_path ? Storage::url('supabase/' . $clip->thumbnail_path) : null,
                'source_video' => $clip->video->title ?? 'Untitled',
                'duration_seconds' => round((float)$clip->end_time - (float)$clip->start_time, 2),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'profile' => $profile,
                'stats' => $counters,
                'active_tasks' => $activeProcesses,
                'clip_gallery' => $topClips
            ]
        ]);
    }

    /**
     * Helper: Kapasitas maksimal kredit per Tier
     */
    private function getMaxCredits(string $tier): int
    {
        return match ($tier) {
            'starter' => 100,
            'pro' => 300,
            'business' => 9999, 
            default => 10,
        };
    }

    /**
     * Helper: Estimasi persentase progres untuk UI
     */
    private function calculateProgress(Video $video): int
    {
        if ($video->status === 'pending') return 10;
        if ($video->transcription) return 70;
        return 40;
    }

    /**
     * Helper: Nama langkah saat ini untuk UI
     */
    private function getCurrentStepName(Video $video): string
    {
        if ($video->status === 'pending') return 'Waiting in Queue';
        if (!$video->transcription) return 'Transcribing Audio...';
        return 'Generating AI Highlights & Rendering...';
    }
}
