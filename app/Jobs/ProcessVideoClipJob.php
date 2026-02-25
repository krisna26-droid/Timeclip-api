<?php

namespace App\Jobs;

use App\Models\Clip;
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
    public $timeout = 1200; // 20 Menit

    public function __construct(Clip $clip)
    {
        $this->clip = $clip;
    }

    public function handle()
    {
        Log::info("=== STARTING FFmpeg RENDER ===", ['clip_id' => $this->clip->id]);

        $video = $this->clip->video;
        $inputPath = storage_path('app/' . $video->file_path);
        $outputDir = storage_path('app/public/clips');

        // Pastikan direktori output ada
        if (!File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $outputFileName = 'clip_' . $this->clip->id . '.mp4';
        $outputPath = $outputDir . '/' . $outputFileName;

        // Filter: Resize ke tinggi 1080 dulu, baru crop tengah 608px (aspek rasio 9:16)
        // Formula: crop=width:height:x:y
        $ffmpegFilter = "scale=-1:1080,crop=608:1080:(in_w-608)/2:0";

        $duration = $this->clip->end_time - $this->clip->start_time;

        $command = [
            'ffmpeg', '-y',
            '-ss', (string) $this->clip->start_time,
            '-t', (string) $duration,
            '-i', $inputPath,
            '-vf', $ffmpegFilter,
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '23',
            '-c:a', 'aac',
            '-b:a', '128k',
            $outputPath
        ];

        $process = new Process($command);
        $process->setTimeout(1200);
        $process->run();

        if ($process->isSuccessful()) {
            $this->clip->update([
                'status' => 'ready',
                'clip_path' => 'clips/' . $outputFileName
            ]);
            Log::info("Render Success: " . $outputPath);
        } else {
            $this->clip->update(['status' => 'failed']);
            Log::error("FFmpeg Error: " . $process->getErrorOutput());
        }
    }
}