<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    /**
     * List semua user dengan pagination dan search
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%");
        }

        $users = $query->latest()->paginate(10);

        return response()->json([
            'status' => 'success',
            'data' => $users
        ]);
    }

    /**
     * Update data user (Tier, Role, Name)
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'role' => 'in:admin,creator',
            'tier' => 'in:free,starter,pro,business',
            'remaining_credits' => 'integer|min:0',
        ]);

        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data user berhasil diperbarui.',
            'user' => $user
        ]);
    }

    /**
     * Fitur Manual Credit Adjustment (Penting untuk CS/Admin)
     */
    public function adjustCredits(Request $request, $id): JsonResponse
    {
        $request->validate([
            'amount' => 'required|integer', // bisa minus untuk mengurangi
            'reason' => 'required|string|max:255'
        ]);

        $user = User::findOrFail($id);
        $user->increment('remaining_credits', $request->amount);

        // Catat ke system log bahwa admin mengubah kredit
        \App\Models\SystemLog::create([
            'service' => 'SYSTEM',
            'level' => 'INFO',
            'category' => 'CREDIT_ADJUSTMENT',
            'message' => "Admin (" . auth()->user()->name . ") mengubah kredit {$user->name}: {$request->amount}",
            'payload' => ['reason' => $request->reason],
            'user_id' => $user->id
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Kredit {$user->name} berhasil disesuaikan.",
            'new_balance' => $user->remaining_credits
        ]);
    }

    /**
     * Hapus User
     */
    public function destroy($id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Tidak bisa menghapus diri sendiri.'], 400);
        }

        $user->delete();
        return response()->json(['status' => 'success', 'message' => 'User berhasil dihapus.']);
    }
}
