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
     * TAHAP 7: Ask AI Agent
     * Mencari momen spesifik berdasarkan query user.
     */
    public function askAI(Request $request, $videoId, AIHighlightService $aiService)
    {
        $request->validate([
            'query' => 'required|string|min:3'
        ]);

        // 1. Verifikasi akses video
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$video || !$video->transcription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Video atau transkripsi tidak ditemukan.'
            ], 404);
        }

        // 2. Minta AI mencari momen berdasarkan query
        $results = $aiService->getHighlights((string) $video->transcription->full_text, $request->get('query'));

        if (empty($results)) {
            return response()->json([
                'status' => 'error',
                'message' => 'AI tidak menemukan momen yang sesuai dengan permintaan Anda.'
            ], 422);
        }

        // 3. Simpan hasil sebagai klip baru
        $newClips = [];
        foreach ($results as $item) {
            $newClips[] = Clip::create([
                'video_id'    => $video->id,
                'title'       => $item['title'],
                'start_time'  => $item['start_time'],
                'end_time'    => $item['end_time'],
                'viral_score' => $item['viral_score'],
                'status'      => 'rendering', // Siap dipotong FFmpeg di tahap 8
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => count($newClips) . ' momen baru ditemukan oleh AI Agent.',
            'data' => $newClips
        ]);
    }

    /**
     * Menampilkan semua klip berdasarkan video_id tertentu.
     */
    public function index($videoId)
    {
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$video) {
            return response()->json([
                'status' => 'error',
                'message' => 'Video tidak ditemukan atau Anda tidak memiliki akses.'
            ], 404);
        }

        $clips = $video->clips()->orderBy('viral_score', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'video_title' => $video->title,
            'data' => $clips
        ]);
    }

    /**
     * Menampilkan detail satu klip spesifik.
     */
    public function show($id)
    {
        $clip = Clip::with('video')->find($id);

        if (!$clip) {
            return response()->json([
                'status' => 'error',
                'message' => 'Klip tidak ditemukan.'
            ], 404);
        }

        if ($clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda tidak memiliki akses ke klip ini.'
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $clip
        ]);
    }
}
