<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;

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

            // LOG UNTUK ADMIN: Peringatan bahwa AI tidak memberikan timestamp kata per kata
            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'WARNING',
                'category' => 'RENDER',
                'user_id'  => Auth::id(),
                'message'  => "Timestamp kata kosong, menggunakan estimasi otomatis.",
                'payload'  => ['text_length' => strlen($fullText)]
            ]);

            $words = $this->estimateWordTimestamps($fullText, $clipStart, $clipEnd);
        }

        if (empty($words)) {
            Log::warning("Tidak ada words untuk dibuat ASS.");
            return;
        }

        $clipDuration = max(0, $clipEnd - $clipStart);
        $normalized = [];

        try {
            foreach ($words as $w) {
                if (!isset($w['start'], $w['end'], $w['word'])) {
                    continue;
                }

                $start = max(0, $w['start'] - $clipStart);
                $end   = max(0, $w['end'] - $clipStart);

                if ($end > $clipDuration) $end = $clipDuration;
                if ($end <= $start) $end = min($clipDuration, $start + 0.1);

                $normalized[] = [
                    'word'  => $this->cleanWord($w['word']),
                    'start' => round($start, 3),
                    'end'   => round($end, 3),
                ];
            }

            if (empty($normalized)) {
                Log::warning("Normalized words kosong.");
                return;
            }

            $assContent = $this->buildYoutubeKaraokeStyle($normalized);

            file_put_contents(
                $outputPath,
                mb_convert_encoding($assContent, 'UTF-8')
            );

            Log::info("ASS karaoke berhasil dibuat.", [
                'path' => $outputPath
            ]);

            // LOG UNTUK ADMIN: Berhasil render subtitle (Audit Trail)
            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'INFO',
                'category' => 'RENDER',
                'user_id'  => Auth::id(),
                'message'  => "File ASS karaoke berhasil dibuat.",
                'payload'  => ['output_path' => basename($outputPath), 'word_count' => count($normalized)]
            ]);
        } catch (\Throwable $e) {
            Log::error("Gagal generate ASS: " . $e->getMessage());

            // LOG UNTUK ADMIN: Error fatal saat render
            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'ERROR',
                'category' => 'RENDER',
                'user_id'  => Auth::id(),
                'message'  => "Gagal membuat file subtitle ASS: " . $e->getMessage(),
                'payload'  => ['trace' => substr($e->getTraceAsString(), 0, 500)]
            ]);

            throw $e;
        }
    }

    /**
     * ==========================================
     * BUILD YOUTUBE KARAOKE STYLE (FIXED)
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
            if (empty($group)) continue;

            $start = $this->toAssTime($group[0]['start']);
            $end   = $this->toAssTime(end($group)['end']);

            $karaokeText = '';
            $lines = $this->splitBalancedLines($group);

            foreach ($lines as $lineIndex => $lineWords) {
                foreach ($lineWords as $i => $wordData) {
                    $duration = $wordData['end'] - $wordData['start'];
                    $centiseconds = max(1, (int) round($duration * 100));
                    $karaokeText .= "{\\k{$centiseconds}}" . $wordData['word'] . " ";
                }

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
        if (empty($words)) return [];

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

    private function splitBalancedLines(array $group): array
    {
        $count = count($group);
        if ($count <= 6) return [$group];
        $mid = floor($count / 2);

        return [
            array_slice($group, 0, $mid),
            array_slice($group, $mid)
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
        $fraction = $seconds - floor($seconds);
        $cs = (int) round($fraction * 100);

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
