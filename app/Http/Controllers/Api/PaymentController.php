<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Snap;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Set konfigurasi Midtrans dari file config/services.php
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    /**
     * Tahap 1: Membuat Transaksi & Mendapatkan Snap Token
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'tier_plan' => 'required|in:starter,pro,business',
        ]);

        $user = auth()->user();

        // Tentukan harga (Sesuaikan dengan keinginanmu)
        $prices = [
            'starter' => 100000,
            'pro'     => 250000,
            'business' => 500000
        ];

        $amount = $prices[$request->tier_plan];
        $orderId = 'TC-' . Str::upper(Str::random(10));

        // Simpan data transaksi ke database dengan status pending
        $transaction = Transaction::create([
            'external_id' => $orderId,
            'user_id'     => $user->id,
            'tier_plan'   => $request->tier_plan,
            'amount'      => $amount,
            'status'      => 'pending'
        ]);

        // Buat parameter untuk dikirim ke Midtrans
        $params = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email'      => $user->email,
            ],
        ];

        try {
            // Minta Snap Token dari Midtrans
            $snapToken = Snap::getSnapToken($params);

            // Simpan token ke transaksi
            $transaction->update(['snap_token' => $snapToken]);

            return response()->json([
                'status'     => 'success',
                'snap_token' => $snapToken,
                'order_id'   => $orderId
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Tahap 2: Menerima Notifikasi dari Midtrans (Webhook)
     * Bagian ini yang otomatis mengubah Tier user saat pembayaran lunas
     */
    public function callback(Request $request)
    {
        $serverKey = config('services.midtrans.server_key');
        $hashed = hash("sha512", $request->order_id . $request->status_code . $request->gross_amount . $serverKey);

        if ($hashed !== $request->signature_key) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $transaction = Transaction::where('external_id', $request->order_id)->first();
        if (!$transaction) return response()->json(['message' => 'Transaction not found'], 404);

        $status = $request->transaction_status;

        if ($status == 'settlement' || $status == 'capture') {
            // PEMBAYARAN BERHASIL
            $transaction->update(['status' => 'settlement']);

            // Logika Update Tier & Kredit User
            $user = User::find($transaction->user_id);
            $user->tier = $transaction->tier_plan;

            // Contoh isi kredit instan sesuai tier
            $credits = ['starter' => 100, 'pro' => 300, 'business' => 9999];
            $user->remaining_credits = $credits[$transaction->tier_plan];
            $user->save();
        } elseif (in_array($status, ['cancel', 'expire', 'deny'])) {
            $transaction->update(['status' => 'failed']);
        }

        return response()->json(['status' => 'ok']);
    }

    public function getAllTransactions()
    {
        // Pastikan hanya admin yang bisa akses (bisa dicek di route middleware)
        $transactions = Transaction::with('user') // Ambil data user terkait
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ]);
    }
}
