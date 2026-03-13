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

            // MENGGUNAKAN LOGIC BARU: Build dengan deteksi jeda diam agar fokus ke suara manusia
            $assContent = $this->buildSmartKaraokeStyle($normalized);

            file_put_contents(
                $outputPath,
                mb_convert_encoding($assContent, 'UTF-8')
            );

            Log::info("ASS karaoke berhasil dibuat.", [
                'path' => $outputPath
            ]);

            SystemLog::create([
                'service'  => 'FFMPEG',
                'level'    => 'INFO',
                'category' => 'RENDER',
                'user_id'  => Auth::id(),
                'message'  => "File ASS karaoke berhasil dibuat dengan sinkronisasi vokal.",
                'payload'  => ['output_path' => basename($outputPath), 'word_count' => count($normalized)]
            ]);
        } catch (\Throwable $e) {
            Log::error("Gagal generate ASS: " . $e->getMessage());
            throw $e;
        }
    }

    private function buildSmartKaraokeStyle(array $words): string
    {
        if (empty($words)) return '';

        // PlayRes: 720x1280 (Standard Portrait)
        // Style: Primary & Secondary Putih (&H00FFFFFF), Outline Hitam tebal (3), Shadow (1)
        $header = <<<ASS
[Script Info]
ScriptType: v4.00+
PlayResX: 720
PlayResY: 1280
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: YT,Arial,65,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00333333,-1,0,0,0,100,100,0,0,1,3,1,2,70,70,220,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text

ASS;

        // Grouping berdasarkan jeda diam (Silence Detection)
        $groups = $this->groupWordsBySilence($words, 0.35, 5);
        $dialogues = '';

        foreach ($groups as $group) {
            if (empty($group)) continue;

            $start = $this->toAssTime($group[0]['start']);
            $end   = $this->toAssTime(end($group)['end']);

            $karaokeText = '';
            $currentPos = $group[0]['start'];

            foreach ($group as $wordData) {
                // Tambahkan tag jeda jika ada gap waktu antar kata dalam satu baris
                $gap = $wordData['start'] - $currentPos;
                if ($gap > 0.01) {
                    $gapCs = (int) round($gap * 100);
                    $karaokeText .= "{\\k{$gapCs}}";
                }

                $duration = $wordData['end'] - $wordData['start'];
                $centiseconds = max(1, (int) round($duration * 100));

                // Karaoke tetap putih bersih
                $karaokeText .= "{\\k{$centiseconds}}" . $wordData['word'] . " ";
                $currentPos = $wordData['end'];
            }

            $dialogues .= "Dialogue: 0,{$start},{$end},YT,,0,0,0,,{$karaokeText}\n";
        }

        return $header . $dialogues;
    }

    /**
     * Membagi kata ke baris baru jika ada jeda diam pembicara
     */
    private function groupWordsBySilence(array $words, float $maxSilence, int $maxWords): array
    {
        $groups = [];
        $currentGroup = [];

        foreach ($words as $word) {
            if (empty($currentGroup)) {
                $currentGroup[] = $word;
                continue;
            }

            $prevWord = end($currentGroup);
            $silence = $word['start'] - $prevWord['end'];

            // Jika diam > 0.35s atau kata sudah > 5, buat baris baru (layar kosong saat diam)
            if ($silence > $maxSilence || count($currentGroup) >= $maxWords) {
                $groups[] = $currentGroup;
                $currentGroup = [$word];
            } else {
                $currentGroup[] = $word;
            }
        }

        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    private function estimateWordTimestamps(string $text, float $start, float $end): array
    {
        $wordsArray = explode(' ', trim(preg_replace('/\s+/', ' ', $text)));
        if (empty($wordsArray)) return [];

        $duration = max(0.1, $end - $start);
        $perWord = $duration / count($wordsArray);

        $current = $start;
        $result = [];

        foreach ($wordsArray as $word) {
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

        if ($cs >= 100) {
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
