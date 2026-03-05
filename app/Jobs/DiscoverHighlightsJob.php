<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\Transcription;
use App\Models\Clip;
use App\Services\AIHighlightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverHighlightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $video;
    public $transcription;
    public $timeout = 300;
    public $tries = 2;

    public function __construct(Video $video, Transcription $transcription)
    {
        $this->video         = $video;
        $this->transcription = $transcription;
    }

    private function timeToSeconds($time): float
    {
        if (is_numeric($time)) {
            return (float) $time;
        }

        $parts = explode(':', (string) $time);
        if (count($parts) === 2) {
            return (float) ($parts[0] * 60) + (float) $parts[1];
        }

        if (count($parts) === 3) {
            return (float) ($parts[0] * 3600) + ($parts[1] * 60) + (float) $parts[2];
        }

        return (float) $time;
    }

    public function handle(AIHighlightService $highlightService): void
    {
        Log::info("Starting Highlight Discovery", ['video_id' => $this->video->id]);

        $highlights = $highlightService->getHighlights($this->transcription->full_text);

        if (empty($highlights)) {
            Log::warning("AI tidak menemukan highlight untuk video ID: " . $this->video->id);
            // Tetap set completed agar FE tidak stuck loading selamanya
            $this->video->update(['status' => 'completed']);
            return;
        }

        foreach ($highlights as $item) {
            $startTime = $this->timeToSeconds($item['start_time'] ?? 0);
            $endTime   = $this->timeToSeconds($item['end_time'] ?? 0);

            if ($endTime <= $startTime) {
                Log::warning("Skipping invalid clip: start={$startTime} end={$endTime}");
                continue;
            }

            $clip = Clip::create([
                'video_id'    => $this->video->id,
                'title'       => $item['title'] ?? 'Untitled Clip',
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'viral_score' => $item['viral_score'] ?? 0,
                'status'      => 'rendering'
            ]);

            ProcessVideoClipJob::dispatch($clip);
        }

        // Update status video ke completed agar FE tidak stuck di 90%
        $this->video->update(['status' => 'completed']);

        Log::info("Berhasil membuat " . count($highlights) . " rencana klip.", ['video_id' => $this->video->id]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("DiscoverHighlightsJob permanently failed for video ID {$this->video->id}: " . $exception->getMessage());
        // Set failed agar FE tidak stuck loading juga
        $this->video->update(['status' => 'failed']);
    }
}
