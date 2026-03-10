<?php

namespace App\Jobs;

use App\Events\VideoStatusUpdated;
use App\Models\Video;
use App\Models\SystemLog; // Import model SystemLog
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DownloadVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $video;
    public $timeout = 3600;
    public $tries = 2;

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    public function handle()
    {
        $this->video->update(['status' => 'processing']);
        VideoStatusUpdated::dispatch(
            $this->video->id,
            $this->video->user_id,
            'downloading',
            'Video sedang didownload...'
        );

        $folderPath = storage_path('app/private/raw_videos');
        if (!File::exists($folderPath)) {
            File::makeDirectory($folderPath, 0755, true);
        }

        $outputPath = $folderPath . DIRECTORY_SEPARATOR . $this->video->id . '.mp4';

        $ytDlpPath  = env('YTDLP_PATH', base_path('yt-dlp.exe'));
        $ffmpegPath = env('FFMPEG_PATH', 'C:\\ffmpeg\\bin\\ffmpeg.exe');
        $ffmpegDir  = dirname($ffmpegPath);

        Log::info("Menggunakan yt-dlp: {$ytDlpPath}", ['video_id' => $this->video->id]);

        $process = new Process([
            $ytDlpPath,
            '-f',
            'bestvideo[height<=720]+bestaudio/best[height<=720]/best',
            '--merge-output-format',
            'mp4',
            '--ffmpeg-location',
            $ffmpegDir,
            '--no-playlist',
            '-o',
            $outputPath,
            $this->video->source_url
        ]);

        $process->setTimeout(3600);

        try {
            $process->mustRun(function ($type, $buffer) {
                Log::debug("yt-dlp: " . trim($buffer));
            });

            if (File::exists($outputPath)) {
                $this->video->update([
                    'status'    => 'completed',
                    'file_path' => 'private/raw_videos/' . $this->video->id . '.mp4'
                ]);

                // LOG SUKSES DOWNLOAD (Opsional untuk tracking trafik)
                SystemLog::create([
                    'service'  => 'YT-DLP',
                    'level'    => 'INFO',
                    'category' => 'DOWNLOAD_SUCCESS',
                    'user_id'  => $this->video->user_id,
                    'message'  => "Video berhasil didownload: ID {$this->video->id}",
                    'payload'  => ['url' => $this->video->source_url]
                ]);

                VideoStatusUpdated::dispatch(
                    $this->video->id,
                    $this->video->user_id,
                    'processing',
                    'Download selesai, memulai transkripsi...'
                );

                ProcessTranscription::dispatch($this->video);
            } else {
                throw new \Exception("File tidak ditemukan setelah download selesai.");
            }
        } catch (\Exception $e) {
            Log::error("Download Failed ID {$this->video->id}: " . $e->getMessage());

            // LOG ERROR KE DATABASE UNTUK ADMIN
            SystemLog::create([
                'service'  => 'YT-DLP',
                'level'    => 'ERROR',
                'category' => 'DOWNLOAD_FAILED',
                'user_id'  => $this->video->user_id,
                'message'  => "Gagal mendownload video: " . substr($e->getMessage(), 0, 200),
                'payload'  => [
                    'url'   => $this->video->source_url,
                    'error' => $e->getMessage(),
                    'cmd'   => $process->getCommandLine()
                ]
            ]);

            $this->video->update(['status' => 'failed']);

            VideoStatusUpdated::dispatch(
                $this->video->id,
                $this->video->user_id,
                'failed',
                'Download video gagal.'
            );

            if (File::exists($outputPath)) {
                File::delete($outputPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        SystemLog::create([
            'service'  => 'SYSTEM',
            'level'    => 'ERROR',
            'category' => 'JOB_FAILED',
            'user_id'  => $this->video->user_id ?? null,
            'message'  => "Job DownloadVideoJob gagal permanen: " . $exception->getMessage(),
        ]);

        $this->video->update(['status' => 'failed']);

        VideoStatusUpdated::dispatch(
            $this->video->id,
            $this->video->user_id,
            'failed',
            'Download video gagal permanen.'
        );
    }
}
