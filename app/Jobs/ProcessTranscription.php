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
    public $timeout = 900; // 15 menit

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    public function handle(GeminiService $gemini): void
    {
        Log::info("=== PROCESS TRANSCRIPTION START ===", [
            'video_id' => $this->video->id
        ]);

        $this->video->update(['status' => 'processing']);

        $videoPath = storage_path('app/' . $this->video->file_path);
        $audioDirectory = storage_path('app/private/audio');

        if (!File::exists($audioDirectory)) {
            File::makeDirectory($audioDirectory, 0755, true);
        }

        $audioPath = $audioDirectory . '/' . $this->video->id . '.mp3';

        try {

            if (!File::exists($videoPath)) {
                throw new \Exception("File video tidak ditemukan: " . $videoPath);
            }

            Log::info("Video ditemukan", ['path' => $videoPath]);
            Log::info("Output audio path", ['path' => $audioPath]);

            // COMMAND WINDOWS SAFE
            $command = 'ffmpeg -y -i "' . $videoPath . '" -vn -acodec libmp3lame "' . $audioPath . '"';

            Log::info("Menjalankan FFmpeg", ['command' => $command]);

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(600);
            $process->run();

            Log::info("FFmpeg stdout:", [
                'output' => $process->getOutput()
            ]);

            Log::info("FFmpeg stderr:", [
                'error' => $process->getErrorOutput()
            ]);

            if (!$process->isSuccessful()) {
                throw new \Exception("FFmpeg gagal dijalankan.");
            }

            if (!File::exists($audioPath)) {
                throw new \Exception("MP3 tidak berhasil dibuat.");
            }

            Log::info("Audio berhasil diekstrak", [
                'path' => $audioPath
            ]);

            // 🔹 TRANSKRIPSI
            $aiResult = $gemini->transcribe($audioPath);

            Transcription::create([
                'video_id'  => $this->video->id,
                'full_text' => $aiResult['full_text'] ?? '',
                'json_data' => json_encode($aiResult)
            ]);

            $this->video->update([
                'status' => 'completed'
            ]);

            Log::info("=== PROCESS TRANSCRIPTION SUCCESS ===", [
                'video_id' => $this->video->id
            ]);
        } catch (\Throwable $e) {

            Log::error("PROCESS TRANSCRIPTION FAILED", [
                'video_id' => $this->video->id,
                'message'  => $e->getMessage()
            ]);

            $this->video->update(['status' => 'failed']);
        }
    }
}
