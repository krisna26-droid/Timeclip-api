<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClipSubtitle;
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
     * Update Transcription — simpan edit dari user
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

        // Simpan perubahan transkripsi utama
        $transcription->update([
            'full_text' => $request->full_text,
            'json_data' => [
                'full_text' => $request->full_text,
                'words'     => $request->words,
            ]
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Transkripsi berhasil diperbarui.',
            'data'    => $transcription
        ]);
    }

    /**
     * Sync subtitle ke semua klip milik video ini
     */
    public function rerender($videoId)
    {
        $video = Video::where('id', $videoId)
            ->where('user_id', Auth::id())
            ->with(['transcription', 'clips'])
            ->firstOrFail();

        if (!$video->transcription) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Transkripsi tidak ditemukan.'
            ], 404);
        }

        $clips = $video->clips()->where('status', 'ready')->get();

        if ($clips->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Tidak ada klip yang bisa di-sync.'
            ], 404);
        }

        $allWords = $video->transcription->json_data['words'] ?? [];
        $synced   = 0;

        foreach ($clips as $clip) {
            $clipStart = (float) $clip->start_time;
            $clipEnd   = (float) $clip->end_time;
            $duration  = $clipEnd - $clipStart;

            /**
             * PERBAIKAN: Tambahkan toleransi 0.2 detik di awal/akhir 
             * agar kata tidak hilang karena pembulatan atau jeda vokal kecil.
             */
            $filteredWords = array_values(array_filter(
                $allWords,
                fn($w) => $w['start'] >= ($clipStart - 0.2) && $w['start'] < ($clipEnd + 0.2)
            ));

            $normalizedWords = array_map(function ($w) use ($clipStart, $duration) {
                // Sesuaikan start dan end kata dengan durasi klip
                $start = max(0, $w['start'] - $clipStart);
                $end   = $w['end'] - $clipStart;

                return [
                    'word'  => $w['word'],
                    'start' => round($start, 3),
                    'end'   => round(min($end, $duration), 3),
                ];
            }, $filteredWords);

            $filteredText = implode(' ', array_column($normalizedWords, 'word'));

            ClipSubtitle::updateOrCreate(
                ['clip_id' => $clip->id],
                [
                    'full_text' => $filteredText,
                    'words'     => $normalizedWords,
                ]
            );

            $synced++;
        }

        return response()->json([
            'status'  => 'success',
            'message' => "{$synced} subtitle klip berhasil di-sync dari transkripsi terbaru.",
            'data'    => ['clips_synced' => $synced]
        ]);
    }
}
