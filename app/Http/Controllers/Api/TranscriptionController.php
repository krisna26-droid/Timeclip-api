<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVideoClipJob;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TranscriptionController extends Controller
{
    /**
     * Get Transcription for Editor
     */
    public function show($videoId)
    {
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $transcription = $video->transcription;

        if (!$transcription) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Transkripsi belum tersedia.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => [
                'video_id'  => $video->id,
                'full_text' => $transcription->full_text,
                'words'     => $transcription->json_data['words'] ?? [],
            ]
        ]);
    }

    /**
     * Update Transcription — simpan edit caption dari user
     */
    public function update(Request $request, $videoId)
    {
        $request->validate([
            'full_text'       => 'required|string',
            'words'           => 'required|array',
            'words.*.word'    => 'required|string',
            'words.*.start'   => 'required|numeric',
            'words.*.end'     => 'required|numeric',
        ]);

        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $transcription = $video->transcription;

        if (!$transcription) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Transkripsi belum tersedia.'
            ], 404);
        }

        // Merge agar data lain di json_data tidak hilang (misal metadata Gemini)
        $transcription->update([
            'full_text' => $request->full_text,
            'json_data' => array_merge(
                $transcription->json_data ?? [],
                [
                    'full_text' => $request->full_text,
                    'words'     => $request->words,
                ]
            )
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Transkripsi berhasil diperbarui. Siap untuk render ulang.',
            'data'    => $transcription
        ]);
    }

    /**
     * Re-render semua klip milik video ini dengan caption yang sudah diedit
     */
    public function rerender($videoId)
    {
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $clips = $video->clips()->where('status', 'ready')->get();

        if ($clips->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak ada klip yang bisa di-render ulang.'
            ], 404);
        }

        foreach ($clips as $clip) {
            // Reset status ke rendering lalu dispatch ulang
            $clip->update(['status' => 'rendering']);
            ProcessVideoClipJob::dispatch($clip);
        }

        return response()->json([
            'status'  => 'success',
            'message' => count($clips) . ' klip sedang di-render ulang dengan caption baru.',
            'data'    => ['clips_queued' => count($clips)]
        ]);
    }
}
