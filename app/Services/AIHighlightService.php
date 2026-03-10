<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;

class AIHighlightService
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = trim((string) config('services.gemini.key'));
        $this->model  = config('services.gemini.model', 'gemini-2.5-flash');
    }

    public function getHighlights(string $fullText, ?string $query = null): array
    {
        $wordCount = str_word_count($fullText);
        Log::info("AIHighlightService: mulai analisis", ['word_count' => $wordCount]);

        if ($wordCount < 10) {
            Log::warning("Transkrip terlalu pendek untuk dianalisis ({$wordCount} kata).");

            // LOG UNTUK ADMIN: Memberi tahu kenapa highlight tidak muncul
            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'WARNING',
                'category' => 'USAGE',
                'user_id'  => Auth::id(),
                'message'  => "Highlight dibatalkan: Transkrip terlalu pendek ({$wordCount} kata).",
            ]);

            return [];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $instruction = $query
            ? "Find segments in this transcript specifically about: '{$query}'."
            : "Identify 2 to 5 most engaging or viral segments for TikTok/Reels.";

        $prompt = "
        You are an expert social media viral curator.
        Analyze the following video transcript.

        Instruction: {$instruction}

        Transcript:
        \"{$fullText}\"

        Rules:
        1. Each segment should be between 10 to 60 seconds.
        2. Titles catchy and clickbait-style.
        3. Viral score 1-100.
        4. start_time and end_time must be numbers in seconds.

        Return ONLY a raw JSON array of objects with keys: 'title', 'start_time', 'end_time', 'viral_score'.
        ";

        try {
            $response = Http::timeout(60)->post($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                    'temperature' => 0.7
                ]
            ]);

            Log::info("Gemini raw response", ['body' => $response->body()]);

            if ($response->successful()) {
                $result = $response->json();
                $text   = $result['candidates'][0]['content']['parts'][0]['text'] ?? '[]';

                $text = preg_replace('/```json|```/i', '', $text);
                $text = trim($text);

                $highlights = json_decode($text, true);

                if (!is_array($highlights)) {
                    Log::warning("Gemini response bukan array JSON valid", ['text' => $text]);

                    // LOG UNTUK ADMIN: Mencatat kegagalan parsing AI
                    SystemLog::create([
                        'service'  => 'GEMINI',
                        'level'    => 'WARNING',
                        'category' => 'PARSE_ERROR',
                        'user_id'  => Auth::id(),
                        'message'  => "Gagal parse highlight JSON.",
                        'payload'  => ['raw_text' => substr($text, 0, 500)]
                    ]);

                    return [];
                }

                // LOG UNTUK ADMIN: Berhasil menemukan momen viral (Audit Trail)
                SystemLog::create([
                    'service'  => 'GEMINI',
                    'level'    => 'INFO',
                    'category' => 'USAGE',
                    'user_id'  => Auth::id(),
                    'message'  => "AI berhasil menemukan " . count($highlights) . " momen viral.",
                    'payload'  => ['query' => $query, 'highlight_count' => count($highlights)]
                ]);

                return $highlights;
            }

            // LOG UNTUK ADMIN: Error API Gemini
            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'ERROR',
                'category' => 'API_ERROR',
                'user_id'  => Auth::id(),
                'message'  => "Gemini Highlight API Error: " . $response->status(),
                'payload'  => ['body' => $response->json()]
            ]);
        } catch (\Exception $e) {
            Log::error("AIHighlightService Exception: " . $e->getMessage());

            // LOG UNTUK ADMIN: Crash Sistem
            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'ERROR',
                'category' => 'SYSTEM',
                'user_id'  => Auth::id(),
                'message'  => "Exception di AIHighlightService: " . $e->getMessage(),
            ]);
        }

        return [];
    }
}
