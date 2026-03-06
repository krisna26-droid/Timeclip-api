<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class GeminiService
{
    // Durasi setiap chunk dalam detik
    private int $chunkDuration = 60;

    public function transcribe(string $audioPath): array
    {
        Log::info("=== GEMINI TRANSCRIPTION START ===");

        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model');

        if (!$apiKey) throw new \Exception("Gemini API key belum dikonfigurasi.");
        if (!file_exists($audioPath)) throw new \Exception("File audio tidak ditemukan.");

        // Dapatkan durasi total audio
        $totalDuration = $this->getAudioDuration($audioPath);
        Log::info("Durasi audio: {$totalDuration} detik");

        // Kalau audio pendek (<= 90 detik), kirim langsung tanpa chunking
        if ($totalDuration <= 90) {
            Log::info("Audio pendek, kirim langsung tanpa chunking.");
            return $this->transcribeChunk($audioPath, 0.0, $apiKey, $model);
        }

        // Bagi audio jadi chunks
        $chunks    = $this->splitAudio($audioPath, $totalDuration);
        $allWords  = [];
        $allTexts  = [];

        Log::info("Audio dibagi menjadi " . count($chunks) . " chunk.");

        foreach ($chunks as $index => $chunk) {
            Log::info("Memproses chunk " . ($index + 1) . "/" . count($chunks) . " (offset: {$chunk['offset']}s)");

            try {
                $result = $this->transcribeChunk($chunk['path'], $chunk['offset'], $apiKey, $model);

                $allTexts[] = $result['full_text'];

                foreach ($result['words'] as $word) {
                    $allWords[] = $word;
                }

                Log::info("Chunk " . ($index + 1) . " selesai: " . count($result['words']) . " words.");
            } catch (\Throwable $e) {
                Log::warning("Chunk " . ($index + 1) . " gagal: " . $e->getMessage() . ". Lanjut ke chunk berikutnya.");
            } finally {
                // Hapus file chunk temporary
                if (file_exists($chunk['path'])) {
                    unlink($chunk['path']);
                }
            }
        }

        $fullText = implode(' ', array_filter($allTexts));

        Log::info("Transkripsi selesai.", [
            'total_words'       => count($allWords),
            'full_text_preview' => substr($fullText, 0, 100),
        ]);

        return [
            'full_text' => $fullText,
            'words'     => $allWords,
        ];
    }

    /**
     * Transkripsi satu chunk audio, tambahkan offset ke timestamps
     */
    private function transcribeChunk(string $audioPath, float $offset, string $apiKey, string $model): array
    {
        $mimeType     = mime_content_type($audioPath);
        $audioContent = base64_encode(file_get_contents($audioPath));

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

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
- "start" dan "end" adalah waktu dalam detik (float), dimulai dari 0.0 untuk audio ini
- Estimasi waktu seakurat mungkin berdasarkan ritme bicara
- Jangan sertakan emoji, tanda baca berlebihan, atau simbol aneh
- Pastikan JSON bisa di-parse langsung tanpa modifikasi apapun
PROMPT;

        $response = Http::timeout(300)
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
            throw new \Exception("Gemini API error: " . $response->status());
        }

        $data = $response->json();

        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception("Response Gemini tidak valid.");
        }

        $rawText = $data['candidates'][0]['content']['parts'][0]['text'];
        $result  = $this->parseGeminiResponse($rawText);

        // Tambahkan offset waktu ke setiap word
        if ($offset > 0 && !empty($result['words'])) {
            foreach ($result['words'] as &$word) {
                $word['start'] = round($word['start'] + $offset, 3);
                $word['end']   = round($word['end'] + $offset, 3);
            }
            unset($word);
        }

        return $result;
    }

    /**
     * Potong audio jadi array chunk files menggunakan FFmpeg
     */
    private function splitAudio(string $audioPath, float $totalDuration): array
    {
        $chunks  = [];
        $dir     = dirname($audioPath);
        $baseName = pathinfo($audioPath, PATHINFO_FILENAME);

        $ffmpegPath = $this->resolveFfmpeg();
        $start      = 0.0;
        $index      = 0;

        while ($start < $totalDuration) {
            $chunkPath = $dir . DIRECTORY_SEPARATOR . $baseName . '_chunk_' . $index . '.mp3';
            $duration  = min($this->chunkDuration, $totalDuration - $start);

            $process = new Process([
                $ffmpegPath,
                '-y',
                '-ss',
                (string) $start,
                '-t',
                (string) $duration,
                '-i',
                $audioPath,
                '-acodec',
                'libmp3lame',
                '-q:a',
                '2',
                $chunkPath
            ]);

            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($chunkPath)) {
                Log::warning("Gagal membuat chunk {$index} pada offset {$start}s");
                $start += $this->chunkDuration;
                $index++;
                continue;
            }

            $chunks[] = [
                'path'   => $chunkPath,
                'offset' => $start,
            ];

            $start += $this->chunkDuration;
            $index++;
        }

        return $chunks;
    }

    /**
     * Dapatkan durasi audio dalam detik menggunakan FFprobe
     */
    private function getAudioDuration(string $audioPath): float
    {
        $ffprobePath = $this->resolveFfprobe();

        $process = new Process([
            $ffprobePath,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $audioPath
        ]);

        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            $duration = trim($process->getOutput());
            if (is_numeric($duration)) {
                return (float) $duration;
            }
        }

        // Fallback: asumsikan 600 detik kalau ffprobe gagal
        Log::warning("FFprobe gagal mendapatkan durasi, asumsi 600 detik.");
        return 600.0;
    }

    /**
     * Parse response Gemini dengan multiple fallback strategy
     */
    private function parseGeminiResponse(string $rawText): array
    {
        $clean = trim($rawText);
        $clean = preg_replace('/^```json\s*/i', '', $clean);
        $clean = preg_replace('/^```\s*/i',     '', $clean);
        $clean = preg_replace('/```\s*$/i',     '', $clean);
        $clean = trim($clean);

        // Strategy 1: json_decode langsung
        $parsed = json_decode($clean, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $result = $this->extractFromParsed($parsed);
            if ($result !== null) {
                return $result;
            }
        }

        // Strategy 2: Fix malformed JSON lalu decode ulang
        $fixed  = $this->fixMalformedJson($clean);
        $parsed = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $result = $this->extractFromParsed($parsed);
            if ($result !== null) {
                return $result;
            }
        }

        // Strategy 3: Regex extraction
        $fullText = $this->regexExtractFullText($clean);
        $words    = $this->regexExtractWords($clean);

        if (!empty($fullText)) {
            return [
                'full_text' => $fullText,
                'words'     => $words,
            ];
        }

        // Strategy 4: Last resort
        Log::warning("Semua strategy parsing gagal untuk chunk ini.");
        return [
            'full_text' => $clean,
            'words'     => [],
        ];
    }

    /**
     * Ekstrak full_text dan words dari array hasil json_decode
     */
    private function extractFromParsed(?array $parsed): ?array
    {
        if (empty($parsed)) return null;

        if (isset($parsed['full_text'])) {
            $fullText = $parsed['full_text'];

            // Cek nested JSON
            if (is_string($fullText) && str_starts_with(trim($fullText), '{')) {
                $nested = json_decode($fullText, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($nested['full_text'])) {
                    $fullText            = $nested['full_text'];
                    $parsed['words'] = $nested['words'] ?? $parsed['words'] ?? [];
                }
            }

            $words = $parsed['words'] ?? [];

            $validWords = array_values(array_filter($words, function ($w) {
                return isset($w['word'], $w['start'], $w['end'])
                    && is_numeric($w['start'])
                    && is_numeric($w['end']);
            }));

            if (!empty($fullText)) {
                return [
                    'full_text' => trim($fullText),
                    'words'     => $validWords,
                ];
            }
        }

        return null;
    }

    /**
     * Perbaiki JSON yang malformed
     */
    private function fixMalformedJson(string $json): string
    {
        // Hapus trailing comma sebelum ] atau }
        $json = preg_replace('/,\s*([\]}])/m', '$1', $json);

        // Tutup JSON yang terpotong
        $openBraces   = substr_count($json, '{') - substr_count($json, '}');
        $openBrackets = substr_count($json, '[') - substr_count($json, ']');

        if ($openBraces > 0 || $openBrackets > 0) {
            $lastComplete = strrpos($json, '},');
            if ($lastComplete !== false) {
                $json = substr($json, 0, $lastComplete + 1);
            }
            $json .= str_repeat(']', max(0, $openBrackets));
            $json .= str_repeat('}', max(0, $openBraces));
        }

        return $json;
    }

    /**
     * Ekstrak full_text via regex
     */
    private function regexExtractFullText(string $text): string
    {
        if (preg_match('/"full_text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $text, $matches)) {
            return trim(stripslashes($matches[1]));
        }
        return '';
    }

    /**
     * Ekstrak words array via regex
     */
    private function regexExtractWords(string $text): array
    {
        $words   = [];
        $pattern = '/\{\s*"word"\s*:\s*"([^"\\\\]*)"\s*,\s*"start"\s*:\s*([\d.]+)\s*,\s*"end"\s*:\s*([\d.]+)\s*\}/';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $words[] = [
                    'word'  => $match[1],
                    'start' => (float) $match[2],
                    'end'   => (float) $match[3],
                ];
            }
        }

        return $words;
    }

    private function resolveFfmpeg(): string
    {
        return config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
    }

    private function resolveFfprobe(): string
    {
        return config('services.ffmpeg.probe_path', env('FFPROBE_PATH', 'ffprobe'));
    }
}
