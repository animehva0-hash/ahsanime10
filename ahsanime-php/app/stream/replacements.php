<?php

declare(strict_types=1);

/**
 * Post-processing rules applied to translated subtitle text.
 *
 * replacements_lines.txt  -> full-line corrections ("lhs => rhs"; if any cue
 *                            line contains lhs, the whole line becomes rhs)
 * replacements_words.txt  -> whole-word corrections (case-insensitive,
 *                            word-boundary matched)
 *
 * Files live alongside this app (ahsanime-php/). Missing files are fine —
 * rules become no-ops. Loaded once per request and cached in a static.
 */

/** @return array{lines: array<int,array{0:string,1:string}>, words: array<int,string>, wordTo: array<int,string>} */
function replacements_rules(): array
{
    static $rules = null;
    if ($rules !== null) return $rules;

    $read = static function (string $file): array {
        $path = __DIR__ . '/../../' . $file; // app/stream/../../ = ahsanime-php/
        if (!is_file($path)) return [];
        $raw = (string) @file_get_contents($path);
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
            $idx = preg_split('/\s*=>+\s*/', $trimmed);
            if (count($idx) >= 2 && $idx[0] !== '' && $idx[1] !== '') {
                $out[] = [$idx[0], implode(' => ', array_slice($idx, 1))];
            }
        }
        return $out;
    };

    $lines = $read('replacements_lines.txt');
    $wordPairs = $read('replacements_words.txt');
    $words = [];
    $wordTo = [];
    foreach ($wordPairs as [$w, $r]) {
        $words[] = '/(^|[\s.,!?…—–-])(' . preg_quote($w, '/') . ')(?=[\s.,!?…—–-]|$)/iu';
        $wordTo[] = $r;
    }

    $rules = ['lines' => $lines, 'words' => $words, 'wordTo' => $wordTo];
    return $rules;
}

/** Apply line replacements first, then whole-word replacements. */
function apply_replacements(string $text): string
{
    $rules = replacements_rules();
    $out = $text;

    foreach ($rules['lines'] as [$lhs, $rhs]) {
        $needle = strtolower($lhs);
        $lines = preg_split('/\n/', $out);
        foreach ($lines as $i => $line) {
            if (str_contains(strtolower($line), $needle)) $lines[$i] = $rhs;
        }
        $out = implode("\n", $lines);
    }

    foreach ($rules['words'] as $i => $re) {
        $out = preg_replace($re, '${1}' . $rules['wordTo'][$i], $out);
    }

    return $out;
}

/**
 * Last-resort safety net: strip any English words that survived Gemini's
 * translation. Removes standalone [a-zA-Z]+ words bounded by whitespace or
 * line edges, then collapses the whitespace they leave behind. Numbers and
 * the target language's script are untouched.
 */
function strip_remaining_english(string $text): string
{
    $lines = preg_split('/\n/', $text);
    foreach ($lines as $i => $line) {
        $line = preg_replace('/(?:^|\s)[a-zA-Z]+(?=[\s.,!?…—–:;-]|$)/u', ' ', $line);
        $line = preg_replace('/\s+/u', ' ', $line);
        $lines[$i] = trim($line);
    }
    return implode("\n", $lines);
}