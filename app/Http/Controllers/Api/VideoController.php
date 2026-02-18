<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Video;
use Illuminate\Support\Facades\Auth;

class VideoController extends Controller
{
    // GET /api/videos - Library Video User [cite: 241]
    public function index()
    {
        $videos = Auth::user()->videos()->latest()->get();
        return response()->json([
            'status' => 'success',
            'data' => $videos
        ]);
    }

    // POST /api/videos/process - Input Video Baru [cite: 241]
    public function store(Request $request)
    {
        // Validasi sesuai batasan proyek [cite: 243, 244, 245]
        $request->validate([
            'title' => 'required|string|max:255',
            'url' => 'required|url', // YouTube atau TikTok [cite: 245]
            'duration' => 'required|integer|max:1800', // Maksimal 30 menit [cite: 244]
        ]);

        // Cek saldo kredit user sebelum proses [cite: 247]
        if (Auth::user()->remaining_credits < 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Saldo kredit tidak mencukupi.'
            ], 403);
        }

        $video = Video::create([
            'user_id' => Auth::id(),
            'title' => $request->title,
            'source_url' => $request->url,
            'duration' => $request->duration,
            'status' => 'pending', // [cite: 207]
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Video berhasil dikirim ke antrean.',
            'data' => $video
        ], 201);
    }
}