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
        Log::info("=== STARTING FFmpeg RENDER ===", ['clip_id' => $this->clip->id]);

        $video          = $this->clip->video;
        $videoInputPath = storage_path('app/' . $video->file_path);

        if (!File::exists($videoInputPath)) {
            Log::error("Video input tidak ditemukan: {$videoInputPath}", ['clip_id' => $this->clip->id]);
            $this->clip->update(['status' => 'failed']);
            return;
        }

        // Pastikan folder output ada
        $outputDir = storage_path('app/public/clips');
        if (!File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        // Pastikan folder thumbnail ada
        $thumbDir = storage_path('app/public/thumbnails');
        if (!File::exists($thumbDir)) {
            File::makeDirectory($thumbDir, 0755, true);
        }

        $outputFileName = 'clip_' . $this->clip->id . '.mp4';
        $outputPath     = $outputDir . DIRECTORY_SEPARATOR . $outputFileName;
        $thumbFileName  = 'thumb_' . $this->clip->id . '.jpg';
        $thumbPath      = $thumbDir . DIRECTORY_SEPARATOR . $thumbFileName;

        // Pastikan folder captions ada
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

        // STEP 1: Generate ASS subtitle
        try {
            $transcription = $video->transcription;
            $words         = $transcription->json_data['words'] ?? [];
            $fullText      = $transcription->full_text ?? '';

            $captionService->generateAss(
                words: $words,
                fullText: $fullText,
                clipStart: (float) $this->clip->start_time,
                clipEnd: (float) $this->clip->end_time,
                outputPath: $assPath
            );
        } catch (\Throwable $e) {
            Log::warning("Caption generation gagal, render tanpa subtitle: " . $e->getMessage(), ['clip_id' => $this->clip->id]);
            $assPath = null;
        }

        // STEP 2: FFmpeg render klip
        $ffmpegPath = $this->resolveFfmpeg();
        Log::info("Menggunakan ffmpeg: {$ffmpegPath}", ['clip_id' => $this->clip->id]);

        $cropFilter = "scale=-1:1080,crop=608:1080:(in_w-608)/2:0";

        if ($assPath && File::exists($assPath)) {
            $assEscaped = $this->escapeAssPath($assPath);
            $vfFilter   = "{$cropFilter},ass='{$assEscaped}'";
            Log::info("Render dengan subtitle.", ['clip_id' => $this->clip->id]);
        } else {
            $vfFilter = $cropFilter;
            Log::info("Render tanpa subtitle.", ['clip_id' => $this->clip->id]);
        }

        $command = [
            $ffmpegPath,
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

        if (!$process->isSuccessful()) {
            $this->clip->update(['status' => 'failed']);
            Log::error("FFmpeg Error clip_id {$this->clip->id}: " . $process->getErrorOutput());
            return;
        }

        Log::info("Render Success: {$outputPath}", ['clip_id' => $this->clip->id]);

        // STEP 3: Generate thumbnail dari frame tengah klip
        $thumbnailPath = null;
        try {
            // Ambil frame di detik ke-3 klip (atau tengah kalau durasi pendek)
            $frameAt = min(3, $duration / 2);

            $thumbProcess = new Process([
                $ffmpegPath,
                '-y',
                '-ss',
                (string) ($this->clip->start_time + $frameAt),
                '-i',
                $videoInputPath,
                '-vframes',
                '1',                          // Ambil 1 frame saja
                '-vf',
                $cropFilter,                       // Sama crop portrait seperti klip
                '-q:v',
                '2',                              // Kualitas JPEG (1=terbaik, 31=terburuk)
                $thumbPath
            ]);

            $thumbProcess->setTimeout(60);
            $thumbProcess->run();

            if ($thumbProcess->isSuccessful() && File::exists($thumbPath)) {
                $thumbnailPath = 'thumbnails/' . $thumbFileName;
                Log::info("Thumbnail berhasil dibuat: {$thumbPath}", ['clip_id' => $this->clip->id]);
            } else {
                Log::warning("Thumbnail gagal dibuat, klip tetap ready.", ['clip_id' => $this->clip->id]);
            }
        } catch (\Throwable $e) {
            Log::warning("Thumbnail exception: " . $e->getMessage(), ['clip_id' => $this->clip->id]);
        }

        // STEP 4: Update status klip
        $this->clip->update([
            'status'         => 'ready',
            'clip_path'      => 'clips/' . $outputFileName,
            'thumbnail_path' => $thumbnailPath,
        ]);
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

        return 'ffmpeg';
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
