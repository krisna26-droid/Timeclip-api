<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public function transcribe(string $audioPath): array
    {
        Log::info("=== GEMINI TRANSCRIPTION START ===");

        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model');

        if (!$apiKey) {
            throw new \Exception("Gemini API key belum dikonfigurasi.");
        }

        if (!file_exists($audioPath)) {
            throw new \Exception("File audio tidak ditemukan.");
        }

        $mimeType = mime_content_type($audioPath);
        $audioContent = base64_encode(file_get_contents($audioPath));

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout(600)
            ->withHeaders([
                'Content-Type' => 'application/json'
            ])
            ->post($endpoint, [
                "contents" => [
                    [
                        "role" => "user",
                        "parts" => [
                            [
                                "text" => "Transkripsikan audio ini ke teks. Berikan hanya hasil transkripsinya tanpa tambahan apapun."
                            ],
                            [
                                "inline_data" => [
                                    "mime_type" => $mimeType,
                                    "data" => $audioContent
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        if (!$response->successful()) {
            Log::error("Gemini Error:", [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);
            throw new \Exception("Gemini API error.");
        }

        $data = $response->json();

        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception("Response Gemini tidak valid.");
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'];

        Log::info("Transkripsi berhasil.");

        return [
            'full_text' => $text,
            'words' => [] // WAJIB array kosong, bukan null
        ];
    }
}