<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

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
            Log::info("Words kosong, estimasi otomatis.");
            $words = $this->estimateWordTimestamps($fullText, $clipStart, $clipEnd);
        }

        $clipDuration = max(0, $clipEnd - $clipStart);

        $normalized = array_map(function ($w) use ($clipStart, $clipDuration) {

            $start = max(0, $w['start'] - $clipStart);
            $end   = max(0, $w['end'] - $clipStart);

            if ($end > $clipDuration) $end = $clipDuration;
            if ($end <= $start) $end = min($clipDuration, $start + 0.1);

            return [
                'word'  => $this->cleanWord($w['word']),
                'start' => round($start, 3),
                'end'   => round($end, 3),
            ];

        }, $words);

        $assContent = $this->buildYoutubeKaraokeStyle($normalized);

        file_put_contents($outputPath, mb_convert_encoding($assContent, 'UTF-8'));

        Log::info("ASS karaoke style berhasil dibuat.", [
            'path' => $outputPath
        ]);
    }

    /**
     * ==========================================
     * BUILD YOUTUBE KARAOKE STYLE
     * ==========================================
     */
    private function buildYoutubeKaraokeStyle(array $words): string
    {
        if (empty($words)) return '';

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

        $groups = $this->splitByDuration($words, 3.0);

        $dialogues = '';

        foreach ($groups as $group) {

            $start = $this->toAssTime($group[0]['start']);
            $end   = $this->toAssTime(end($group)['end']);

            $karaokeText = '';
            $sentenceWords = array_column($group, 'word');

            // Balanced 2 line split
            $lines = $this->splitBalancedLines($sentenceWords);

            foreach ($lines as $lineIndex => $lineWords) {

                foreach ($lineWords as $index => $word) {

                    $wordData = $group[array_search($word, $sentenceWords)];
                    $duration = ($wordData['end'] - $wordData['start']);
                    $centiseconds = max(1, (int)round($duration * 100));

                    $karaokeText .= "{\\k{$centiseconds}}" . $word . " ";
                }

                if ($lineIndex === 0 && count($lines) > 1) {
                    $karaokeText .= "\\N";
                }
            }

            $dialogues .= "Dialogue: 0,{$start},{$end},YT,,0,0,0,,{$karaokeText}\n";
        }

        return $header . $dialogues;
    }

    /**
     * Split per durasi (3 detik)
     */
    private function splitByDuration(array $words, float $maxSeconds): array
    {
        $groups = [];
        $current = [];
        $startTime = $words[0]['start'];

        foreach ($words as $word) {

            if (empty($current)) {
                $startTime = $word['start'];
            }

            $current[] = $word;

            if (($word['end'] - $startTime) >= $maxSeconds) {
                $groups[] = $current;
                $current = [];
            }
        }

        if (!empty($current)) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Split jadi 2 baris seimbang
     */
    private function splitBalancedLines(array $words): array
    {
        if (count($words) <= 6) {
            return [$words];
        }

        $mid = floor(count($words) / 2);

        return [
            array_slice($words, 0, $mid),
            array_slice($words, $mid)
        ];
    }

    
    private function estimateWordTimestamps(string $text, float $start, float $end): array
    {
        $words = explode(' ', trim(preg_replace('/\s+/', ' ', $text)));
        $duration = max(0.1, $end - $start);
        $perWord = $duration / max(count($words), 1);

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
        $cs = floor(($seconds - floor($seconds)) * 100);

        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }

    private function cleanWord(string $word): string
    {
        return trim(str_replace(['{','}','\\'], '', $word));
    }
}