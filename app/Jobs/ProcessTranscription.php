<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\Transcription;
use App\Services\GeminiService; // Import Service yang baru kita buat
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
    public $timeout = 900; // Ditingkatkan menjadi 15 menit karena proses AI butuh waktu

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    /**
     * Laravel akan otomatis menyuntikkan GeminiService ke sini
     */
    public function handle(GeminiService $gemini): void
    {
        Log::info("Tahap 5: Memulai Ekstraksi & Transkripsi untuk Video ID: " . $this->video->id);

        $videoPath = storage_path('app/' . $this->video->file_path);
        $audioPath = str_replace('.mp4', '.mp3', $videoPath);

        // 1. Validasi File Video
        if (!File::exists($videoPath)) {
            Log::error("File video tidak ditemukan: " . $videoPath);
            $this->video->update(['status' => 'failed']);
            return;
        }

        // 2. Ekstraksi Audio menggunakan FFmpeg
        $process = new Process([
            'ffmpeg', '-i', $videoPath, '-q:a', '0', '-map', 'a', '-y', $audioPath
        ]);

        try {
            $process->mustRun();
            Log::info("Ekstraksi Audio Berhasil: " . $audioPath);

            // 3. Kirim ke Gemini AI untuk Transkripsi
            Log::info("Mengirim audio ke Gemini AI...");
            $aiResult = $gemini->transcribe($audioPath);

            // 4. Simpan Hasil ke Database
            Transcription::create([
                'video_id' => $this->video->id,
                'full_text' => $aiResult['full_text'],
                'json_data' => $aiResult['words'], // Menyimpan word-level timestamps
            ]);

            // 5. Update Status Video menjadi Completed
            $this->video->update(['status' => 'completed']);
            Log::info("Tahap 5 SELESAI untuk Video ID: " . $this->video->id);

        } catch (\Exception $e) {
            Log::error("Gagal di Tahap 5: " . $e->getMessage());
            $this->video->update(['status' => 'failed']);
        }
    }
}