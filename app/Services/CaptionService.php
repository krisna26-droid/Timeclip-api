<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\SystemLog;

class CaptionService
{
    public function generateAss(
        array  $words,
        string $fullText,
        float  $clipStart,
        float  $clipEnd,
        string $outputPath
    ): void {

        if (empty($words)) {
            Log::info("CaptionService: Words kosong, estimasi otomatis berdasarkan teks.");
            $words = $this->estimateWordTimestamps($fullText, $clipStart, $clipEnd);
        }

        if (empty($words)) {
            Log::error("CaptionService: Gagal generate ASS karena data words dan fullText kosong.");
            return;
        }

        $clipDuration = max(0, $clipEnd - $clipStart);
        $normalized = [];

        foreach ($words as $w) {
            if (!isset($w['start'], $w['end'], $w['word'])) {
                continue;
            }

            // Normalisasi waktu agar relatif terhadap awal klip (start at 0.0)
            $start = max(0, $w['start'] - $clipStart);
            $end   = max(0, $w['end'] - $clipStart);

            // Proteksi durasi agar tidak melebihi batas klip
            if ($end > $clipDuration) $end = $clipDuration;
            if ($end <= $start) $end = min($clipDuration, $start + 0.1);

            $normalized[] = [
                'word'  => $this->cleanWord($w['word']),
                'start' => round($start, 3),
                'end'   => round($end, 3),
            ];
        }

        if (empty($normalized)) {
            Log::warning("CaptionService: Normalized words menghasilkan array kosong.");
            return;
        }

        try {
            $assContent = $this->buildYoutubeKaraokeStyle($normalized);

            file_put_contents(
                $outputPath,
                mb_convert_encoding($assContent, 'UTF-8')
            );

            Log::info("CaptionService: File ASS berhasil dibuat.", ['path' => $outputPath]);
        } catch (\Exception $e) {
            Log::error("CaptionService: Gagal menulis file ASS ke disk: " . $e->getMessage());
            throw $e; // Lempar agar Job bisa menangkap dan mencatat ke SystemLog
        }
    }

    /**
     * Build format subtitle karaoke ala YouTube Shorts
     */
    private function buildYoutubeKaraokeStyle(array $words): string
    {
        if (empty($words)) return '';

        // PlayResX/Y disesuaikan dengan rasio 9:16 (608x1080)
        $header = <<<ASS
[Script Info]
ScriptType: v4.00+
PlayResX: 608
PlayResY: 1080
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: YT,Arial,58,&H00FFFFFF,&H0000FFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,0,2,60,60,140,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text

ASS;

        // Kelompokkan kata maksimal per 3 detik agar subtitle tidak menumpuk
        $groups = $this->splitByDuration($words, 3.0);
        $dialogues = '';

        foreach ($groups as $group) {
            if (empty($group)) continue;

            $start = $this->toAssTime($group[0]['start']);
            $end   = $this->toAssTime(end($group)['end']);

            $karaokeText = '';
            $lines = $this->splitBalancedLines($group);

            foreach ($lines as $lineIndex => $lineWords) {
                foreach ($lineWords as $wordData) {
                    $duration = $wordData['end'] - $wordData['start'];
                    // Format \k dalam centiseconds (1/100 detik)
                    $centiseconds = max(1, (int) round($duration * 100));
                    $karaokeText .= "{\\k{$centiseconds}}" . $wordData['word'] . " ";
                }

                // Jika ada 2 baris, tambahkan line break ASS
                if ($lineIndex === 0 && count($lines) > 1) {
                    $karaokeText .= "\\N";
                }
            }

            $dialogues .= "Dialogue: 0,{$start},{$end},YT,,0,0,0,,{$karaokeText}\n";
        }

        return $header . $dialogues;
    }

    private function splitByDuration(array $words, float $maxSeconds): array
    {
        $groups = [];
        $current = [];
        $startTime = $words[0]['start'] ?? 0;

        foreach ($words as $word) {
            if (empty($current)) $startTime = $word['start'];
            $current[] = $word;

            if (($word['end'] - $startTime) >= $maxSeconds) {
                $groups[] = $current;
                $current = [];
            }
        }

        if (!empty($current)) $groups[] = $current;
        return $groups;
    }

    private function splitBalancedLines(array $group): array
    {
        $count = count($group);
        if ($count <= 6) return [$group];

        $mid = floor($count / 2);
        return [
            array_slice($group, 0, (int)$mid),
            array_slice($group, (int)$mid)
        ];
    }

    private function estimateWordTimestamps(string $text, float $start, float $end): array
    {
        $words = explode(' ', trim(preg_replace('/\s+/', ' ', $text)));
        if (empty($words)) return [];

        $duration = max(0.1, $end - $start);
        $perWord = $duration / count($words);

        $current = $start;
        $result = [];

        foreach ($words as $word) {
            $result[] = [
                'word'  => $this->cleanWord($word),
                'start' => $current,
                'end'   => $current + $perWord,
            ];
            $current += $perWord;
        }

        return $result;
    }

    private function toAssTime(float $seconds): string
    {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = floor($seconds % 60);
        $cs = (int) round(($seconds - floor($seconds)) * 100);

        if ($cs === 100) {
            $cs = 0;
            $s++;
        }
        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }

    private function cleanWord(string $word): string
    {
        return trim(str_replace(['{', '}', '\\'], '', $word));
    }
}
