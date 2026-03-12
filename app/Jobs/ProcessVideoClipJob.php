<?php

namespace App\Jobs;

use App\Events\ClipStatusUpdated;
use App\Models\Clip;
use App\Models\ClipSubtitle;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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

    // CaptionService tidak lagi dibutuhkan, dihapus dari parameter
    public function handle()
    {
        Log::info("=== STARTING FFmpeg RENDER (RAW - NO SUBTITLE) ===", ['clip_id' => $this->clip->id]);

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

        $timestamp      = time();
        $outputFileName = 'clip_' . $this->clip->id . '_' . $timestamp . '.mp4';
        $outputPath     = $outputDir . DIRECTORY_SEPARATOR . $outputFileName;
        $thumbFileName  = 'thumb_' . $this->clip->id . '_' . $timestamp . '.jpg';
        $thumbPath      = $thumbDir . DIRECTORY_SEPARATOR . $thumbFileName;

        $clipStart = (float) $this->clip->start_time;
        $clipEnd   = (float) $this->clip->end_time;
        $duration  = max(0, $clipEnd - $clipStart);

        if ($duration <= 0) {
            Log::error("Durasi clip tidak valid", ['clip_id' => $this->clip->id]);
            $this->clip->update(['status' => 'failed']);
            ClipStatusUpdated::dispatch($this->clip->id, $this->clip->video_id, $video->user_id, 'failed', 'Durasi klip tidak valid.');
            return;
        }

        // STEP 1: FFmpeg render klip RAW (tanpa subtitle sama sekali)
        $ffmpegPath = $this->resolveFfmpeg();
        Log::info("Menggunakan ffmpeg: {$ffmpegPath}", ['clip_id' => $this->clip->id]);

        // Hanya crop filter, tidak ada ass subtitle filter
        $cropFilter = "scale=-1:1080,crop=608:1080:(in_w-608)/2:0";

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
            $cropFilter,
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

        if (!$process->isSuccessful()) {
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

        Log::info("Render RAW Success: {$outputPath}", ['clip_id' => $this->clip->id]);

        // STEP 2: Generate thumbnail
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

        // STEP 3: Upload ke Supabase
        try {
            Log::info("Uploading to Supabase...", ['clip_id' => $this->clip->id]);

            if (File::exists($outputPath)) {
                $uploadedVideo = Storage::disk('supabase')->put(
                    'clips/' . $outputFileName,
                    fopen($outputPath, 'r+'),
                    ['visibility' => 'public', 'ContentType' => 'video/mp4']
                );

                if ($uploadedVideo) {
                    sleep(1);
                    File::delete($outputPath);
                }
            }

            if ($thumbPath && File::exists($thumbPath)) {
                $uploadedThumb = Storage::disk('supabase')->put(
                    'thumbnails/' . $thumbFileName,
                    fopen($thumbPath, 'r+'),
                    ['visibility' => 'public', 'ContentType' => 'image/jpeg']
                );

                if ($uploadedThumb) {
                    sleep(1);
                    File::delete($thumbPath);
                }
            }

            Log::info("Upload ke Supabase berhasil, file lokal dibersihkan.", ['clip_id' => $this->clip->id]);
        } catch (\Throwable $e) {
            Log::error("Gagal Upload ke Supabase: " . $e->getMessage());
        }

        $this->clip->update([
            'status'         => 'ready',
            'clip_path'      => 'clips/' . $outputFileName,
            'thumbnail_path' => $thumbnailPath,
        ]);

        SystemLog::create([
            'service'  => 'FFMPEG',
            'level'    => 'INFO',
            'category' => 'RENDER',
            'user_id'  => $video->user_id,
            'message'  => "Render klip RAW berhasil (ready).",
            'payload'  => ['clip_id' => $this->clip->id, 'duration' => $duration]
        ]);

        // STEP 4: Seed subtitle (words + timing) dari transkripsi video induk
        // Data ini yang akan dipakai Frontend untuk overlay subtitle di editor
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

                Log::info("Subtitle data di-seed untuk klip (untuk editor FE).", ['clip_id' => $this->clip->id, 'words_count' => count($normalizedWords)]);
            }
        }

        $supabaseUrl    = config('filesystems.disks.supabase.url');
        $supabaseBucket = config('filesystems.disks.supabase.bucket');

        $clipUrl      = "{$supabaseUrl}/{$supabaseBucket}/clips/{$outputFileName}";
        $thumbnailUrl = $thumbnailPath ? "{$supabaseUrl}/{$supabaseBucket}/{$thumbnailPath}" : null;

        ClipStatusUpdated::dispatch(
            $this->clip->id,
            $this->clip->video_id,
            $video->user_id,
            'ready',
            'Klip siap ditonton!',
            [
                'clip_url'      => $clipUrl,
                'thumbnail_url' => $thumbnailUrl,
            ]
        );

        // Hapus video master jika semua klip sudah selesai dirender
        $remainingClips = Clip::where('video_id', $this->clip->video_id)
            ->whereIn('status', ['pending', 'rendering'])
            ->count();

        if ($remainingClips === 0) {
            if (File::exists($videoInputPath)) {
                sleep(1);
                File::delete($videoInputPath);
                Log::info("PROSES SELESAI: Video master telah dihapus.", ['video_id' => $video->id]);
            }
        }
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

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessVideoClipJob permanently failed for clip ID {$this->clip->id}: " . $exception->getMessage());

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
