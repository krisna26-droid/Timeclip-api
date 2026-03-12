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
        Log::info("=== GEMINI TRANSCRIPTION START (TWO-STEP MODE) ===");

        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-2.5-flash');

        if (!$apiKey) throw new \Exception("Gemini API key belum dikonfigurasi.");
        if (!file_exists($audioPath)) throw new \Exception("File audio tidak ditemukan.");

        $totalDuration = $this->getAudioDuration($audioPath);
        Log::info("Durasi audio utuh: {$totalDuration} detik");

        $mimeType     = mime_content_type($audioPath);
        $audioContent = base64_encode(file_get_contents($audioPath));
        $endpoint     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        // STEP 1: Minta full_text saja — ringan, pasti cepat
        $fullText = $this->requestFullText($audioContent, $mimeType, $endpoint);
        Log::info("Full text berhasil.", ['preview' => substr($fullText, 0, 100)]);

        // STEP 2: Minta words + timestamp — kalau gagal, pakai estimasi otomatis
        $words = $this->requestWords($audioContent, $mimeType, $endpoint, $fullText, $totalDuration);
        Log::info("Words selesai.", ['count' => count($words)]);

        SystemLog::create([
            'service'  => 'GEMINI',
            'level'    => 'INFO',
            'category' => 'USAGE',
            'user_id'  => Auth::id(),
            'message'  => "Transkripsi 2-step berhasil.",
            'payload'  => ['model' => $model, 'word_count' => count($words)]
        ]);

        return [
            'full_text' => $fullText,
            'words'     => $words,
        ];
    }

    private function requestFullText(string $audioContent, string $mimeType, string $endpoint): string
    {
        $prompt = <<<PROMPT
Transcribe this audio accurately in its original language.
Return ONLY a raw JSON object with this exact structure:
{"full_text": "complete transcription here"}
No markdown, no backticks, no explanation.
PROMPT;

        $response = Http::timeout(600)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($endpoint, [
                "contents" => [[
                    "role"  => "user",
                    "parts" => [
                        ["text" => $prompt],
                        ["inline_data" => ["mime_type" => $mimeType, "data" => $audioContent]]
                    ]
                ]],
                "generationConfig" => [
                    "response_mime_type" => "application/json",
                    "temperature"        => 0.0,
                    "maxOutputTokens"    => 8192,
                ]
            ]);

        if (!$response->successful()) {
            throw new \Exception("Gemini full_text request gagal: " . $response->status());
        }

        $rawText  = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $clean    = $this->cleanJson($rawText);
        $parsed   = json_decode($clean, true);
        $fullText = $parsed['full_text'] ?? '';

        if (empty($fullText)) {
            throw new \Exception("Full text kosong dari Gemini.");
        }

        return $fullText;
    }

    private function requestWords(string $audioContent, string $mimeType, string $endpoint, string $fullText, float $duration): array
    {
        try {
            $prompt = <<<PROMPT
You already know the transcript of this audio:
"{$fullText}"

Now provide word-level timestamps for this audio.
Return ONLY a raw JSON object:
{
  "words": [
    ["word1", 0.0, 0.5],
    ["word2", 0.6, 1.1]
  ]
}
Rules:
- Match EXACTLY the words in the transcript above
- Timestamps are floats in seconds
- No markdown, no backticks
PROMPT;

            $response = Http::timeout(600)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, [
                    "contents" => [[
                        "role"  => "user",
                        "parts" => [
                            ["text" => $prompt],
                            ["inline_data" => ["mime_type" => $mimeType, "data" => $audioContent]]
                        ]
                    ]],
                    "generationConfig" => [
                        "response_mime_type" => "application/json",
                        "temperature"        => 0.0,
                        "maxOutputTokens"    => 65536,
                    ]
                ]);

            if (!$response->successful()) {
                throw new \Exception("Words request gagal: " . $response->status());
            }

            $rawText = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $clean   = $this->cleanJson($rawText);
            $parsed  = json_decode($clean, true);
            $words   = $this->normalizeWords($parsed['words'] ?? []);

            if (!empty($words)) {
                return $words;
            }

            throw new \Exception("Words kosong dari response.");

        } catch (\Throwable $e) {
            Log::warning("Words request gagal, pakai estimasi: " . $e->getMessage());

            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'WARNING',
                'category' => 'USAGE',
                'user_id'  => Auth::id(),
                'message'  => "Words timestamp gagal, menggunakan estimasi otomatis.",
                'payload'  => ['error' => $e->getMessage()]
            ]);

            return $this->estimateWords($fullText, $duration);
        }
    }

    private function estimateWords(string $fullText, float $duration): array
    {
        $wordList = array_values(array_filter(preg_split('/\s+/', trim($fullText))));

        if (empty($wordList) || $duration <= 0) return [];

        $perWord = $duration / count($wordList);
        $current = 0.0;
        $result  = [];

        foreach ($wordList as $word) {
            $result[] = [
                'word'  => $word,
                'start' => round($current, 3),
                'end'   => round($current + $perWord, 3),
            ];
            $current += $perWord;
        }

        Log::info("Estimasi words selesai.", ['count' => count($result)]);

        return $result;
    }

    private function cleanJson(string $rawText): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $rawText);
        $clean = trim($clean);
        $clean = preg_replace('/^```json\s*/i', '', $clean);
        $clean = preg_replace('/^```\s*/i',     '', $clean);
        $clean = preg_replace('/```\s*$/i',     '', $clean);
        $clean = trim($clean);

        $startPos = strpos($clean, '{');
        $endPos   = strrpos($clean, '}');
        if ($startPos !== false && $endPos !== false) {
            $clean = substr($clean, $startPos, $endPos - $startPos + 1);
        }

        return $clean;
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

    private function getAudioDuration(string $audioPath): float
    {
        try {
            $ffprobePath = config('services.ffmpeg.probe_path', env('FFPROBE_PATH', 'ffprobe'));
            $process     = new Process([
                $ffprobePath, '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $audioPath
            ]);
            $process->run();
            return $process->isSuccessful() ? (float) trim($process->getOutput()) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}