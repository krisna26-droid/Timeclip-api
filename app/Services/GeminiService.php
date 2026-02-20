<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
    }

    public function transcribe($audioPath): array
    {
        if (!file_exists($audioPath)) {
            throw new \Exception("File audio tidak ditemukan.");
        }

        return retry(3, function () use ($audioPath) {
            // STEP 1: Upload Audio (Gunakan v1beta hanya untuk upload)
            $fileData = $this->uploadToGemini($audioPath);
            $fileUri = $fileData['file']['uri'];
            $fileId = $fileData['file']['name'];

            // STEP 2: Tunggu File ACTIVE
            $this->waitForFile($fileId);

            // STEP 3: Minta Transkrip
            // Kita ganti ID model ke 'gemini-1.5-flash-latest' dan pakai endpoint v1beta1 
            // atau v1beta yang lebih spesifik.
            $modelId = "gemini-1.5-flash-latest";
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $modelId . ":generateContent?key=" . trim($this->apiKey);

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(300)
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => "Transcribe this audio accurately. Return ONLY a JSON object with: 'full_text' (string) and 'words' (array of objects with 'word', 'start_time', 'end_time' in seconds)."],
                                ['file_data' => ['mime_type' => 'audio/mpeg', 'file_uri' => $fileUri]]
                            ]
                        ]
                    ]
                ]);

            if ($response->status() === 429) {
                Log::warning("Quota Gemini habis (429), menunggu 30 detik...");
                throw new \Exception("Rate Limit");
            }

            if ($response->successful()) {
                $result = $response->json();
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $cleanJson = preg_replace('/^```json\s+|```$/', '', trim($text));
                return json_decode($cleanJson, true);
            }

            Log::error("Gemini Content Error", ['status' => $response->status(), 'body' => $response->json()]);
            throw new \Exception("Gagal di Tahap Content: " . $response->status());
        }, 30000);
    }

    protected function uploadToGemini($path)
    {
        $url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=" . trim($this->apiKey);

        $response = Http::withHeaders([
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'X-Goog-Upload-Header-Content-Length' => filesize($path),
            'X-Goog-Upload-Header-Content-Type' => 'audio/mpeg',
        ])->post($url, ['file' => ['display_name' => basename($path)]]);

        $uploadUrl = $response->header('X-Goog-Upload-URL');

        $upload = Http::withHeaders([
            'Content-Length' => filesize($path),
            'X-Goog-Upload-Offset' => '0',
            'X-Goog-Upload-Command' => 'upload, finalize',
        ])->withBody(file_get_contents($path), 'audio/mpeg')->post($uploadUrl);

        return $upload->json();
    }

    protected function waitForFile($fileId)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/" . $fileId . "?key=" . trim($this->apiKey);

        for ($i = 0; $i < 10; $i++) {
            $response = Http::get($url);
            $state = $response->json()['state'] ?? 'UNKNOWN';
            if ($state === 'ACTIVE') return;
            sleep(3);
        }
        throw new \Exception("File tidak kunjung ACTIVE.");
    }
}
