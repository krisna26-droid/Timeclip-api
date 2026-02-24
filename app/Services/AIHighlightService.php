<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIHighlightService
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = trim((string) config('services.gemini.key'));
        // Menggunakan model dari .env sesuai spesifikasi kamu
        $this->model  = config('services.gemini.model', 'gemini-2.5-flash');
    }

    /**
     * @param string $fullText
     * @param string|null $query (Opsional untuk Ask AI Agent)
     */
    public function getHighlights(string $fullText, ?string $query = null): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        // Logika Prompt: Jika ada query, instruksikan AI mencari momen spesifik
        $instruction = $query 
            ? "Find segments in this transcript specifically about: '{$query}'."
            : "Identify 3 to 5 most engaging or viral segments for TikTok/Reels.";

        $prompt = "
        You are an expert social media viral curator. 
        Analyze the following video transcript.
        
        Instruction: {$instruction}
        
        Transcript:
        \"{$fullText}\"

        Rules:
        1. Each segment must be between 15 to 60 seconds.
        2. Titles must be catchy and clickbait-style.
        3. Viral score should reflect hook strength (1-100).

        Return ONLY a raw JSON array of objects with keys: 'title', 'start_time', 'end_time', 'viral_score'.
        Do not include markdown formatting like ```json.
        ";

        try {
            $response = Http::timeout(60)->post($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                    'temperature' => 1.0
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
                return json_decode($text, true) ?: [];
            }

            Log::error("Gemini Highlight API Error", ['body' => $response->body()]);
        } catch (\Exception $e) {
            Log::error("AIHighlightService Exception: " . $e->getMessage());
        }

        return [];
    }
}