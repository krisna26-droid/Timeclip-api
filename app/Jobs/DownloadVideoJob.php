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
        // Set status awal
        $this->video->update(['status' => 'processing']);

        // 1. Pastikan folder tujuan ada (Solusi FE Poin 2)
        $folderPath = storage_path('app/private/raw_videos');
        if (!File::exists($folderPath)) {
            File::makeDirectory($folderPath, 0755, true);
        }

        // 2. Gunakan output template yt-dlp tanpa memaksa ekstensi di nama file (Solusi FE Poin 1)
        // Kita gunakan %(ext)s agar yt-dlp bebas menentukan ekstensi terbaiknya
        $outputTemplate = $folderPath . DIRECTORY_SEPARATOR . $this->video->id . '.%(ext)s';

        // 3. Tentukan Path yt-dlp secara absolut (Solusi FE Poin 3)
        // Jika file ada di root project, gunakan base_path. Jika di folder sistem, arahkan langsung.
        $ytDlpPath = base_path(PHP_OS_FAMILY === 'Windows' ? 'yt-dlp.exe' : 'yt-dlp');

        // 4. Jalankan proses download
        $process = new Process([
            $ytDlpPath,
            '-f',
            'bestvideo[height<=720]+bestaudio/best', // Ambil kualitas terbaik max 720p
            '--merge-output-format',
            'mp4', // Minta yt-dlp menggabungkan ke mp4 jika memungkinkan
            '-o',
            $outputTemplate,
            $this->video->source_url
        ]);

        $process->setTimeout(3600);

        try {
            $process->mustRun();

            // 5. Cari file yang benar-benar tercipta menggunakan glob (Solusi FE Poin 1)
            // Ini akan mencari file seperti: 10.mp4, 10.mkv, atau 10.webm
            $downloadedFiles = File::glob($folderPath . DIRECTORY_SEPARATOR . $this->video->id . '.*');

            if (!empty($downloadedFiles)) {
                // Ambil file pertama yang ditemukan
                $actualFilePath = $downloadedFiles[0];
                $actualFileName = basename($actualFilePath);

                // Simpan path relatif yang benar ke database (bukan hardcoded .mp4)
                $this->video->update([
                    'status'    => 'completed',
                    'file_path' => 'private/raw_videos/' . $actualFileName
                ]);

                Log::info("Download Success ID {$this->video->id}: " . $actualFileName);

                // Lanjut ke tahap berikutnya
                ProcessTranscription::dispatch($this->video);
            } else {
                throw new \Exception("File tidak ditemukan di folder tujuan setelah proses download selesai.");
            }
        } catch (\Exception $e) {
            Log::error("Download Failed ID {$this->video->id}: " . $e->getMessage());
            $this->video->update(['status' => 'failed']);

            // Cleanup jika ada file sisa yang corrupt
            $residualFiles = File::glob($folderPath . DIRECTORY_SEPARATOR . $this->video->id . '.*');
            foreach ($residualFiles as $file) {
                File::delete($file);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("DownloadVideoJob permanently failed for video ID {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => 'failed']);
    }
}
