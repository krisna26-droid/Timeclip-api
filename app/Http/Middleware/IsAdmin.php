<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Cek apakah user sudah terautentikasi (lewat Sanctum)
        // 2. Cek apakah role user adalah 'admin'
        if ($request->user() && $request->user()->role === 'admin') {
            return $next($request);
        }

        // Jika bukan admin, tendang dengan error 403 Forbidden
        return response()->json([
            'status' => 'error',
            'message' => 'Akses ditolak. Area ini hanya untuk Admin.'
        ], 403);
    }
}