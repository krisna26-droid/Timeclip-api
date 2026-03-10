<?php

namespace App\Jobs;

use App\Events\ClipStatusUpdated;
use App\Models\Clip;
use App\Models\ClipSubtitle;
use App\Models\SystemLog; // Tambahan
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
            Log::error("Video input tidak ditemukan: {$videoInputPath}", ['clip_id' => $this->clip->id]);

            // LOG UNTUK ADMIN: File master hilang
            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'ERROR',
                'category' => 'RENDER',
                'user_id'  => $video->user_id,
                'message'  => "Render gagal: Video master tidak ditemukan.",
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
                // Words dari DB sudah normalized (start dari 0) → kirim clipStart=0
                $words    = $clipSubtitle->words ?? [];
                $fullText = $clipSubtitle->full_text ?? '';
                Log::info("Pakai subtitle per clip.", ['clip_id' => $this->clip->id]);

                $captionService->generateAss(
                    words: $words,
                    fullText: $fullText,
                    clipStart: 0.0,
                    clipEnd: $duration,
                    outputPath: $assPath
                );
            } else {
                $transcription      = $video->transcription;
                $allWords           = $transcription->json_data['words'] ?? [];
                $transcriptText     = $transcription->full_text ?? '';
                $totalVideoDuration = max(1, (float) $video->duration);

                $normalizedWords = [];
                $filteredText    = '';

                if (!empty($allWords)) {
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
                    Log::info("Pakai word timestamps dari transcription.", ['clip_id' => $this->clip->id, 'words' => count($normalizedWords)]);
                }

                if (empty($normalizedWords) && !empty($transcriptText)) {
                    Log::info("Words kosong, estimasi dari full_text via proporsi.", ['clip_id' => $this->clip->id]);

                    $allWordsFromText = preg_split('/\s+/', trim($transcriptText));
                    $totalWords       = count($allWordsFromText);
                    $wordsPerSecond   = $totalWords / $totalVideoDuration;

                    $startWordIdx = (int) floor($clipStart * $wordsPerSecond);
                    $endWordIdx   = (int) ceil($clipEnd * $wordsPerSecond);
                    $endWordIdx   = min($endWordIdx, $totalWords - 1);

                    $clipWords    = array_slice($allWordsFromText, $startWordIdx, max(1, $endWordIdx - $startWordIdx + 1));
                    $filteredText = implode(' ', $clipWords);

                    $wordCount = count($clipWords);
                    $perWord   = $duration / $wordCount;
                    $current   = 0.0;

                    foreach ($clipWords as $word) {
                        $normalizedWords[] = [
                            'word'  => trim($word),
                            'start' => round($current, 3),
                            'end'   => round($current + $perWord, 3),
                        ];
                        $current += $perWord;
                    }

                    Log::info("Estimasi selesai.", ['clip_id' => $this->clip->id, 'estimated_words' => count($normalizedWords)]);
                }

                Log::info("Pakai transcription video induk (filtered+normalized).", ['clip_id' => $this->clip->id]);

                $captionService->generateAss(
                    words: $normalizedWords,
                    fullText: $filteredText,
                    clipStart: 0.0,
                    clipEnd: $duration,
                    outputPath: $assPath
                );
            }

            Log::info("Caption generated.", ['clip_id' => $this->clip->id]);
        } catch (\Throwable $e) {
            Log::warning("Caption generation gagal: " . $e->getMessage(), ['clip_id' => $this->clip->id]);
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
            // LOG UNTUK ADMIN: Kegagalan teknis FFmpeg
            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'ERROR',
                'category' => 'RENDER',
                'user_id'  => $video->user_id,
                'message'  => "FFmpeg Render Gagal (ID: {$this->clip->id})",
                'payload'  => ['error' => $process->getErrorOutput()]
            ]);

            $this->clip->update(['status' => 'failed']);
            Log::error("FFmpeg Error clip_id {$this->clip->id}: " . $process->getErrorOutput());
            ClipStatusUpdated::dispatch($this->clip->id, $this->clip->video_id, $video->user_id, 'failed', 'Render klip gagal.');
            return;
        }

        Log::info("Render Success: {$outputPath}", ['clip_id' => $this->clip->id]);

        // STEP 3: Generate thumbnail
        $thumbnailPath = null;
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

            $thumbProcess->setTimeout(60);
            $thumbProcess->run();

            if ($thumbProcess->isSuccessful() && File::exists($thumbPath)) {
                $thumbnailPath = 'thumbnails/' . $thumbFileName;
                Log::info("Thumbnail berhasil dibuat.", ['clip_id' => $this->clip->id]);
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

        // LOG UNTUK ADMIN: Berhasil render
        SystemLog::create([
            'service'  => 'FFMPEG',
            'level'    => 'INFO',
            'category' => 'RENDER',
            'user_id'  => $video->user_id,
            'message'  => "Render klip berhasil (ready).",
            'payload'  => ['clip_id' => $this->clip->id, 'duration' => $duration]
        ]);

        // STEP 5: Seed subtitle dari transcription jika belum ada
        if (!$this->clip->subtitle) {
            $transcription = $video->transcription;
            if ($transcription) {
                $allWords = $transcription->json_data['words'] ?? [];

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

                ClipSubtitle::create([
                    'clip_id'   => $this->clip->id,
                    'full_text' => $filteredText,
                    'words'     => $normalizedWords,
                ]);

                Log::info("Subtitle di-seed dan difilter untuk klip.", ['clip_id' => $this->clip->id]);
            }
        }

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

        // LOG UNTUK ADMIN: Kegagalan permanen
        SystemLog::create([
            'service'  => 'FFMPEG',
            'level'    => 'ERROR',
            'category' => 'SYSTEM',
            'user_id'  => $this->clip->video->user_id,
            'message'  => "Job Render gagal permanen (Clip ID: {$this->clip->id})",
            'payload'  => ['exception' => $exception->getMessage()]
        ]);

        $this->clip->update(['status' => 'failed']);

        ClipStatusUpdated::dispatch(
            $this->clip->id,
            $this->clip->video_id,
            $this->clip->video->user_id,
            'failed',
            'Render klip gagal permanen.'
        );
    }
}
