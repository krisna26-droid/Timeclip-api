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
        $audioPath = $audioDir . DIRECTORY_SEPARATOR . $this->video->id . '.mp3';

        // Pastikan folder audio ada
        if (!File::exists($audioDir)) {
            File::makeDirectory($audioDir, 0755, true);
        }

        if (!File::exists($videoPath)) {
            Log::error("Video file tidak ditemukan: {$videoPath}", ['video_id' => $this->video->id]);
            $this->video->update(['status' => 'failed']);
            return;
        }

        try {
            // FIX: Gunakan helper terpusat untuk cari ffmpeg (sama logikanya dengan DownloadVideoJob)
            $ffmpegPath = $this->resolveFfmpeg();
            Log::info("Menggunakan ffmpeg: {$ffmpegPath}", ['video_id' => $this->video->id]);

            $process = new Process([
                $ffmpegPath,
                '-y',
                '-i',
                $videoPath,
                '-vn',
                '-acodec',
                'libmp3lame',
                '-q:a',
                '2',
                $audioPath
            ]);

            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception("FFmpeg ekstraksi audio gagal: " . $process->getErrorOutput());
            }

            if (!File::exists($audioPath)) {
                throw new \Exception("File audio tidak tercipta setelah proses FFmpeg.");
            }

            $aiResult = $gemini->transcribe($audioPath);

            $transcription = Transcription::create([
                'video_id'  => $this->video->id,
                'full_text' => $aiResult['full_text'] ?? '',
                'json_data' => $aiResult
            ]);

            DiscoverHighlightsJob::dispatch($this->video, $transcription);

            Log::info("=== TRANSCRIPTION SUCCESS & HIGHLIGHT DISPATCHED ===", ['video_id' => $this->video->id]);
        } catch (\Throwable $e) {
            Log::error("TRANSCRIPTION FAILED ID {$this->video->id}: " . $e->getMessage());
            $this->video->update(['status' => 'failed']);

            if (File::exists($audioPath)) {
                File::delete($audioPath);
            }
        }
    }

    private function resolveFfmpeg(): string
    {
        if ($envPath = env('FFMPEG_PATH')) {
            return $envPath;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $default = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
            if (File::exists($default)) return $default;
        } else {
            foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $path) {
                if (File::exists($path)) return $path;
            }
        }

        $projectPath = base_path(PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg');
        if (File::exists($projectPath)) return $projectPath;

        return 'ffmpeg'; // Fallback terakhir
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessTranscription permanently failed for video ID {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => 'failed']);
    }
}
