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
                'id'            => $clip->id,
                'title'         => $clip->title,
                'viral_score'   => $clip->viral_score,
                'duration'      => round($clip->end_time - $clip->start_time, 2),
                'video_title'   => $clip->video->title ?? 'Untitled Video',
                'clip_url'      => $clip->clip_path ? url('/api/clips/' . $clip->id . '/stream') : null,
                'thumbnail_url' => $clip->thumbnail_path ? asset('storage/' . $clip->thumbnail_path) : null,
                'created_at'    => $clip->created_at->diffForHumans(),
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

        $clips = $video->clips()->with('subtitle')->orderBy('viral_score', 'desc')->get();

        return response()->json([
            'status'      => 'success',
            'video_title' => $video->title,
            'data'        => $clips->map(fn($c) => [
                'id'            => $c->id,
                'title'         => $c->title,
                'viral_score'   => $c->viral_score,
                'status'        => $c->status,
                'start_time'    => $c->start_time,
                'end_time'      => $c->end_time,
                'clip_url'      => $c->clip_path ? url('/api/clips/' . $c->id . '/stream') : null,
                'thumbnail_url' => $c->thumbnail_path ? asset('storage/' . $c->thumbnail_path) : null,
                'subtitle'      => $c->subtitle ? [
                    'full_text' => $c->subtitle->full_text,
                    'words'     => $c->subtitle->words,
                ] : null,
            ])
        ]);
    }

    /**
     * Detail satu klip
     */
    public function show($id)
    {
        $clip = Clip::with(['video:id,user_id,title', 'subtitle'])->find($id);

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
                'status'        => $clip->status,
                'start_time'    => $clip->start_time,
                'end_time'      => $clip->end_time,
                'clip_url'      => $clip->clip_path ? url('/api/clips/' . $clip->id . '/stream') : null,
                'thumbnail_url' => $clip->thumbnail_path ? asset('storage/' . $clip->thumbnail_path) : null,
                'parent_video'  => $clip->video->title,
                'subtitle'      => $clip->subtitle ? [
                    'full_text' => $clip->subtitle->full_text,
                    'words'     => $clip->subtitle->words,
                ] : null,
            ]
        ]);
    }

    /**
     * Edit title klip
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $clip = Clip::with('video:id,user_id')->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Klip tidak ditemukan atau akses ditolak.'
            ], 404);
        }

        $clip->update(['title' => $request->title]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Judul klip berhasil diperbarui.',
            'data'    => [
                'id'    => $clip->id,
                'title' => $clip->title,
            ]
        ]);
    }

    /**
     * Ambil subtitle clip
     */
    public function showSubtitle($id)
    {
        $clip = Clip::with(['video:id,user_id', 'subtitle'])->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json(['status' => 'error', 'message' => 'Akses ditolak.'], 404);
        }

        $data = $this->getOrGenerateClipSubtitle($clip);

        return response()->json([
            'status' => 'success',
            'data'   => $data
        ]);
    }

    /**
     * Edit subtitle clip + auto re-render
     */
    public function updateSubtitle(Request $request, $id)
    {
        $request->validate([
            'full_text' => 'required|string',
            'words'     => 'required|array',
        ]);

        $clip = Clip::with('video')->find($id);

        if (!$clip || $clip->video->user_id !== Auth::id()) {
            return response()->json(['status' => 'error', 'message' => 'Akses ditolak.'], 403);
        }

        // UPDATE: Eloquent akan otomatis casting array ke JSON
        $clip->subtitle()->updateOrCreate(
            ['clip_id' => $clip->id],
            [
                'full_text' => $request->full_text,
                'words'     => $request->words,
            ]
        );

        $clip->update(['status' => 'rendering']);
        ProcessVideoClipJob::dispatch($clip);

        return response()->json(['status' => 'success', 'message' => 'Subtitle diperbarui.']);
    }

    /**
     * Stream klip video
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
    private function getOrGenerateClipSubtitle(Clip $clip)
    {
        // Jika user sudah pernah edit manual, ambil dari tabel subtitles
        if ($clip->subtitle) {
            return [
                'full_text' => $clip->subtitle->full_text,
                'words'     => $clip->subtitle->words
            ];
        }

        // Jika belum ada, potong otomatis dari transkripsi video induk
        $video = $clip->video()->with('transcription')->first();
        if (!$video || !$video->transcription) return null;

        $allWords = $video->transcription->json_data['words'] ?? [];
        $clipStart = (float) $clip->start_time;
        $clipEnd   = (float) $clip->end_time;

        // Filter kata yang masuk range waktu klip
        $filteredWords = array_values(array_filter($allWords, function ($w) use ($clipStart, $clipEnd) {
            return ($w['start'] >= $clipStart && $w['start'] <= $clipEnd);
        }));

        // Normalisasi waktu (start dari 0.0) agar Frontend mudah editnya
        $normalizedWords = array_map(function ($w) use ($clipStart) {
            return [
                'word'  => $w['word'],
                'start' => round($w['start'] - $clipStart, 3),
                'end'   => round($w['end'] - $clipStart, 3),
            ];
        }, $filteredWords);

        return [
            'full_text' => implode(' ', array_column($normalizedWords, 'word')),
            'words'     => $normalizedWords
        ];
    }
}
