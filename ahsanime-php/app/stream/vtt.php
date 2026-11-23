<?php

declare(strict_types=1);

/**
 * Subtitle parsing / serialization utilities.
 * Accepts WebVTT, SubRip (.srt) and basic ASS dialogue; always emits valid VTT.
 *
 * A cue is represented as an associative array:
 *   ['id' => int, 'start' => float, 'end' => float, 'text' => string, 'translated' => bool]
 */

/** "00:00:01.000" | "00:01.000" | SRT "00:00:01,000" -> seconds */
function vtt_parse_timestamp(string $raw): float
{
    $t = str_replace(',', '.', trim($raw));
    $parts = explode(':', $t);
    if (count($parts) === 3) {
        return (float) $parts[0] * 3600 + (float) $parts[1] * 60 + (float) $parts[2];
    }
    if (count($parts) === 2) {
        return (float) $parts[0] * 60 + (float) $parts[1];
    }
    return (float) $t;
}

function vtt_format_timestamp(float $sec, bool $withHours = true): string
{
    $total = max(0.0, $sec);
    $h = (int) floor($total / 3600);
    $m = (int) floor((fmod($total, 3600)) / 60);
    $s = number_format((float) fmod($total, 60), 3, '.', '');
    $s = str_pad($s, 6, '0', STR_PAD_LEFT);
    $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    return $withHours ? sprintf('%02d:%s:%s', $h, $mm, $s) : "{$mm}:{$s}";
}

/** Strip ASS override tags {\...}, HTML-ish tags and styles from a line. */
function vtt_strip_tags(string $line): string
{
    $line = preg_replace('/\{[^}]*\}/', '', $line);
    $line = preg_replace('/<[^>]+>/', '', $line);
    $line = preg_replace('/\\\\N/i', ' ', $line);
    $line = preg_replace('/\\\\h/i', ' ', $line);
    $line = preg_replace('/\s+/', ' ', $line);
    return trim($line);
}

/**
 * Normalise typographic glyphs that render inconsistently across caption
 * pipelines (Gemini often emits smart quotes / fullwidth forms).
 */
function vtt_normalise_glyphs(string $text): string
{
    // Smart quotes / apostrophes -> ASCII, dashes, ellipsis, fullwidth punctuation.
    $map = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201B}" => "'", "\u{2032}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201F}" => '"', "\u{2033}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{2026}" => '...',
        "\u{FF01}" => '!', "\u{FF1F}" => '?', "\u{FF0C}" => ',', "\u{FF1B}" => ';', "\u{FF1A}" => ':',
    ];
    $text = str_replace(array_keys($map), array_values($map), $text);
    // Strip zero-width / BOM / C0 control chars (keep tab/newline/CR).
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '', $text);
    // Collapse doubled spaces created by the removals above.
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    return trim($text);
}

/**
 * Parse any supported subtitle text into a list of cues.
 */
function vtt_parse(string $raw): array
{
    $text = preg_replace('/^\x{FEFF}/u', '', $raw);
    if (str_contains($text, '[Events]')) return vtt_parse_ass($text);

    $cues = [];
    $blocks = preg_split('/\r?\n\s*\r?\n/', $text);
    $id = 0;
    foreach ($blocks as $block) {
        $lines = preg_split('/\r?\n/', $block);
        $lines = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
        if (!$lines) continue;
        if (preg_match('/^WEBVTT/i', $lines[0]) || preg_match('/^NOTE/', $lines[0]) || preg_match('/^STYLE/', $lines[0])) continue;

        $timingIdx = -1;
        foreach ($lines as $i => $l) {
            if (preg_match('/(\d{1,2}:)?\d{1,2}:\d{2}[.,]\d{1,3}\s*-->\s*(\d{1,2}:)?\d{1,2}:\d{2}[.,]\d{1,3}/', $l)) {
                $timingIdx = $i;
                break;
            }
        }
        if ($timingIdx === -1) continue;

        $timing = $lines[$timingIdx];
        $m = preg_split('/\s*-->\s*/', $timing);
        if (count($m) < 2) continue;
        $start = vtt_parse_timestamp($m[0]);
        // Strip position/align settings from the end stamp.
        $endParts = preg_split('/\s/', trim($m[1]));
        $end = vtt_parse_timestamp($endParts[0]);

        $bodyParts = array_slice($lines, $timingIdx + 1);
        $bodyLines = array_map('vtt_strip_tags', $bodyParts);
        $bodyLines = array_values(array_filter($bodyLines, static fn($l) => $l !== ''));
        $body = implode("\n", $bodyLines);
        if ($body === '' || $end <= $start) continue;
        $cues[] = ['id' => ++$id, 'start' => $start, 'end' => $end, 'text' => $body];
    }
    return $cues;
}

/** Basic ASS -> cues (Dialogue events only). */
function vtt_parse_ass(string $text): array
{
    $eventsPos = strpos($text, '[Events]');
    $events = $eventsPos === false ? '' : substr($text, $eventsPos + strlen('[Events]'));
    $fields = ['start', 'end', 'text'];
    if (preg_match('/^\s*Format:\s*(.+)$/im', $events, $fm)) {
        $fields = array_map('trim', explode(',', $fm[1]));
        $fields = array_map('strtolower', $fields);
    }
    $iStart = array_search('start', $fields, true);
    $iEnd = array_search('end', $fields, true);
    $iText = array_search('text', $fields, true);
    if ($iText === false) $iText = count($fields) - 1;

    $cues = [];
    $id = 0;
    foreach (preg_split('/\r?\n/', $events) as $line) {
        if (!preg_match('/^\s*Dialogue:/i', $line)) continue;
        $payload = preg_replace('/^\s*Dialogue:\s*/i', '', $line);
        $parts = explode(',', $payload);
        if (count($parts) <= max($iStart, $iEnd)) continue;
        // text field takes the remainder (commas allowed)
        $start = vtt_parse_timestamp($parts[$iStart] ?? '');
        $end = vtt_parse_timestamp($parts[$iEnd] ?? '');
        $textRaw = implode(',', array_slice($parts, $iText));
        $body = vtt_strip_tags(preg_replace('/\\\\[Nnh]/', ' ', $textRaw));
        if ($body === '' || $end <= $start) continue;
        $cues[] = ['id' => ++$id, 'start' => $start, 'end' => $end, 'text' => $body];
    }
    return $cues;
}

/**
 * Timing-safety pass for translation: merge cues that share (almost) the same
 * window so one cue occupies each time slot.
 */
function vtt_merge_overlapping(array $cues, float $tolerance = 0.15): array
{
    if (count($cues) < 2) return $cues;
    usort($cues, static fn($a, $b) => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end']);

    $out = [];
    foreach ($cues as $cue) {
        if ($out) {
            $prev = &$out[count($out) - 1];
            $sameSlot =
                abs($prev['start'] - $cue['start']) <= $tolerance &&
                abs($prev['end'] - $cue['end']) <= $tolerance;
            if ($sameSlot) {
                if (!str_contains($prev['text'], $cue['text'])) $prev['text'] .= "\n" . $cue['text'];
                $prev['end'] = max($prev['end'], $cue['end']);
                unset($prev);
                continue;
            }
            unset($prev);
        }
        $out[] = $cue;
    }

    // Never let a cue overlap the next one's start.
    for ($i = 0; $i < count($out) - 1; $i++) {
        if ($out[$i]['end'] > $out[$i + 1]['start']) $out[$i]['end'] = $out[$i + 1]['start'];
    }

    $res = [];
    $nid = 0;
    foreach ($out as $c) {
        if ($c['end'] > $c['start']) {
            $c['id'] = ++$nid;
            $res[] = $c;
        }
    }
    return $res;
}

/** Shift every cue's start/end by $delta seconds (clamped to >= 0). */
function vtt_shift(array $cues, float $delta): array
{
    if ($delta == 0.0) return $cues;
    return array_map(static function ($c) use ($delta) {
        $c['start'] = max(0.0, $c['start'] + $delta);
        $c['end'] = max(0.0, $c['end'] + $delta);
        return $c;
    }, $cues);
}

function vtt_to_vtt(array $cues): string
{
    $out = "WEBVTT\n\n";
    $blocks = [];
    foreach ($cues as $c) {
        $blocks[] = $c['id'] . "\n"
            . vtt_format_timestamp($c['start']) . ' --> ' . vtt_format_timestamp($c['end'])
            . "\n" . $c['text'] . "\n";
    }
    return $out . implode("\n", $blocks);
}

/** Detect + normalise any subtitle text to valid VTT. */
function vtt_normalize(string $raw): string
{
    return vtt_to_vtt(vtt_parse($raw));
}