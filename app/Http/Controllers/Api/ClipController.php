<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Video;
use App\Models\Clip;
use Illuminate\Support\Facades\Auth;

class ClipController extends Controller
{
    /**
     * Menampilkan semua klip berdasarkan video_id tertentu.
     * Endpoint: GET /api/videos/{video_id}/clips
     */
    public function index($videoId)
    {
        // 1. Pastikan video tersebut ada dan milik user yang sedang login
        $video = Video::where('id', $videoId)
                      ->where('user_id', Auth::id())
                      ->first();

        if (!$video) {
            return response()->json([
                'status' => 'error',
                'message' => 'Video tidak ditemukan atau Anda tidak memiliki akses.'
            ], 404);
        }

        // 2. Ambil semua klip yang berelasi dengan video tersebut
        // Diurutkan berdasarkan viral_score tertinggi sesuai spek
        $clips = $video->clips()->orderBy('viral_score', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'video_title' => $video->title,
            'data' => $clips
        ]);
    }

    /**
     * Menampilkan detail satu klip spesifik (misal untuk halaman Editor).
     * Endpoint: GET /api/clips/{id}
     */
    public function show($id)
    {
        // Ambil klip beserta data video induknya untuk verifikasi user_id
        $clip = Clip::with('video')->find($id);

        if (!$clip) {
            return response()->json([
                'status' => 'error',
                'message' => 'Klip tidak ditemukan.'
            ], 404);
        }

        // Keamanan: Cek apakah video induk klip ini milik user yang login
        if ($clip->video->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Anda tidak memiliki akses ke klip ini.'
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $clip
        ]);
    }
}