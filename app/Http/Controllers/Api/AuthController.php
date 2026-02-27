<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider; // Tambahkan import ini
use Exception;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    /**
     * Registrasi User Manual
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'creator',
            'tier' => 'free',
            'remaining_credits' => 10,
            'last_reset_date' => now()->toDateString(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => $user
        ], 201);
    }

    /**
     * Login User Manual
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email atau Password salah!'
            ], 401);
        }

        $user = User::where('email', $request->input('email'))->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login Berhasil!',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    /**
     * Redirect ke GitHub OAuth
     */
    public function githubRedirect()
    {
        /** @var GithubProvider $driver */
        $driver = Socialite::driver('github');
        
        return $driver->stateless()->redirect();
    }

    /**
     * Handle Callback dari GitHub
     */
    public function githubCallback(): JsonResponse
    {
        try {
            /** @var GithubProvider $driver */
            $driver = Socialite::driver('github');
            
            $githubUser = $driver->stateless()->user();

            $user = User::updateOrCreate([
                'email' => $githubUser->getEmail(),
            ], [
                'name' => $githubUser->getName() ?? $githubUser->getNickname(),
                'provider_id' => $githubUser->getId(),
                'provider_name' => 'github',
                'password' => null, 
                'role' => 'creator',
                'tier' => 'free',
                'remaining_credits' => 10,
                'last_reset_date' => now()->toDateString(),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login via GitHub Berhasil!',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal login via GitHub: ' . $e->getMessage()
            ], 500);
        }
    }
}