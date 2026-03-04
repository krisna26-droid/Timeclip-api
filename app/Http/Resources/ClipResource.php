<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ClipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'video_id' => $this->video_id,
            'title' => $this->title,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration' => $this->duration_formatted, // Mengambil dari model yang Anda buat
            'viral_score' => $this->viral_score, //
            'status' => $this->status, //
            // Mengubah path database menjadi URL publik yang bisa diakses browser
            'clip_url' => $this->clip_path ? asset('storage/' . $this->clip_path) : null, //
            'parent_video_title' => $this->video->title ?? 'Unknown',
            'created_at' => $this->created_at->diffForHumans(),
        ];
    }
}