<?php

namespace App\Jobs;

use App\Models\Clip;
use App\Services\CaptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ProcessVideoClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $clip;
    public $timeout = 1200;
    public $tries   = 2;

    public function __construct(Clip $clip)
    {
        $this->clip = $clip;
    }

    public function handle(CaptionService $captionService)
    {
        Log::info("=== STARTING FFmpeg RENDER (FIXED PATH & EXT) ===", ['clip_id' => $this->clip->id]);

        $video = $this->clip->video;

        // FIX 1: Gunakan file_path dinamis dari database (Solusi FE Poin 1)
        // Jangan hardcode .mp4 karena hasil download bisa jadi .mkv atau .webm
        $videoInputPath = storage_path('app/' . $video->file_path);

        // Validasi file input ada
        if (!File::exists($videoInputPath)) {
            Log::error("Video input tidak ditemukan di private storage: " . $videoInputPath, ['clip_id' => $this->clip->id]);
            $this->clip->update(['status' => 'failed']);
            return;
        }

        // FIX 2: Pastikan folder output PUBLIC/CLIPS tersedia (Solusi FE Poin 2)
        $outputDir = storage_path('app/public/clips');
        if (!File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $outputFileName = 'clip_' . $this->clip->id . '.mp4';
        $outputPath     = $outputDir . DIRECTORY_SEPARATOR . $outputFileName;

        // FIX 2: Pastikan folder CAPTIONS tersedia di private storage
        $assPath = storage_path('app/private/captions/clip_' . $this->clip->id . '.ass');
        $assDir  = dirname($assPath);
        if (!File::exists($assDir)) {
            File::makeDirectory($assDir, 0755, true);
        }

        $duration = max(0, $this->clip->end_time - $this->clip->start_time);

        if ($duration <= 0) {
            Log::error("Durasi clip tidak valid", ['clip_id' => $this->clip->id]);
            $this->clip->update(['status' => 'failed']);
            return;
        }

        // =============================================
        // STEP 1: Generate file ASS dari CaptionService
        // =============================================
        try {
            $transcription = $video->transcription;

            $words    = $transcription->json_data['words'] ?? [];
            $fullText = $transcription->full_text ?? '';

            $captionService->generateAss(
                words: $words,
                fullText: $fullText,
                clipStart: (float) $this->clip->start_time,
                clipEnd: (float) $this->clip->end_time,
                outputPath: $assPath
            );
        } catch (\Throwable $e) {
            Log::warning("Caption generation gagal, render tanpa subtitle: " . $e->getMessage(), [
                'clip_id' => $this->clip->id
            ]);
            $assPath = null;
        }

        // =============================================
        // STEP 2: FFmpeg render dengan Full Path Binary
        // =============================================

        // FIX 3: Path FFmpeg Absolut (Solusi FE Poin 3)
        $ffmpegPath = PHP_OS_FAMILY === 'Windows'
            ? 'C:\ffmpeg\bin\ffmpeg.exe'
            : 'ffmpeg';

        // Crop portrait 9:16
        $cropFilter = "scale=-1:1080,crop=608:1080:(in_w-608)/2:0";

        if ($assPath && File::exists($assPath)) {
            $assEscaped = $this->escapeAssPath($assPath);
            $vfFilter = "{$cropFilter},ass='{$assEscaped}'";
            Log::info("Render dengan karaoke subtitle.", ['ass' => $assPath]);
        } else {
            $vfFilter = $cropFilter;
            Log::info("Render tanpa subtitle.");
        }

        $command = [
            $ffmpegPath, // Pakai Full Path
            '-y',
            '-ss',
            (string) $this->clip->start_time,
            '-t',
            (string) $duration,
            '-i',
            $videoInputPath,
            '-vf',
            $vfFilter,
            '-c:v',
            'libx264',
            '-preset',
            'fast',
            '-crf',
            '23',
            '-c:a',
            'aac',
            '-b:a',
            '128k',
            '-map',
            '0:v:0',
            '-map',
            '0:a:0',
            '-movflags',
            '+faststart',
            $outputPath
        ];

        $process = new Process($command);
        $process->setTimeout(1200);
        $process->run();

        // Cleanup temp ASS
        if ($assPath && File::exists($assPath)) {
            File::delete($assPath);
        }

        // UPDATE STATUS
        if ($process->isSuccessful()) {
            $this->clip->update([
                'status'    => 'ready',
                'clip_path' => 'clips/' . $outputFileName,
            ]);
            Log::info("Render Success: " . $outputPath, ['clip_id' => $this->clip->id]);
        } else {
            $this->clip->update(['status' => 'failed']);
            Log::error("FFmpeg Error clip_id {$this->clip->id}: " . $process->getErrorOutput());
        }
    }

    private function escapeAssPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/^([A-Za-z]):/', '$1\\:', $path);
        $path = str_replace(['[', ']', ',', ';'], ['\\[', '\\]', '\\,', '\\;'], $path);
        return $path;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessVideoClipJob permanently failed for clip ID {$this->clip->id}: " . $exception->getMessage());
        $this->clip->update(['status' => 'failed']);
    }
}
