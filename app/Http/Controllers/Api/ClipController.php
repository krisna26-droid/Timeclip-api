<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVideoClipJob;
use App\Models\Clip;
use App\Models\Video;
use App\Services\AIHighlightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClipController extends Controller
{
    /**
     * Gallery semua klip siap milik user
     */
    public function gallery()
    {
        $user  = Auth::user();
        $clips = Clip::whereHas('video', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->where('status', 'ready')
            ->with(['video' => function ($q) {
                $q->select('id', 'user_id', 'title');
            }])
            ->orderBy('viral_score', 'desc')
            ->paginate(12);

        if ($clips->isEmpty()) {
            return response()->json([
                'status'  => 'success',
                'message' => 'Belum ada klip yang siap.',
                'data'    => []
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $clips->getCollection()->map(fn($clip) => [
                'id'             => $clip->id,
                'title'          => $clip->title,
                'viral_score'    => $clip->viral_score,
                'duration'       => round($clip->end_time - $clip->start_time, 2),
                'video_title'    => $clip->video->title ?? 'Untitled Video',
                'clip_url'       => $clip->clip_path ? url('/api/clips/' . $clip->id . '/stream') : null,
                'thumbnail_url'  => $clip->thumbnail_path ? asset('storage/' . $clip->thumbnail_path) : null,
                'created_at'     => $clip->created_at->diffForHumans(),
            ]),
            'meta' => [
                'current_page' => $clips->currentPage(),
                'last_page'    => $clips->lastPage(),
                'total'        => $clips->total(),
            ]
        ]);
    }

    /**
     * Semua klip dari satu video
     */
    public function index($videoId)
    {
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$video) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Video tidak ditemukan.'
            ], 404);
        }

        $clips = $video->clips()->orderBy('viral_score', 'desc')->get();

        return response()->json([
            'status'      => 'success',
            'video_title' => $video->title,
            'data'        => $clips->map(fn($c) => [
                'id'            => $c->id,
                'title'         => $c->title,
                'viral_score'   => $c->viral_score,
                'status'        => $c->status,
                'clip_url'      => $c->clip_path ? url('/api/clips/' . $c->id . '/stream') : null,
                'thumbnail_url' => $c->thumbnail_path ? asset('storage/' . $c->thumbnail_path) : null,
            ])
        ]);
    }

    /**
     * Detail satu klip
     */
    public function show($id)
    {
        $clip = Clip::with('video:id,user_id,title')->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Klip tidak ditemukan atau akses ditolak.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'id'            => $clip->id,
                'title'         => $clip->title,
                'viral_score'   => $clip->viral_score,
                'clip_url'      => $clip->clip_path ? url('/api/clips/' . $clip->id . '/stream') : null,
                'thumbnail_url' => $clip->thumbnail_path ? asset('storage/' . $clip->thumbnail_path) : null,
                'parent_video'  => $clip->video->title,
            ]
        ]);
    }

    /**
     * Stream klip video — agar bisa diputar langsung di browser/FE
     */
    public function stream($id)
    {
        $clip = Clip::with('video:id,user_id')->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Klip tidak ditemukan atau akses ditolak.'
            ], 404);
        }

        $path = storage_path('app/public/' . $clip->clip_path);

        if (!file_exists($path)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'File tidak ditemukan.'
            ], 404);
        }

        return response()->file($path, [
            'Content-Type'        => 'video/mp4',
            'Content-Disposition' => 'inline',
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'public, max-age=3600',
        ]);
    }

    /**
     * Download klip
     */
    public function download($id)
    {
        $clip = Clip::with('video:id,user_id')->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Klip tidak ditemukan atau akses ditolak.'
            ], 404);
        }

        $path = storage_path('app/public/' . $clip->clip_path);

        if (!file_exists($path)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'File tidak ditemukan.'
            ], 404);
        }

        return response()->download($path, $clip->title . '.mp4', [
            'Content-Type' => 'video/mp4',
        ]);
    }

    /**
     * Re-render satu klip spesifik
     */
    public function rerender($id)
    {
        $clip = Clip::with('video:id,user_id')->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Klip tidak ditemukan atau akses ditolak.'
            ], 404);
        }

        $clip->update(['status' => 'rendering']);
        ProcessVideoClipJob::dispatch($clip);

        return response()->json([
            'status'  => 'success',
            'message' => 'Klip sedang di-render ulang.',
        ]);
    }

    /**
     * Ask AI Agent
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
                'status'  => 'error',
                'message' => 'Video atau transkripsi tidak ditemukan.'
            ], 404);
        }

        $results = $aiService->getHighlights(
            (string) $video->transcription->full_text,
            $request->get('query')
        );

        if (empty($results)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'AI tidak menemukan momen yang sesuai.'
            ], 422);
        }

        $newClips = [];
        foreach ($results as $item) {
            $clip = Clip::create([
                'video_id'    => $video->id,
                'title'       => $item['title'],
                'start_time'  => $item['start_time'],
                'end_time'    => $item['end_time'],
                'viral_score' => $item['viral_score'],
                'status'      => 'rendering',
            ]);

            ProcessVideoClipJob::dispatch($clip);
            $newClips[] = $clip;
        }

        return response()->json([
            'status'  => 'success',
            'message' => count($newClips) . ' momen baru ditemukan.',
            'data'    => $newClips
        ]);
    }
}
