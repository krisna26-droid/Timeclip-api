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

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    public function handle(GeminiService $gemini): void
    {
        Log::info("=== PROCESS TRANSCRIPTION START ===", ['video_id' => $this->video->id]);

        $this->video->update(['status' => 'processing']);
        $videoPath = storage_path('app/' . $this->video->file_path);
        $audioPath = storage_path('app/private/audio/' . $this->video->id . '.mp3');

        try {
            // Ekstraksi Audio menggunakan FFmpeg
            $command = 'ffmpeg -y -i "' . $videoPath . '" -vn -acodec libmp3lame "' . $audioPath . '"';
            $process = Process::fromShellCommandline($command);
            $process->run();

            if (!$process->isSuccessful()) throw new \Exception("FFmpeg gagal.");

            // 🔹 Tahap Transkripsi AI
            $aiResult = $gemini->transcribe($audioPath);

            $transcription = Transcription::create([
                'video_id'  => $this->video->id,
                'full_text' => $aiResult['full_text'] ?? '',
                'json_data' => $aiResult // Cast array otomatis di model
            ]);

            $this->video->update(['status' => 'completed']);

            // 🔹 Lanjut ke Tahap Kurasi Klip
            DiscoverHighlightsJob::dispatch($this->video, $transcription);

            Log::info("=== TRANSCRIPTION SUCCESS & HIGHLIGHT DISPATCHED ===");

        } catch (\Throwable $e) {
            Log::error("TRANSCRIPTION FAILED: " . $e->getMessage());
            $this->video->update(['status' => 'failed']);
        }
    }
}