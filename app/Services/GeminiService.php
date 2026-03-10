<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

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
            throw $e;
        }
    }

    private function transcribeFullFile(string $audioPath, string $apiKey, string $model): array
    {
        $mimeType     = mime_content_type($audioPath);
        $audioContent = base64_encode(file_get_contents($audioPath));

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        // Prompt diperketat:
        // - Format compact [word,start,end] untuk hemat token
        // - Instruksi eksplisit jangan potong di tengah
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
- Use compact array format (NOT objects) to save space
- Include EVERY spoken word
- Timestamps are floats in seconds
- Do NOT stop early — transcribe the complete audio from start to finish
- Do NOT add any text before or after the JSON
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
                    "temperature"        => 0.0,    // Paling deterministik
                    "maxOutputTokens"    => 65536,  // Cukup untuk 1300+ kata
                ]
            ]);

        if (!$response->successful()) {
            throw new \Exception("Gemini API error: " . $response->status() . " - " . $response->body());
        }

        $data    = $response->json();
        $rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->parseGeminiResponse($rawText);
    }

    private function parseGeminiResponse(string $rawText): array
    {
        $clean = trim($rawText);

        // Bersihkan markdown kalau masih ada
        $clean = preg_replace('/^```json\s*/i', '', $clean);
        $clean = preg_replace('/^```\s*/i',     '', $clean);
        $clean = preg_replace('/```\s*$/i',     '', $clean);
        $clean = trim($clean);

        // Bersihkan control characters
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

        // Normalisasi newline di dalam full_text
        $clean = preg_replace_callback('/"full_text"\s*:\s*"(.*?)(?<!\\\\)"/s', function ($matches) {
            $inner = str_replace(["\r\n", "\r", "\n"], ' ', $matches[1]);
            return '"full_text": "' . $inner . '"';
        }, $clean);

        $parsed = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Gagal parse JSON Gemini: " . json_last_error_msg());
            Log::debug("Raw text (200 char pertama): " . substr($clean, 0, 200));
            return $this->extractFallback($clean);
        }

        // Proteksi Double JSON
        if (isset($parsed['full_text']) && is_string($parsed['full_text'])) {
            $testNested = json_decode($parsed['full_text'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($testNested['full_text'])) {
                Log::info("Mendeteksi Double JSON, melakukan ekstraksi...");
                $parsed = $testNested;
            }
        }

        $fullText = $parsed['full_text'] ?? '';
        $rawWords = $parsed['words'] ?? [];

        // Normalisasi format words:
        // Support format compact array [word, start, end]
        // maupun format object {word, start, end}
        $words = $this->normalizeWords($rawWords);

        return [
            'full_text' => $fullText,
            'words'     => $words,
        ];
    }

    /**
     * Normalisasi words array:
     * Format compact  : [["kata", 0.0, 0.5], ...]
     * Format object   : [{"word": "kata", "start": 0.0, "end": 0.5}, ...]
     * Keduanya dikonversi ke format object standar.
     */
    private function normalizeWords(array $rawWords): array
    {
        if (empty($rawWords)) return [];

        $normalized = [];

        foreach ($rawWords as $w) {
            if (is_array($w) && isset($w[0], $w[1], $w[2])) {
                // Format compact: ["kata", 0.0, 0.5]
                $normalized[] = [
                    'word'  => (string) $w[0],
                    'start' => (float)  $w[1],
                    'end'   => (float)  $w[2],
                ];
            } elseif (is_array($w) && isset($w['word'], $w['start'], $w['end'])) {
                // Format object: {"word": "kata", "start": 0.0, "end": 0.5}
                $normalized[] = [
                    'word'  => (string) $w['word'],
                    'start' => (float)  $w['start'],
                    'end'   => (float)  $w['end'],
                ];
            }
        }

        Log::info("Words normalized: " . count($normalized) . " kata.");

        return $normalized;
    }

    /**
     * Fallback manual kalau JSON tetap gagal di-parse.
     */
    private function extractFallback(string $raw): array
    {
        Log::warning("Menggunakan fallback ekstraksi manual dari response Gemini.");

        $fullText = '';
        $words    = [];

        // Ekstrak full_text via regex
        if (preg_match('/"full_text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $raw, $m)) {
            $fullText = stripslashes($m[1]);
        }

        // Ekstrak words array via regex — coba format compact dulu
        if (preg_match('/"words"\s*:\s*(\[.*?\])/s', $raw, $m)) {
            $wordsJson = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $m[1]);
            $decoded   = json_decode($wordsJson, true);
            if (is_array($decoded)) {
                $words = $this->normalizeWords($decoded);
            }
        }

        Log::info("Fallback result: full_text_len=" . strlen($fullText) . ", words=" . count($words));

        return [
            'full_text' => $fullText,
            'words'     => $words,
        ];
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

            if ($process->isSuccessful()) {
                return (float) trim($process->getOutput());
            }
        } catch (\Throwable $e) {
            Log::warning("Gagal mendapatkan durasi: " . $e->getMessage());
        }

        return 0.0;
    }
}
