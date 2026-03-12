<?php

namespace App\Jobs;

use App\Models\Clip;
use App\Models\SystemLog;
use App\Services\CaptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ExportClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $clip;
    public $timeout = 600;
    public $tries   = 2;

    public function __construct(Clip $clip)
    {
        $this->clip = $clip;
    }

    public function handle(CaptionService $captionService): void
    {
        Log::info("=== STARTING EXPORT (BURN SUBTITLE) ===", ['clip_id' => $this->clip->id]);

        $clip      = $this->clip->fresh(['subtitle', 'video']); // Eager load relations

        if (!$clip) {
            Log::error("Clip tidak ditemukan saat job dijalankan.", ['clip_id' => $this->clip->id]);
            return;
        }

        $subtitle  = $clip->subtitle;
        $video     = $clip->video;

        if (!$subtitle || empty($subtitle->words)) {
            Log::warning("Tidak ada subtitle untuk di-export.", ['clip_id' => $clip->id]);
            $clip->update(['export_path' => null]);
            return;
        }

        $ffmpegPath = $this->resolveFfmpeg();
        $timestamp  = time();
        $tempDir    = storage_path('app/temp_exports'); // Gunakan folder spesifik

        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $rawVideoPath   = $tempDir . DIRECTORY_SEPARATOR . 'raw_' . $clip->id . '_' . $timestamp . '.mp4';
        $exportFileName = 'clip_export_' . $clip->id . '_' . $timestamp . '.mp4';
        $exportPath     = $tempDir . DIRECTORY_SEPARATOR . $exportFileName;
        $assPath        = $tempDir . DIRECTORY_SEPARATOR . 'sub_' . $clip->id . '_' . $timestamp . '.ass';

        try {
            // 1. Download Video
            $this->downloadRawVideo($clip, $rawVideoPath);

            // 2. Generate ASS
            $duration = (float) $clip->end_time - (float) $clip->start_time;
            $captionService->generate($subtitle->words, $assPath, $duration);

            if (!File::exists($assPath)) {
                throw new \Exception("File ASS gagal dibuat: {$assPath}");
            }

            Log::info("File ASS dibuat, mulai render FFmpeg.", ['clip_id' => $clip->id]);

            // 3. Render FFmpeg
            $assPathEscaped = $this->escapeAssPath($assPath);
            // Crop filter disesuaikan agar aspect ratio terjaga (9:16 portrait)
            $videoFilter    = "scale=-1:1080,crop=608:1080:(in_w-608)/2:0,ass='{$assPathEscaped}'";

            $command = [
                $ffmpegPath,
                '-y',
                '-i',
                $rawVideoPath,
                '-vf',
                $videoFilter,
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
                '-movflags',
                '+faststart',
                $exportPath,
            ];

            $process = new Process($command);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception("FFmpeg export gagal: " . $process->getErrorOutput());
            }

            // 4. Upload ke Supabase menggunakan Stream (Hemat RAM)
            $supabasePath = 'clips/export/' . $exportFileName;
            $stream = fopen($exportPath, 'r');
            Storage::disk('supabase')->put($supabasePath, $stream, [
                'visibility' => 'public',
                'ContentType' => 'video/mp4'
            ]);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $clip->update(['export_path' => $supabasePath]);

            Log::info("Export berhasil diupload ke Supabase.", [
                'clip_id'     => $clip->id,
                'export_path' => $supabasePath,
            ]);

            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'INFO',
                'category' => 'EXPORT',
                'user_id'  => $video->user_id,
                'message'  => "Export klip berhasil.",
                'payload'  => json_encode(['clip_id' => $clip->id, 'path' => $supabasePath])
            ]);
        } catch (\Throwable $e) {
            Log::error("Error saat export: " . $e->getMessage());
            throw $e; // Throw agar antrean job tahu jika gagal
        } finally {
            // Bersihkan file temp
            $this->cleanupFiles([$rawVideoPath, $exportPath, $assPath]);
        }
    }

    private function downloadRawVideo(Clip $clip, string $destPath): void
    {
        $supabaseUrl    = config('filesystems.disks.supabase.url');
        $supabaseBucket = config('filesystems.disks.supabase.bucket');
        $rawUrl         = "{$supabaseUrl}/{$supabaseBucket}/" . ltrim($clip->clip_path, '/');

        Log::info("Download RAW video.", ['url' => $rawUrl]);

        // Gunakan sinkronisasi ke file langsung untuk menghemat RAM pada file besar
        $resource = fopen($destPath, 'w');
        $response = Http::timeout(300)->sink($resource)->get($rawUrl);

        if (is_resource($resource)) {
            fclose($resource);
        }

        if (!$response->successful()) {
            if (File::exists($destPath)) File::delete($destPath);
            throw new \Exception("Gagal download RAW video. Status: " . $response->status());
        }

        if (!File::exists($destPath) || filesize($destPath) < 100) {
            throw new \Exception("File RAW hasil download tidak valid atau kosong.");
        }
    }

    private function cleanupFiles(array $files): void
    {
        foreach ($files as $file) {
            if ($file && File::exists($file)) {
                File::delete($file);
            }
        }
    }

    private function escapeAssPath(string $path): string
    {
        // FFmpeg filter 'ass' membutuhkan escaping khusus untuk path
        $path = str_replace('\\', '/', $path); // Ubah ke forward slash untuk semua OS
        $path = str_replace(':', '\:', $path);
        $path = str_replace(["'", '[', ']'], ["\\'", '\[', '\]'], $path);
        return $processPath = $path;
    }

    private function resolveFfmpeg(): string
    {
        if ($envPath = env('FFMPEG_PATH')) return $envPath;

        if (PHP_OS_FAMILY === 'Windows') {
            $paths = ['C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\bin\\ffmpeg.exe'];
        } else {
            $paths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'];
        }

        foreach ($paths as $path) {
            if (File::exists($path)) return $path;
        }

        return 'ffmpeg';
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ExportClipJob gagal permanen ID {$this->clip->id}: " . $exception->getMessage());

        SystemLog::create([
            'service'  => 'FFMPEG',
            'level'    => 'ERROR',
            'category' => 'EXPORT',
            'user_id'  => $this->clip->video->user_id ?? null,
            'message'  => "Export klip gagal permanen (Clip ID: {$this->clip->id})",
            'payload'  => json_encode(['exception' => $exception->getMessage()])
        ]);
    }
}
