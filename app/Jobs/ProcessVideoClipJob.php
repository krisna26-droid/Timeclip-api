<?php

namespace App\Jobs;

use App\Events\ClipStatusUpdated;
use App\Models\Clip;
use App\Models\ClipSubtitle;
use App\Models\SystemLog; // Import Model SystemLog
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

        ClipStatusUpdated::dispatch(
            $this->clip->id,
            $this->clip->video_id,
            $video->user_id,
            'rendering',
            'Klip sedang dirender...'
        );

        if (!File::exists($videoInputPath)) {
            $errorMsg = "Video input tidak ditemukan: {$videoInputPath}";
            Log::error($errorMsg, ['clip_id' => $this->clip->id]);

            // LOG KE SYSTEM LOG
            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'ERROR',
                'category' => 'FILE_MISSING',
                'user_id'  => $video->user_id,
                'message'  => "Render gagal: File sumber video tidak ditemukan.",
                'payload'  => ['path' => $videoInputPath, 'clip_id' => $this->clip->id]
            ]);

            $this->clip->update(['status' => 'failed']);
            ClipStatusUpdated::dispatch($this->clip->id, $this->clip->video_id, $video->user_id, 'failed', 'File video tidak ditemukan.');
            return;
        }

        $outputDir = storage_path('app/public/clips');
        if (!File::exists($outputDir)) File::makeDirectory($outputDir, 0755, true);

        $thumbDir = storage_path('app/public/thumbnails');
        if (!File::exists($thumbDir)) File::makeDirectory($thumbDir, 0755, true);

        $outputFileName = 'clip_' . $this->clip->id . '.mp4';
        $outputPath     = $outputDir . DIRECTORY_SEPARATOR . $outputFileName;
        $thumbFileName  = 'thumb_' . $this->clip->id . '.jpg';
        $thumbPath      = $thumbDir . DIRECTORY_SEPARATOR . $thumbFileName;

        $assPath = storage_path('app/private/captions/clip_' . $this->clip->id . '.ass');
        $assDir  = dirname($assPath);
        if (!File::exists($assDir)) File::makeDirectory($assDir, 0755, true);

        $clipStart = (float) $this->clip->start_time;
        $clipEnd   = (float) $this->clip->end_time;
        $duration  = max(0, $clipEnd - $clipStart);

        if ($duration <= 0) {
            Log::error("Durasi clip tidak valid", ['clip_id' => $this->clip->id]);
            $this->clip->update(['status' => 'failed']);
            ClipStatusUpdated::dispatch($this->clip->id, $this->clip->video_id, $video->user_id, 'failed', 'Durasi klip tidak valid.');
            return;
        }

        // STEP 1: Generate ASS subtitle
        try {
            $clipSubtitle = $this->clip->subtitle;

            if ($clipSubtitle) {
                $words    = $clipSubtitle->words ?? [];
                $fullText = $clipSubtitle->full_text ?? '';
                Log::info("Pakai subtitle per clip.", ['clip_id' => $this->clip->id]);

                $captionService->generateAss($words, $fullText, 0.0, $duration, $assPath);
            } else {
                $transcription      = $video->transcription;
                $allWords           = $transcription->json_data['words'] ?? [];
                $transcriptText     = $transcription->full_text ?? '';

                $filteredWords = array_values(array_filter($allWords, function ($w) use ($clipStart, $clipEnd) {
                    return ($w['start'] >= $clipStart && $w['start'] < $clipEnd);
                }));

                $normalizedWords = array_map(function ($w) use ($clipStart, $duration) {
                    return [
                        'word'  => $w['word'],
                        'start' => round($w['start'] - $clipStart, 3),
                        'end'   => round(min($w['end'] - $clipStart, $duration), 3),
                    ];
                }, $filteredWords);

                $filteredText = implode(' ', array_column($normalizedWords, 'word'));
                $captionService->generateAss($normalizedWords, $filteredText, 0.0, $duration, $assPath);
            }
        } catch (\Throwable $e) {
            Log::warning("Caption generation gagal: " . $e->getMessage());
            $assPath = null;
        }

        // STEP 2: FFmpeg render klip
        $ffmpegPath = $this->resolveFfmpeg();
        $cropFilter = "scale=-1:1080,crop=608:1080:(in_w-608)/2:0";

        if ($assPath && File::exists($assPath)) {
            $assEscaped = $this->escapeAssPath($assPath);
            $vfFilter   = "{$cropFilter},ass='{$assEscaped}'";
        } else {
            $vfFilter = $cropFilter;
        }

        $command = [
            $ffmpegPath,
            '-y',
            '-ss',
            (string) $clipStart,
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

        if ($assPath && File::exists($assPath)) {
            File::delete($assPath);
        }

        if (!$process->isSuccessful()) {
            // LOG ERROR RENDER KE DATABASE
            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'ERROR',
                'category' => 'RENDER_FAILED',
                'user_id'  => $video->user_id,
                'message'  => "FFmpeg gagal me-render klip ID: {$this->clip->id}",
                'payload'  => [
                    'command' => $process->getCommandLine(),
                    'error'   => $process->getErrorOutput(),
                ]
            ]);

            $this->clip->update(['status' => 'failed']);
            ClipStatusUpdated::dispatch($this->clip->id, $this->clip->video_id, $video->user_id, 'failed', 'Render klip gagal.');
            return;
        }

        // STEP 3: Generate thumbnail
        try {
            $frameAt = min(3, $duration / 2);
            $thumbProcess = new Process([
                $ffmpegPath,
                '-y',
                '-ss',
                (string) ($clipStart + $frameAt),
                '-i',
                $videoInputPath,
                '-vframes',
                '1',
                '-vf',
                $cropFilter,
                '-q:v',
                '2',
                $thumbPath
            ]);
            $thumbProcess->run();
            $thumbnailPath = $thumbProcess->isSuccessful() ? 'thumbnails/' . $thumbFileName : null;
        } catch (\Throwable $e) {
            $thumbnailPath = null;
        }

        // STEP 4: Update status klip
        $this->clip->update([
            'status'         => 'ready',
            'clip_path'      => 'clips/' . $outputFileName,
            'thumbnail_path' => $thumbnailPath,
        ]);

        // Kirim event sukses
        ClipStatusUpdated::dispatch(
            $this->clip->id,
            $this->clip->video_id,
            $video->user_id,
            'ready',
            'Klip siap ditonton!',
            [
                'clip_url'      => url('/api/clips/' . $this->clip->id . '/stream'),
                'thumbnail_url' => $thumbnailPath ? asset('storage/' . $thumbnailPath) : null,
            ]
        );
    }

    private function resolveFfmpeg(): string
    {
        if ($envPath = env('FFMPEG_PATH')) return $envPath;
        if (PHP_OS_FAMILY === 'Windows') {
            $default = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
            if (File::exists($default)) return $default;
        }
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
        SystemLog::create([
            'service'  => 'SYSTEM',
            'level'    => 'ERROR',
            'category' => 'JOB_FAILED',
            'user_id'  => $this->clip->video->user_id ?? null,
            'message'  => "Job ProcessVideoClipJob gagal permanen: " . $exception->getMessage(),
        ]);

        $this->clip->update(['status' => 'failed']);
    }
}
