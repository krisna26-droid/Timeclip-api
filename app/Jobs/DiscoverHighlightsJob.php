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

    public function __construct(Video $video, Transcription $transcription)
    {
        $this->video = $video;
        $this->transcription = $transcription;
    }

    public function handle(AIHighlightService $highlightService): void
    {
        Log::info("Starting Highlight Discovery", ['video_id' => $this->video->id]);

        $highlights = $highlightService->getHighlights($this->transcription->full_text);

        foreach ($highlights as $item) {
            Clip::create([
                'video_id'    => $this->video->id,
                'title'       => $item['title'] ?? 'Untitled Clip',
                'start_time'  => $item['start_time'] ?? 0,
                'end_time'    => $item['end_time'] ?? 0,
                'viral_score' => $item['viral_score'] ?? 0,
                'status'      => 'rendering' // Siap untuk tahap render
            ]);
        }

        Log::info("Created " . count($highlights) . " clip plans in database.");
    }
}