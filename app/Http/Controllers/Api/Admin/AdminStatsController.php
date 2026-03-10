<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Models\Video;
use App\Models\User;
use App\Models\Clip;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminStatsController extends Controller
{
    public function index(): JsonResponse
    {
        // 1. Ringkasan Keseluruhan
        $summary = [
            'total_users' => User::count(),
            'total_videos' => Video::count(),
            'total_clips' => Clip::count(),
            'total_system_errors' => SystemLog::where('level', 'ERROR')->count(),
        ];

        // 2. Statistik Trafik Gemini (7 Hari Terakhir)
        $geminiUsage = SystemLog::where('service', 'GEMINI')
            ->where('category', 'USAGE')
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->groupBy('date')
            ->get();

        // 3. Status Kesehatan FFmpeg
        $ffmpegHealth = [
            'success' => SystemLog::where('service', 'FFMPEG')->where('level', 'INFO')->count(),
            'failed' => SystemLog::where('service', 'FFMPEG')->where('level', 'ERROR')->count(),
        ];

        // 4. Antrean Aktif (Real-time monitoring)
        $queueStatus = [
            'pending' => Video::where('status', 'pending')->count(),
            'processing' => Video::where('status', 'processing')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => $summary,
                'gemini_chart' => $geminiUsage,
                'ffmpeg_health' => $ffmpegHealth,
                'queue_status' => $queueStatus
            ]
        ]);
    }

    /**
     * Mengambil log sistem terbaru untuk live feed dashboard
     */
    public function latestLogs(): JsonResponse
    {
        $logs = SystemLog::with('user:id,name')
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $logs
        ]);
    }
}
