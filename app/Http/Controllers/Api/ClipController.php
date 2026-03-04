<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Video;
use App\Models\Clip;
use App\Services\AIHighlightService;
use Illuminate\Support\Facades\Auth;

class ClipController extends Controller
{
    /**
     * TAHAP 12: Clip Output & Preview Gallery
     * Memperbaiki logic agar data muncul di Postman sesuai kepemilikan user.
     */
    public function gallery()
    {
        $user = Auth::user();

        // Fix: Query harus memuat 'user_id' agar filter ownership tidak null
        $clips = Clip::whereHas('video', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->where('status', 'ready') //
            ->with(['video' => function ($q) {
                $q->select('id', 'user_id', 'title'); // WAJIB ada user_id di sini
            }])
            ->orderBy('viral_score', 'desc')
            ->paginate(12);

        // Memberikan response yang informatif jika data memang belum ada
        if ($clips->isEmpty()) {
            return response()->json([
                'status' => 'success',
                'message' => 'Belum ada klip yang siap (status ready) untuk User ID: ' . $user->id,
                'data' => []
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'data' => $clips->getCollection()->map(function ($clip) {
                return [
                    'id' => $clip->id,
                    'title' => $clip->title,
                    'viral_score' => $clip->viral_score,
                    'duration' => round($clip->end_time - $clip->start_time, 2),
                    'video_title' => $clip->video->title ?? 'Untitled Video',
                    'clip_url' => $clip->clip_path ? asset('storage/' . $clip->clip_path) : null,
                    'created_at' => $clip->created_at->diffForHumans(),
                ];
            }),
            'meta' => [
                'current_page' => $clips->currentPage(),
                'last_page' => $clips->lastPage(),
                'total' => $clips->total(),
            ]
        ]);
    }

    /**
     * TAHAP 7: Ask AI Agent
     */
    public function askAI(Request $request, $videoId, AIHighlightService $aiService)
    {
        $request->validate([
            'query' => 'required|string|min:3'
        ]);

        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$video || !$video->transcription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Video atau transkripsi tidak ditemukan.'
            ], 404);
        }

        $results = $aiService->getHighlights((string) $video->transcription->full_text, $request->get('query'));

        if (empty($results)) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI tidak menemukan momen yang sesuai.'
            ], 422);
        }

        $newClips = [];
        foreach ($results as $item) {
            $newClips[] = Clip::create([
                'video_id'    => $video->id,
                'title'       => $item['title'],
                'start_time'  => $item['start_time'],
                'end_time'    => $item['end_time'],
                'viral_score' => $item['viral_score'],
                'status'      => 'rendering',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => count($newClips) . ' momen baru ditemukan.',
            'data' => $newClips
        ]);
    }

    /**
     * Mengambil semua klip dari satu video spesifik
     */
    public function index($videoId)
    {
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$video) {
            return response()->json([
                'status' => 'error',
                'message' => 'Video tidak ditemukan.'
            ], 404);
        }

        $clips = $video->clips()->orderBy('viral_score', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'video_title' => $video->title,
            'data' => $clips->map(fn($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'viral_score' => $c->viral_score,
                'status' => $c->status,
                'clip_url' => $c->clip_path ? asset('storage/' . $c->clip_path) : null,
            ])
        ]);
    }

    /**
     * Menampilkan detail satu klip spesifik
     */
    public function show($id)
    {
        // Fix eager loading select
        $clip = Clip::with('video:id,user_id,title')->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Klip tidak ditemukan atau akses ditolak.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $clip->id,
                'title' => $clip->title,
                'viral_score' => $clip->viral_score,
                'clip_url' => asset('storage/' . $clip->clip_path),
                'parent_video' => $clip->video->title
            ]
        ]);
    }
}
