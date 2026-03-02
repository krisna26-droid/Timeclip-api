<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\Transcription;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ProcessTranscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $video;
    public $timeout = 900;
    public $tries = 2;

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    public function handle(GeminiService $gemini): void
    {
        Log::info("=== PROCESS TRANSCRIPTION START ===", ['video_id' => $this->video->id]);

        $this->video->update(['status' => 'processing']);

        $videoPath = storage_path('app/' . $this->video->file_path);
        $audioDir  = storage_path('app/private/audio');
        $audioPath = $audioDir . '/' . $this->video->id . '.mp3';

        // Pastikan folder audio ada
        if (!File::exists($audioDir)) {
            File::makeDirectory($audioDir, 0755, true);
        }

        // Validasi video input ada
        if (!File::exists($videoPath)) {
            Log::error("Video file tidak ditemukan: " . $videoPath);
            $this->video->update(['status' => 'failed']);
            return;
        }

        try {
            // FIX: Pakai array process, bukan string interpolasi (hindari injection risk)
            $process = new Process([
                'ffmpeg', '-y',
                '-i', $videoPath,
                '-vn',
                '-acodec', 'libmp3lame',
                '-q:a', '2',
                $audioPath
            ]);
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception("FFmpeg ekstraksi audio gagal: " . $process->getErrorOutput());
            }

            // Transkripsi AI
            $aiResult = $gemini->transcribe($audioPath);

            $transcription = Transcription::create([
                'video_id'  => $this->video->id,
                'full_text' => $aiResult['full_text'] ?? '',
                'json_data' => $aiResult
            ]);

            $this->video->update(['status' => 'completed']);

            // Lanjut ke highlight discovery
            DiscoverHighlightsJob::dispatch($this->video, $transcription);

            Log::info("=== TRANSCRIPTION SUCCESS & HIGHLIGHT DISPATCHED ===", ['video_id' => $this->video->id]);

            // NOTE: File audio TIDAK dihapus di sini karena masih dipakai
            // oleh ProcessVideoClipJob untuk referensi transkripsi.
            // Hapus manual setelah semua clip selesai jika perlu hemat storage.

        } catch (\Throwable $e) {
            Log::error("TRANSCRIPTION FAILED: " . $e->getMessage(), ['video_id' => $this->video->id]);
            $this->video->update(['status' => 'failed']);

            // Bersihkan file audio yang mungkin corrupt
            if (File::exists($audioPath)) {
                File::delete($audioPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessTranscription permanently failed for video ID {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => 'failed']);
    }
}