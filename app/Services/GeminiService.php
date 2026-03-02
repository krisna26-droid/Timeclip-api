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

        if (!$apiKey) throw new \Exception("Gemini API key belum dikonfigurasi.");
        if (!file_exists($audioPath)) throw new \Exception("File audio tidak ditemukan.");

        $mimeType     = mime_content_type($audioPath);
        $audioContent = base64_encode(file_get_contents($audioPath));

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        // Prompt baru: minta JSON dengan word-level timestamps
        $prompt = <<<PROMPT
Transkripsikan audio ini ke dalam teks Bahasa Indonesia.

Kembalikan HANYA JSON valid tanpa markdown, tanpa komentar, tanpa backtick.
Format JSON yang diharapkan:
{
  "full_text": "kalimat lengkap hasil transkripsi",
  "words": [
    {"word": "kata", "start": 0.0, "end": 0.5},
    {"word": "selanjutnya", "start": 0.6, "end": 1.2}
  ]
}

Aturan penting:
- "start" dan "end" adalah waktu dalam detik (float)
- Estimasi waktu seakurat mungkin berdasarkan ritme bicara
- Jangan sertakan emoji, tanda baca berlebihan, atau simbol aneh
- Pastikan JSON bisa di-parse langsung
PROMPT;

        $response = Http::timeout(600)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($endpoint, [
                "contents" => [
                    [
                        "role" => "user",
                        "parts" => [
                            ["text" => $prompt],
                            [
                                "inline_data" => [
                                    "mime_type" => $mimeType,
                                    "data"      => $audioContent
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

        if (!$response->successful()) {
            Log::error("Gemini Error:", ['status' => $response->status(), 'body' => $response->body()]);
            throw new \Exception("Gemini API error.");
        }

        $data = $response->json();

        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception("Response Gemini tidak valid.");
        }

        $rawText = $data['candidates'][0]['content']['parts'][0]['text'];

        // Bersihkan kalau ada markdown code block
        $cleanJson = preg_replace('/```json|```/', '', $rawText);
        $cleanJson = trim($cleanJson);

        $parsed = json_decode($cleanJson, true);

        // Validasi: kalau JSON valid dan ada words dengan timestamps
        if (
            json_last_error() === JSON_ERROR_NONE &&
            isset($parsed['full_text'], $parsed['words']) &&
            count($parsed['words']) > 0 &&
            isset($parsed['words'][0]['start'])
        ) {
            Log::info("Transkripsi berhasil dengan word-level timestamps.", [
                'word_count' => count($parsed['words'])
            ]);

            return [
                'full_text' => $parsed['full_text'],
                'words'     => $parsed['words'], // [{word, start, end}, ...]
            ];
        }

        // Fallback: kalau JSON gagal atau words kosong, simpan sebagai full_text saja
        // CaptionService akan handle generate timestamps otomatis dari full_text
        $fullText = $parsed['full_text'] ?? $rawText;

        Log::warning("Gemini tidak mengembalikan word timestamps. Fallback ke full_text only.");

        return [
            'full_text' => $fullText,
            'words'     => [], // CaptionService akan generate estimasi timestamps
        ];
    }
}