<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class GeminiService
{
    public function transcribe(string $audioPath): array
    {
        Log::info("=== GEMINI TRANSCRIPTION START (CHUNKED MODE) ===");

        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-2.5-flash');

        if (!$apiKey) throw new \Exception("Gemini API key belum dikonfigurasi.");
        if (!file_exists($audioPath)) throw new \Exception("File audio tidak ditemukan.");

        $totalDuration = $this->getAudioDuration($audioPath);
        Log::info("Total Durasi: {$totalDuration} detik");

        // Jika video lebih dari 3 menit (180 detik), gunakan chunking
        if ($totalDuration > 180) {
            return $this->processInChunks($audioPath, $totalDuration, $apiKey, $model);
        }

        // Jalur normal untuk video pendek
        return $this->processNormal($audioPath, $totalDuration, $apiKey, $model);
    }

    private function processInChunks(string $audioPath, float $totalDuration, string $apiKey, string $model): array
    {
        $chunkDuration = 120.0; // Potong per 2 menit (aman untuk token & timeout)
        $allWords = [];
        $fullTextParts = [];

        $tempDir = storage_path('app/temp_chunks_' . time());
        File::makeDirectory($tempDir, 0755, true);

        try {
            for ($start = 0; $start < $totalDuration; $start += $chunkDuration) {
                $actualChunkDuration = min($chunkDuration, $totalDuration - $start);
                $chunkPath = $tempDir . "/chunk_{$start}.mp3";

                Log::info("Memproses chunk: {$start}s sampai " . ($start + $actualChunkDuration) . "s");

                // 1. Potong Audio menggunakan FFmpeg
                $this->extractChunk($audioPath, $chunkPath, $start, $actualChunkDuration);

                $mimeType = mime_content_type($chunkPath);
                $audioContent = base64_encode(file_get_contents($chunkPath));
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

                // 2. Request Full Text untuk Chunk ini
                $chunkText = $this->requestFullText($audioContent, $mimeType, $endpoint);
                $fullTextParts[] = $chunkText;

                // 3. Request Word Timestamps untuk Chunk ini
                $chunkWords = $this->requestWords($audioContent, $mimeType, $endpoint, $chunkText, $actualChunkDuration);

                // 4. Adjust Timestamp (tambahkan offset $start)
                foreach ($chunkWords as $w) {
                    $allWords[] = [
                        'word'  => $w['word'],
                        'start' => round($w['start'] + $start, 3),
                        'end'   => round($w['end'] + $start, 3),
                    ];
                }

                File::delete($chunkPath); // Hapus chunk setelah diproses
            }

            File::deleteDirectory($tempDir);

            return [
                'full_text' => implode(' ', $fullTextParts),
                'words'     => $allWords,
            ];
        } catch (\Throwable $e) {
            File::deleteDirectory($tempDir);
            throw $e;
        }
    }

    private function processNormal(string $audioPath, float $totalDuration, string $apiKey, string $model): array
    {
        $mimeType = mime_content_type($audioPath);
        $audioContent = base64_encode(file_get_contents($audioPath));
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $fullText = $this->requestFullText($audioContent, $mimeType, $endpoint);
        $words = $this->requestWords($audioContent, $mimeType, $endpoint, $fullText, $totalDuration);

        return ['full_text' => $fullText, 'words' => $words];
    }

    private function extractChunk(string $input, string $output, float $start, float $duration): void
    {
        $ffmpegPath = config('services.ffmpeg.ffmpeg_path', env('FFMPEG_PATH', 'ffmpeg'));
        $process = new Process([
            $ffmpegPath,
            '-y',
            '-ss',
            $start,
            '-t',
            $duration,
            '-i',
            $input,
            '-acodec',
            'libmp3lame',
            '-b:a',
            '64k', // Kompres bitrate agar base64 tidak raksasa
            $output
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception("Gagal memotong chunk audio: " . $process->getErrorOutput());
        }
    }

    private function requestFullText(string $audioContent, string $mimeType, string $endpoint): string
    {
        $prompt = "Transcribe this audio accurately. Return ONLY a JSON object: {\"full_text\": \"...\"}";

        $response = Http::timeout(600)->post($endpoint, [
            "contents" => [[
                "role"  => "user",
                "parts" => [
                    ["text" => $prompt],
                    ["inline_data" => ["mime_type" => $mimeType, "data" => $audioContent]]
                ]
            ]],
            "generationConfig" => ["response_mime_type" => "application/json", "temperature" => 0.0]
        ]);

        $res = $response->json();
        $rawText = $res['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        $parsed = json_decode($this->cleanJson($rawText), true);

        return $parsed['full_text'] ?? '';
    }

    private function requestWords(string $audioContent, string $mimeType, string $endpoint, string $fullText, float $duration): array
    {
        try {
            $prompt = <<<PROMPT
Audio Duration: {$duration} seconds.
Full Transcript: "{$fullText}"
TASK: Map EVERY WORD to precise [start, end] timestamps.
STRICT RULES: 1. No skips. 2. Output JSON ONLY: {"words": [["word", start, end], ...]}
PROMPT;

            $response = Http::timeout(600)->post($endpoint, [
                "contents" => [[
                    "role"  => "user",
                    "parts" => [
                        ["text" => $prompt],
                        ["inline_data" => ["mime_type" => $mimeType, "data" => $audioContent]]
                    ]
                ]],
                "generationConfig" => [
                    "response_mime_type" => "application/json",
                    "temperature" => 0.0,
                    "maxOutputTokens" => 65536
                ]
            ]);

            if (!$response->successful()) throw new \Exception("API Error");

            $rawText = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed  = json_decode($this->cleanJson($rawText), true);

            return $this->normalizeWords($parsed['words'] ?? []);
        } catch (\Throwable $e) {
            return $this->estimateWords($fullText, $duration);
        }
    }

    // --- HELPER METHODS (DIBIARKAN TETAP SAMA) ---

    private function estimateWords(string $fullText, float $duration): array
    {
        $wordList = array_values(array_filter(preg_split('/\s+/', trim($fullText))));
        if (empty($wordList) || $duration <= 0) return [];
        $perWord = $duration / count($wordList);
        $current = 0.0;
        $result = [];
        foreach ($wordList as $word) {
            $result[] = ['word' => $word, 'start' => round($current, 3), 'end' => round($current + $perWord, 3)];
            $current += $perWord;
        }
        return $result;
    }

    private function cleanJson(string $rawText): string
    {
        $clean = trim($rawText);
        $clean = preg_replace('/^```json\s*/i', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
        $startPos = strpos($clean, '{');
        $endPos = strrpos($clean, '}');
        if ($startPos !== false && $endPos !== false) {
            $clean = substr($clean, $startPos, $endPos - $startPos + 1);
        }
        return $clean;
    }

    private function normalizeWords(array $rawWords): array
    {
        $normalized = [];
        foreach ($rawWords as $w) {
            if (isset($w[0], $w[1], $w[2])) {
                $normalized[] = ['word' => (string)$w[0], 'start' => (float)$w[1], 'end' => (float)$w[2]];
            } elseif (isset($w['word'], $w['start'], $w['end'])) {
                $normalized[] = ['word' => (string)$w['word'], 'start' => (float)$w['start'], 'end' => (float)$w['end']];
            }
        }
        return $normalized;
    }

    private function getAudioDuration(string $audioPath): float
    {
        try {
            $ffprobePath = config('services.ffmpeg.probe_path', env('FFPROBE_PATH', 'ffprobe'));
            $process = new Process([$ffprobePath, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $audioPath]);
            $process->run();
            return $process->isSuccessful() ? (float) trim($process->getOutput()) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
