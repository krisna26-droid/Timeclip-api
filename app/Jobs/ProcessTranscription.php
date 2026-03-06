<?php

namespace App\Jobs;

use App\Events\VideoStatusUpdated;
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
        VideoStatusUpdated::dispatch(
            $this->video->id,
            $this->video->user_id,
            'processing',
            'Sedang melakukan transkripsi audio...'
        );

        $videoPath = storage_path('app/' . $this->video->file_path);
        $audioDir  = storage_path('app/private/audio');
        $audioPath = $audioDir . DIRECTORY_SEPARATOR . $this->video->id . '.mp3';

        if (!File::exists($audioDir)) {
            File::makeDirectory($audioDir, 0755, true);
        }

        if (!File::exists($videoPath)) {
            Log::error("Video file tidak ditemukan: {$videoPath}", ['video_id' => $this->video->id]);
            $this->video->update(['status' => 'failed']);

            VideoStatusUpdated::dispatch(
                $this->video->id,
                $this->video->user_id,
                'failed',
                'File video tidak ditemukan.'
            );
            return;
        }

        try {
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

            $fullText = $aiResult['full_text'] ?? '';
            $words    = $aiResult['words'] ?? [];

            Log::info("Menyimpan transcription.", [
                'video_id'     => $this->video->id,
                'word_count'   => count($words),
                'text_preview' => substr($fullText, 0, 100),
            ]);

            $transcription = Transcription::create([
                'video_id'  => $this->video->id,
                'full_text' => $fullText,
                'json_data' => [
                    'full_text' => $fullText,
                    'words'     => $words,
                ],
            ]);

            if (File::exists($audioPath)) {
                File::delete($audioPath);
            }

            VideoStatusUpdated::dispatch(
                $this->video->id,
                $this->video->user_id,
                'processing',
                'Transkripsi selesai, AI sedang menganalisis highlight...'
            );

            DiscoverHighlightsJob::dispatch($this->video, $transcription);

            Log::info("=== TRANSCRIPTION SUCCESS & HIGHLIGHT DISPATCHED ===", ['video_id' => $this->video->id]);
        } catch (\Throwable $e) {
            Log::error("TRANSCRIPTION FAILED ID {$this->video->id}: " . $e->getMessage());
            $this->video->update(['status' => 'failed']);

            VideoStatusUpdated::dispatch(
                $this->video->id,
                $this->video->user_id,
                'failed',
                'Transkripsi gagal: ' . $e->getMessage()
            );

            if (File::exists($audioPath)) {
                File::delete($audioPath);
            }
        }
    }

    private function resolveFfmpeg(): string
    {
        return config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessTranscription permanently failed for video ID {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => 'failed']);

        VideoStatusUpdated::dispatch(
            $this->video->id,
            $this->video->user_id,
            'failed',
            'Transkripsi gagal permanen.'
        );
    }
}
