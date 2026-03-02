<?php

namespace App\Jobs;

use App\Models\Video;
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

        $folderPath = storage_path('app/private/raw_videos');
        if (!File::exists($folderPath)) {
            File::makeDirectory($folderPath, 0755, true);
        }

        $outputPath = $folderPath . DIRECTORY_SEPARATOR . $this->video->id . '.mp4';

        // FIX: Support Windows dan Linux/Server
        $ytDlpPath = base_path(PHP_OS_FAMILY === 'Windows' ? 'yt-dlp.exe' : 'yt-dlp');

        $process = new Process([
            $ytDlpPath,
            '-f', 'bestvideo[height<=720]+bestaudio/best',
            '--merge-output-format', 'mp4',
            '-o', $outputPath,
            $this->video->source_url
        ]);

        $process->setTimeout(3600);

        try {
            $process->mustRun();

            if (File::exists($outputPath)) {
                $this->video->update([
                    'status'    => 'completed',
                    'file_path' => 'private/raw_videos/' . $this->video->id . '.mp4'
                ]);

                // FIX: Import sudah ditambahkan, dispatch ke transcription
                ProcessTranscription::dispatch($this->video);

            } else {
                throw new \Exception("File tidak ditemukan setelah download.");
            }

        } catch (\Exception $e) {
            Log::error("Download Failed ID {$this->video->id}: " . $e->getMessage());
            $this->video->update(['status' => 'failed']);

            if (File::exists($outputPath)) {
                File::delete($outputPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("DownloadVideoJob permanently failed for video ID {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => 'failed']);
    }
}