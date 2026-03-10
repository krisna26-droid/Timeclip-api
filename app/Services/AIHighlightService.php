<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\SystemLog; // Import Model SystemLog
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

            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'WARNING',
                'category' => 'CONTENT_SHORT',
                'user_id'  => Auth::id(),
                'message'  => "Transkrip terlalu pendek untuk dianalisis highlight ({$wordCount} kata).",
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
        1. Each segment should be between 10 to 60 seconds (be flexible if the video is short).
        2. If the entire video is short (under 60 seconds), you may return the whole video as 1 segment.
        3. Titles must be catchy and clickbait-style.
        4. Viral score should reflect hook strength (1-100).
        5. start_time and end_time must be numbers in seconds (e.g. 0, 12.5, 30).

        Return ONLY a raw JSON array of objects with keys: 'title', 'start_time', 'end_time', 'viral_score'.
        Do not include any markdown, explanation, or formatting. Only the JSON array.
        Example: [{\"title\":\"...\",\"start_time\":0,\"end_time\":30,\"viral_score\":85}]
        ";

        try {
            $response = Http::timeout(60)->post($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                    'temperature' => 0.7
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $text   = $result['candidates'][0]['content']['parts'][0]['text'] ?? '[]';

                // Bersihkan markdown jika ada
                $text = preg_replace('/```json|```/i', '', $text);
                $text = trim($text);

                $highlights = json_decode($text, true);

                if (!is_array($highlights)) {
                    SystemLog::create([
                        'service'  => 'GEMINI',
                        'level'    => 'ERROR',
                        'category' => 'PARSE_ERROR',
                        'user_id'  => Auth::id(),
                        'message'  => "AI gagal memberikan format JSON array yang valid untuk highlight.",
                        'payload'  => ['raw_response' => $text]
                    ]);
                    return [];
                }

                // LOG BERHASIL: Catat jumlah highlight dan penggunaan token
                SystemLog::create([
                    'service'  => 'GEMINI',
                    'level'    => 'INFO',
                    'category' => 'HIGHLIGHT_DISCOVERY',
                    'user_id'  => Auth::id(),
                    'message'  => "Berhasil menemukan " . count($highlights) . " highlight.",
                    'payload'  => [
                        'query' => $query,
                        'usage' => $result['usageMetadata'] ?? null
                    ]
                ]);

                return $highlights;
            }

            // LOG ERROR API
            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'ERROR',
                'category' => 'API_ERROR',
                'user_id'  => Auth::id(),
                'message'  => "Gemini Highlight API Gagal: " . $response->status(),
                'payload'  => $response->json()
            ]);
        } catch (\Exception $e) {
            Log::error("AIHighlightService Exception: " . $e->getMessage());

            SystemLog::create([
                'service'  => 'GEMINI',
                'level'    => 'ERROR',
                'category' => 'SYSTEM',
                'user_id'  => Auth::id(),
                'message'  => "Exception pada AIHighlightService: " . $e->getMessage(),
            ]);
        }

        return [];
    }
}
