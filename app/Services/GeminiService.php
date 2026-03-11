<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;

class GeminiService
{
    public function transcribe(string $audioPath): array
    {
        Log::info("=== GEMINI TRANSCRIPTION START (SINGLE REQUEST MODE) ===");

        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-2.5-flash');

        if (!$apiKey) throw new \Exception("Gemini API key belum dikonfigurasi.");
        if (!file_exists($audioPath)) throw new \Exception("File audio tidak ditemukan.");

        $totalDuration = $this->getAudioDuration($audioPath);
        Log::info("Durasi audio utuh: {$totalDuration} detik");

        try {
            $result = $this->transcribeFullFile($audioPath, $apiKey, $model);

            Log::info("Transkripsi berhasil.", [
                'total_words'  => count($result['words'] ?? []),
                'text_preview' => substr($result['full_text'] ?? '', 0, 100),
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error("Gagal melakukan transkripsi: " . $e->getMessage());

            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'ERROR',
                'category' => 'SYSTEM',
                'user_id'  => Auth::id(),
                'message'  => "Exception pada GeminiService: " . $e->getMessage(),
                'payload'  => ['trace' => substr($e->getTraceAsString(), 0, 1000)]
            ]);

            throw $e;
        }
    }

    private function transcribeFullFile(string $audioPath, string $apiKey, string $model): array
    {
        $mimeType     = mime_content_type($audioPath);
        $audioContent = base64_encode(file_get_contents($audioPath));

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $prompt = <<<PROMPT
Transcribe this audio accurately in its original language.
Return ONLY a raw JSON object. No markdown, no backticks, no explanation.
Use this EXACT structure:
{
  "full_text": "complete transcription here",
  "words": [
    ["word1", 0.0, 0.5],
    ["word2", 0.6, 1.1]
  ]
}
Rules:
- "words" is an array of arrays: [word_string, start_seconds, end_seconds]
- Include EVERY spoken word
- Timestamps are floats in seconds
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
                ],
                "generationConfig" => [
                    "response_mime_type" => "application/json",
                    "temperature"        => 0.0,
                    "maxOutputTokens"    => 65536,
                ]
            ]);

        if (!$response->successful()) {
            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'ERROR',
                'category' => 'API_ERROR',
                'user_id'  => Auth::id(),
                'message'  => "Gemini API Error: " . $response->status(),
                'payload'  => ['status' => $response->status(), 'body' => $response->json()]
            ]);

            throw new \Exception("Gemini API error: " . $response->status() . " - " . $response->body());
        }

        $data = $response->json();

        SystemLog::create([
            'service'  => 'GEMINI',
            'level'    => 'INFO',
            'category' => 'USAGE',
            'user_id'  => Auth::id(),
            'message'  => "Berhasil melakukan transkripsi audio.",
            'payload'  => ['model' => $model, 'usage' => $data['usageMetadata'] ?? null]
        ]);

        $rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->parseGeminiResponse($rawText);
    }

    private function parseGeminiResponse(string $rawText): array
    {
        // 1. Bersihkan Karakter Kontrol (Kunci utama perbaikan Control character error)
        // Menghapus karakter ASCII 0-31 dan 127
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $rawText);

        // 2. Bersihkan Markdown Backticks
        $clean = trim($clean);
        $clean = preg_replace('/^```json\s*/i', '', $clean);
        $clean = preg_replace('/^```\s*/i',     '', $clean);
        $clean = preg_replace('/```\s*$/i',     '', $clean);
        $clean = trim($clean);

        // 3. Ekstraksi Blok JSON Terluar (Jika ada teks tambahan dari AI)
        $startPos = strpos($clean, '{');
        $endPos = strrpos($clean, '}');
        if ($startPos !== false && $endPos !== false) {
            $clean = substr($clean, $startPos, $endPos - $startPos + 1);
        }

        // 4. Decode JSON Utama
        $parsed = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Gagal parse JSON Gemini: " . json_last_error_msg());

            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'WARNING',
                'category' => 'PARSE_ERROR',
                'user_id'  => Auth::id(),
                'message'  => "JSON Parse Error, mencoba fallback regex.",
                'payload'  => ['error' => json_last_error_msg(), 'raw' => substr($clean, 0, 500)]
            ]);

            return $this->extractFallback($clean);
        }

        $fullText = $parsed['full_text'] ?? '';
        $rawWords = $parsed['words'] ?? [];

        return [
            'full_text' => (string) $fullText,
            'words'     => $this->normalizeWords($rawWords),
        ];
    }

    private function normalizeWords(array $rawWords): array
    {
        if (empty($rawWords)) return [];
        $normalized = [];

        foreach ($rawWords as $w) {
            if (is_array($w) && isset($w[0], $w[1], $w[2])) {
                $normalized[] = [
                    'word'  => (string) $w[0],
                    'start' => (float)  $w[1],
                    'end'   => (float)  $w[2],
                ];
            } elseif (is_array($w) && isset($w['word'], $w['start'], $w['end'])) {
                $normalized[] = [
                    'word'  => (string) $w['word'],
                    'start' => (float)  $w['start'],
                    'end'   => (float)  $w['end'],
                ];
            }
        }
        return $normalized;
    }

    private function extractFallback(string $raw): array
    {
        Log::warning("Menggunakan fallback ekstraksi manual regex.");

        $fullText = '';
        $words    = [];

        if (preg_match('/"full_text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $raw, $m)) {
            $fullText = stripslashes($m[1]);
        }

        if (preg_match('/"words"\s*:\s*(\[.*?\])/s', $raw, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                $words = $this->normalizeWords($decoded);
            }
        }

        return ['full_text' => $fullText, 'words' => $words];
    }

    private function getAudioDuration(string $audioPath): float
    {
        try {
            $ffprobePath = config('services.ffmpeg.probe_path', env('FFPROBE_PATH', 'ffprobe'));
            $process     = new Process([
                $ffprobePath,
                '-v',
                'error',
                '-show_entries',
                'format=duration',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $audioPath
            ]);
            $process->run();
            return $process->isSuccessful() ? (float) trim($process->getOutput()) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
