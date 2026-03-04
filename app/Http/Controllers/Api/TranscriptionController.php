<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transcription;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TranscriptionController extends Controller
{
    /**
     * TAHAP 13: Get Transcription for Editor
     * Mengambil data transkripsi lengkap untuk dimuat ke timeline editor.
     */
    public function show($videoId)
    {
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $transcription = $video->transcription;

        if (!$transcription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transkripsi belum tersedia.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'video_id'  => $video->id,
                'full_text' => $transcription->full_text,
                'words'     => $transcription->json_data['words'] ?? [], // Data per kata untuk timeline
            ]
        ]);
    }

    /**
     * TAHAP 13: Update Transcription
     * Menyimpan hasil revisi teks dari user.
     */
    public function update(Request $request, $videoId)
    {
        $request->validate([
            'full_text' => 'required|string',
            'words'     => 'required|array',
        ]);

        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $transcription = $video->transcription;

        // Update data transkripsi
        $transcription->update([
            'full_text' => $request->full_text,
            'json_data' => [
                'full_text' => $request->full_text,
                'words'     => $request->words
            ]
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Transkripsi berhasil diperbarui. Siap untuk render ulang.',
            'data' => $transcription
        ]);
    }
}