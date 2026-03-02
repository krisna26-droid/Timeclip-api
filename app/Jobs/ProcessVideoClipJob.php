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
        Log::info("=== STARTING FFmpeg RENDER (WITH KARAOKE CAPTION) ===", ['clip_id' => $this->clip->id]);

        $video = $this->clip->video;

        $videoInputPath = storage_path('app/private/raw_videos/' . $video->id . '.mp4');

        // Validasi file input ada
        if (!File::exists($videoInputPath)) {
            Log::error("Video input tidak ditemukan: " . $videoInputPath, ['clip_id' => $this->clip->id]);
            $this->clip->update(['status' => 'failed']);
            return;
        }

        // Setup output
        $outputDir = storage_path('app/public/clips');
        if (!File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $outputFileName = 'clip_' . $this->clip->id . '.mp4';
        $outputPath     = $outputDir . '/' . $outputFileName;

        // Setup temp ASS subtitle file
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
            $transcription = $video->transcription; // relasi: Video hasOne Transcription

            $words    = $transcription->json_data['words'] ?? [];
            $fullText = $transcription->full_text ?? '';

            $captionService->generateAss(
                words:      $words,
                fullText:   $fullText,
                clipStart:  (float) $this->clip->start_time,
                clipEnd:    (float) $this->clip->end_time,
                outputPath: $assPath
            );

        } catch (\Throwable $e) {
            // Jika caption gagal, tetap render video tapi tanpa subtitle
            Log::warning("Caption generation gagal, render tanpa subtitle: " . $e->getMessage(), [
                'clip_id' => $this->clip->id
            ]);
            $assPath = null;
        }

        // =============================================
        // STEP 2: FFmpeg render dengan atau tanpa subtitle
        // =============================================

        // Crop portrait 9:16
        $cropFilter = "scale=-1:1080,crop=608:1080:(in_w-608)/2:0";

        if ($assPath && File::exists($assPath)) {
            // Escape path untuk FFmpeg subtitles filter (Windows & Linux safe)
            $assEscaped = $this->escapeAssPath($assPath);

            // Filter: crop dulu, lalu burn subtitle karaoke
            $vfFilter = "{$cropFilter},ass='{$assEscaped}'";

            Log::info("Render dengan karaoke subtitle.", ['ass' => $assPath]);
        } else {
            $vfFilter = $cropFilter;
            Log::info("Render tanpa subtitle (caption tidak tersedia).");
        }

        $command = [
            'ffmpeg', '-y',
            '-ss', (string) $this->clip->start_time,
            '-t',  (string) $duration,
            '-i',  $videoInputPath,
            '-vf', $vfFilter,
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '23',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-map', '0:v:0',
            '-map', '0:a:0',
            '-movflags', '+faststart',
            $outputPath
        ];

        $process = new Process($command);
        $process->setTimeout(1200);
        $process->run();

        // =============================================
        // STEP 3: Cleanup temp ASS file
        // =============================================
        if ($assPath && File::exists($assPath)) {
            File::delete($assPath);
        }

        // =============================================
        // STEP 4: Update status clip
        // =============================================
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

    /**
     * Escape path ASS untuk FFmpeg ass filter.
     * Windows: D:\path\file.ass → D\:/path/file.ass
     */
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