<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\Transcription;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ProcessTranscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $video;
    public $timeout = 900;
    public $tries = 2;

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    public function handle(GeminiService $gemini): void
    {
        Log::info("=== PROCESS TRANSCRIPTION START ===", ['video_id' => $this->video->id]);

        $this->video->update(['status' => 'processing']);

        // 1. Ambil path asli dari database (hasil globbing di Job sebelumnya)
        $videoPath = storage_path('app/' . $this->video->file_path);
        $audioDir  = storage_path('app/private/audio');
        $audioPath = $audioDir . DIRECTORY_SEPARATOR . $this->video->id . '.mp3';

        // 2. FIX: Pastikan folder audio ada dengan permission yang benar (Solusi FE Poin 2)
        if (!File::exists($audioDir)) {
            File::makeDirectory($audioDir, 0755, true);
        }

        // 3. Validasi file input ada sebelum diproses
        if (!File::exists($videoPath)) {
            Log::error("Video file tidak ditemukan: " . $videoPath);
            $this->video->update(['status' => 'failed']);
            return;
        }

        try {
            // 4. FIX: Gunakan Full Path FFmpeg agar tidak "Ghaib" di Queue (Solusi FE Poin 3)
            // Sesuaikan path ini dengan lokasi instalasi di Windows Anda
            $ffmpegPath = PHP_OS_FAMILY === 'Windows'
                ? 'C:\ffmpeg\bin\ffmpeg.exe'
                : 'ffmpeg';

            $process = new Process([
                $ffmpegPath,
                '-y',
                '-i',
                $videoPath,
                '-vn',
                '-acodec',
                'libmp3lame',
                '-q:a',
                '2',
                $audioPath
            ]);

            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Exception("FFmpeg ekstraksi audio gagal: " . $process->getErrorOutput());
            }

            // 5. Verifikasi file audio hasil ekstraksi benar-benar tercipta
            if (!File::exists($audioPath)) {
                throw new \Exception("File audio tidak tercipta setelah proses FFmpeg.");
            }

            // 6. Jalankan Transkripsi AI
            $aiResult = $gemini->transcribe($audioPath);

            // Simpan hasil ke database
            $transcription = Transcription::create([
                'video_id'  => $this->video->id,
                'full_text' => $aiResult['full_text'] ?? '',
                'json_data' => $aiResult
            ]);

            // Jangan update video jadi 'completed' di sini karena proses kliping belum mulai.
            // Biarkan status tetap 'processing' agar Dashboard FE menunjukkan progres berjalan.

            // 7. Lanjut ke highlight discovery
            DiscoverHighlightsJob::dispatch($this->video, $transcription);

            Log::info("=== TRANSCRIPTION SUCCESS & HIGHLIGHT DISPATCHED ===", ['video_id' => $this->video->id]);
        } catch (\Throwable $e) {
            Log::error("TRANSCRIPTION FAILED ID {$this->video->id}: " . $e->getMessage());
            $this->video->update(['status' => 'failed']);

            // Bersihkan file audio yang mungkin corrupt
            if (File::exists($audioPath)) {
                File::delete($audioPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessTranscription permanently failed for video ID {$this->video->id}: " . $exception->getMessage());
        $this->video->update(['status' => 'failed']);
    }
}
