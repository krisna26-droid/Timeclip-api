<?php

namespace App\Jobs;

use App\Events\ClipStatusUpdated;
use App\Models\Clip;
use App\Models\ClipSubtitle;
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

        $duration = max(0, $this->clip->end_time - $this->clip->start_time);

        if ($duration <= 0) {
            Log::error("Durasi clip tidak valid", ['clip_id' => $this->clip->id]);
            $this->clip->update(['status' => 'failed']);
            ClipStatusUpdated::dispatch($this->clip->id, $this->clip->video_id, $video->user_id, 'failed', 'Durasi klip tidak valid.');
            return;
        }

        // STEP 1: Generate ASS subtitle
        // Prioritas: clip_subtitles (sudah diedit user) → fallback ke transcription video
        try {
            $clipSubtitle = $this->clip->subtitle;

            if ($clipSubtitle) {
                // Pakai subtitle per clip yang sudah diedit user
                $words    = $clipSubtitle->words ?? [];
                $fullText = $clipSubtitle->full_text ?? '';
                Log::info("Pakai subtitle per clip.", ['clip_id' => $this->clip->id]);
            } else {
                // Fallback ke transcription video induk
                $transcription = $video->transcription;
                $words         = $transcription->json_data['words'] ?? [];
                $fullText      = $transcription->full_text ?? '';
                if (empty($fullText)) {
                    $fullText = $transcription->json_data['full_text'] ?? '';
                }
                Log::info("Pakai transcription video induk.", ['clip_id' => $this->clip->id]);
            }

            Log::info("Caption: words=" . count($words) . ", text_len=" . strlen($fullText), ['clip_id' => $this->clip->id]);

            $captionService->generateAss(
                words: $words,
                fullText: $fullText,
                clipStart: (float) $this->clip->start_time,
                clipEnd: (float) $this->clip->end_time,
                outputPath: $assPath
            );
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

        if ($assPath && File::exists($assPath)) {
            File::delete($assPath);
        }

        if (!$process->isSuccessful()) {
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
                (string) ($this->clip->start_time + $frameAt),
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

        // STEP 5: Seed subtitle dari transcription jika belum ada sama sekali
        if (!$this->clip->subtitle) {
            $transcription = $video->transcription;
            if ($transcription) {
                // 1. Ambil data asli (pastikan json_data sudah di-cast jadi array di Model Transcription)
                $allWords = $transcription->json_data['words'] ?? [];

                $clipStart = (float) $this->clip->start_time;
                $clipEnd   = (float) $this->clip->end_time;

                // 2. FILTER: Hanya ambil kata yang berada di dalam durasi klip
                $filteredWords = array_values(array_filter($allWords, function ($w) use ($clipStart, $clipEnd) {
                    return ($w['start'] >= $clipStart && $w['end'] <= $clipEnd);
                }));

                // 3. NORMALISASI: (Opsional) Geser waktu agar mulai dari 0.0 buat memudahkan FE
                $normalizedWords = array_map(function ($w) use ($clipStart) {
                    return [
                        'word'  => $w['word'],
                        'start' => round($w['start'] - $clipStart, 3),
                        'end'   => round($w['end'] - $clipStart, 3),
                    ];
                }, $filteredWords);

                // 4. SUSUN ULANG FULL TEXT: Gabungkan kata-kata yang sudah difilter saja
                $filteredText = implode(' ', array_column($normalizedWords, 'word'));

                ClipSubtitle::create([
                    'clip_id'   => $this->clip->id,
                    'full_text' => $filteredText,
                    'words'     => $normalizedWords, // Masuk sebagai array, Laravel otomatis JSON-kan
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
