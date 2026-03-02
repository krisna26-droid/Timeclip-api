<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CaptionService
{
    /**
     * Generate file .ASS (Advanced SubStation Alpha) dengan karaoke style.
     * File .ASS mendukung animasi per kata yang tidak bisa dilakukan oleh .SRT/.VTT.
     *
     * @param array  $words      [{word, start, end}, ...] dari Gemini (bisa kosong)
     * @param string $fullText   Teks lengkap sebagai fallback
     * @param float  $clipStart  Waktu mulai clip di video asli (detik)
     * @param float  $clipEnd    Waktu selesai clip di video asli (detik)
     * @param string $outputPath Path lengkap untuk menyimpan file .ass
     */
    public function generateAss(
        array  $words,
        string $fullText,
        float  $clipStart,
        float  $clipEnd,
        string $outputPath
    ): void {
        // Jika words dari Gemini kosong, generate estimasi dari full_text
        if (empty($words)) {
            Log::info("CaptionService: words kosong, generate estimasi dari full_text.");
            $words = $this->estimateWordTimestamps($fullText, $clipStart, $clipEnd);
        }

        // Filter hanya kata-kata yang masuk dalam rentang clip
        $clipWords = array_filter($words, function ($w) use ($clipStart, $clipEnd) {
            return $w['start'] >= $clipStart && $w['end'] <= $clipEnd;
        });

        // Normalisasi waktu: ubah ke relatif dari awal clip (0-based)
        $normalizedWords = array_map(function ($w) use ($clipStart) {
            return [
                'word'  => $w['word'],
                'start' => round($w['start'] - $clipStart, 3),
                'end'   => round($w['end'] - $clipStart, 3),
            ];
        }, array_values($clipWords));

        // Jika setelah filter masih kosong (timestamps Gemini tidak akurat),
        // fallback: estimasi ulang dari full_text dengan durasi clip
        if (empty($normalizedWords)) {
            Log::warning("CaptionService: tidak ada kata dalam rentang clip, fallback estimasi ulang.");
            $clipDuration    = $clipEnd - $clipStart;
            $normalizedWords = $this->estimateWordTimestamps($fullText, 0, $clipDuration);
        }

        $assContent = $this->buildAssContent($normalizedWords);

        file_put_contents($outputPath, $assContent);

        Log::info("CaptionService: ASS file berhasil dibuat.", [
            'path'       => $outputPath,
            'word_count' => count($normalizedWords),
        ]);
    }

    /**
     * Estimasi timestamps per kata secara proporsional berdasarkan panjang kata.
     * Lebih akurat dari pembagian rata karena kata panjang butuh waktu lebih.
     */
    private function estimateWordTimestamps(string $text, float $startTime, float $endTime): array
    {
        // Bersihkan teks
        $text  = preg_replace('/\s+/', ' ', trim($text));
        $words = explode(' ', $text);
        $words = array_filter($words, fn($w) => trim($w) !== '');
        $words = array_values($words);

        if (empty($words)) return [];

        $duration       = $endTime - $startTime;
        $totalCharCount = array_sum(array_map('mb_strlen', $words));

        $result  = [];
        $current = $startTime;

        foreach ($words as $word) {
            $proportion = mb_strlen($word) / max($totalCharCount, 1);
            $wordDur    = $duration * $proportion;
            // Minimum 0.1 detik per kata, maksimum 1.5 detik
            $wordDur    = max(0.1, min(1.5, $wordDur));

            $result[] = [
                'word'  => $word,
                'start' => round($current, 3),
                'end'   => round($current + $wordDur, 3),
            ];

            $current += $wordDur;
        }

        return $result;
    }

    /**
     * Build konten file .ASS dengan style per kata.
     * Setiap kata muncul satu per satu di tengah layar:
     * - Teks: putih, bold, besar
     * - Background: kotak hitam semi-transparan di belakang kata
     * - Muncul dan hilang sesuai durasi kata
     */
    private function buildAssContent(array $words): string
    {
        // =============================================
        // HEADER ASS
        // =============================================
        // PrimaryColour  = warna teks utama (putih)
        // SecondaryColour= warna highlight karaoke (kuning)
        // OutlineColour  = warna outline/border teks (hitam)
        // BackColour     = warna background box (hitam semi-transparan)
        // BorderStyle=3  = background box per kata (bukan garis outline biasa)
        // Alignment=5    = tengah-tengah layar (Middle Center)
        $header = <<<ASS
[Script Info]
ScriptType: v4.00+
PlayResX: 608
PlayResY: 1080
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: WordPop,Arial,72,&H00FFFFFF,&H0000FFFF,&H00000000,&HAA000000,-1,0,0,0,100,100,2,0,3,8,0,5,20,20,0,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text

ASS;

        // =============================================
        // GENERATE: 1 dialogue per kata
        // =============================================
        $dialogues = '';

        foreach ($words as $w) {
            $word = $this->cleanWord($w['word']);
            if ($word === '') continue;

            $start = $this->toAssTime($w['start']);
            $end   = $this->toAssTime($w['end']);

            // {\an5} = anchor tengah layar (override alignment ke center-middle)
            // {\fad(80,80)} = fade in 80ms, fade out 80ms supaya tidak abrupt
            // {\b1} = bold
            $text = "{\\an5}{\\fad(80,80)}{\\b1}" . $word;

            $dialogues .= "Dialogue: 0,{$start},{$end},WordPop,,0,0,0,,{$text}\n";
        }

        return $header . $dialogues;
    }

    /**
     * Format detik ke format ASS time: H:MM:SS.CC
     */
    private function toAssTime(float $seconds): string
    {
        $h  = (int) ($seconds / 3600);
        $m  = (int) (($seconds % 3600) / 60);
        $s  = (int) ($seconds % 60);
        $cs = (int) round(fmod($seconds, 1) * 100);

        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }

    /**
     * Bersihkan kata dari karakter yang bisa break ASS format.
     */
    private function cleanWord(string $word): string
    {
        // Hapus karakter yang bisa break ASS: { } \
        $word = str_replace(['{', '}', '\\'], '', $word);
        // Hapus emoji
        $word = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $word);
        return trim($word);
    }
}