<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MockController extends Controller
{
    // Simulasi Login dengan berbagai skenario kredit
    public function login(Request $request)
    {
        $email = $request->email;

        if ($email === 'habis@timeclip.test') {
            return response()->json([
                'status' => 'success',
                'access_token' => 'mock_token_empty',
                'user' => [
                    'name' => 'User Limit',
                    'email' => $email,
                    'role' => 'creator',
                    'tier' => 'free',
                    'remaining_credits' => 0 // [cite: 36, 195]
                ]
            ]);
        }

        return response()->json([
            'status' => 'success',
            'access_token' => 'mock_token_default',
            'user' => [
                'name' => 'Krisna Mock',
                'email' => $email,
                'role' => 'creator',
                'tier' => 'free',
                'remaining_credits' => 10 // [cite: 195, 246]
            ]
        ]);
    }
    public function register(Request $request)
    {
        // Simulasi input dari Frontend
        $name = $request->name ?? 'New User Mock';
        $email = $request->email ?? 'test@timeclip.test';

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully (Mock Mode)',
            'data' => [
                'id' => rand(100, 999), // ID acak untuk simulasi
                'name' => $name,
                'email' => $email,
                'role' => 'creator', // Default role sesuai dokumen [cite: 192]
                'tier' => 'free', // Default tier [cite: 193]
                'remaining_credits' => 10, // Kredit awal sesuai dokumen [cite: 195]
                'last_reset_date' => now()->toDateString(), //[cite: 194]
            ]
        ], 201); // Status 201 Created
    }   

    // Simulasi daftar video dengan status berbeda untuk mengetes Progress Tracker
    public function indexVideos()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                ['id' => 1, 'title' => 'Podcast Tech', 'status' => 'completed', 'duration' => 1200], // [cite: 167]
                ['id' => 2, 'title' => 'Daily Vlog', 'status' => 'processing', 'duration' => 600], // [cite: 32, 167]
                ['id' => 3, 'title' => 'Short Movie', 'status' => 'failed', 'duration' => 300] // [cite: 39, 167]
            ]
        ]);
    }

    // Simulasi hasil klip AI dengan Viral Score
    public function getClips($id)
    {
        return response()->json([
            'status' => 'success',
            'video_id' => $id,
            'clips' => [
                [
                    'id' => 101,
                    'title' => 'Highlight AI',
                    'viral_score' => 95, // [cite: 54, 169, 225]
                    'clip_path' => 'https://www.w3schools.com/html/mov_bbb.mp4', // [cite: 226]
                    'status' => 'ready' // [cite: 227]
                ]
            ]
        ]);
    }

    // Simulasi Ask AI Agent
    public function askAi(Request $request, $id)
    {
        return response()->json([
            'status' => 'success',
            'answer' => 'Saya menemukan bagian menarik di menit 02:30 sesuai permintaan Anda.' // [cite: 58, 59]
        ]);
    }
}