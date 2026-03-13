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
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct()
    {
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

        $prices = [
            'starter' => 100000,
            'pro'     => 250000,
            'business' => 500000
        ];

        $amount = $prices[$request->tier_plan];
        $orderId = 'TC-' . Str::upper(Str::random(10));

        // Simpan data transaksi ke database
        $transaction = Transaction::create([
            'external_id' => $orderId,
            'user_id'     => $user->id,
            'tier_plan'   => $request->tier_plan,
            'amount'      => $amount,
            'status'      => 'pending'
        ]);

        // URL Ngrok kamu (Sesuaikan jika berubah)
        $ngrokUrl = "https://bradly-spumescent-keisha.ngrok-free.dev";

        $params = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email'      => $user->email,
            ],
            // Override notification URL untuk testing di localhost/ngrok
            'notification_url' => $ngrokUrl . '/api/payment/callback',
            'callbacks' => [
                'finish' => config('app.frontend_url') . '/dashboard',
            ]
        ];

        try {
            $snapToken = Snap::getSnapToken($params);
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
     * Tahap 2: Webhook Callback
     */
    public function callback(Request $request)
    {
        $serverKey = config('services.midtrans.server_key');

        // Midtrans kadang mengirimkan gross_amount dengan .00, kita bersihkan agar signature match
        $grossAmount = number_format($request->gross_amount, 0, '.', '');
        $signatureSource = $request->order_id . $request->status_code . $request->gross_amount . $serverKey;
        $hashed = hash("sha512", $signatureSource);

        if ($hashed !== $request->signature_key) {
            Log::error("Midtrans Signature Invalid", ['order_id' => $request->order_id]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $transaction = Transaction::where('external_id', $request->order_id)->first();
        if (!$transaction) return response()->json(['message' => 'Transaction not found'], 404);

        // Idempotency: Jika sudah lunas, jangan proses lagi
        if ($transaction->status === 'settlement') {
            return response()->json(['status' => 'ok']);
        }

        $status = $request->transaction_status;

        if ($status == 'settlement' || $status == 'capture') {
            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'settlement']);

                $user = User::find($transaction->user_id);
                $user->tier = $transaction->tier_plan;

                $credits = ['starter' => 100, 'pro' => 300, 'business' => 9999];
                $user->remaining_credits = $credits[$transaction->tier_plan];
                $user->save();
            });
            Log::info("Payment Successful: " . $request->order_id);
        } elseif (in_array($status, ['cancel', 'expire', 'deny'])) {
            $transaction->update(['status' => 'failed']);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Tahap 3: Check Status (Polling untuk Front-end)
     */
    public function checkStatus($orderId)
    {
        $transaction = Transaction::where('external_id', $orderId)->first();

        if (!$transaction) {
            return response()->json(['status' => 'error', 'message' => 'Transaction not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'transaction_status' => $transaction->status,
        ]);
    }

    public function getAllTransactions()
    {
        $transactions = Transaction::with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ]);
    }
}
