<?php

declare(strict_types=1);

/* ------------------------------ mbstring shims --------------------------- */
// Work whether or not the mbstring extension is installed.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $enc = null): int { return strlen($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null, ?string $enc = null): string
    {
        return $len === null ? substr($s, $start) : substr($s, $start, $len);
    }
}

/* ------------------------------ escaping --------------------------------- */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------ cn / labels ------------------------------- */

function cn(array $parts): string
{
    return implode(' ', array_filter($parts, static fn($p) => (bool) $p));
}

function scoreLabel($score): ?string
{
    if (!$score) return null;
    return number_format($score / 10, 1);
}

function statusLabel(?string $status): string
{
    switch ($status) {
        case 'RELEASING': return 'Airing';
        case 'FINISHED': return 'Finished';
        case 'NOT_YET_RELEASED': return 'Coming Soon';
        case 'CANCELLED': return 'Cancelled';
        case 'HIATUS': return 'Hiatus';
        default: return 'Unknown';
    }
}

function formatLabel(?string $format): string
{
    if (!$format) return 'Unknown';
    if ($format === 'TV_SHORT') return 'TV Short';
    return str_replace('_', ' ', $format);
}

/* --------------------------- description cleaning ------------------------- */

function cleanDescription(?string $desc, int $max = 0): string
{
    if (!$desc) return '';
    $text = preg_replace('/<br\s*\/?>/i', "\n", $desc);
    $text = preg_replace('/<i>([\s\S]*?)<\/i>/i', '$1', $text);
    $text = preg_replace('/<b>([\s\S]*?)<\/b>/i', '$1', $text);
    $text = preg_replace('/<[^>]+>/', '', $text);
    $text = preg_replace('/~![\s\S]*?!~/', '[spoiler]', $text);
    $text = str_replace(['&amp;', '&apos;', '&quot;'], ['&', "'", '"'], $text);
    $text = trim($text);
    if ($max > 0 && mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max);
        $text = preg_replace('/\s+\S*$/', '', $text) . '…';
    }
    return $text;
}

/* -------------------------------- numbers --------------------------------- */

function dateLabel(?array $d): string
{
    if (!$d || empty($d['year'])) return 'TBA';
    $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $m = !empty($d['month']) ? ($months[$d['month'] - 1] ?? null) : null;
    if ($m) {
        $s = $m . ' ' . ($d['day'] ?? '') . ' ' . $d['year'];
        return trim(preg_replace('/\s+/', ' ', $s));
    }
    return (string) $d['year'];
}

function seasonLabel(?string $season, $year): string
{
    if (!$season) return $year ? (string) $year : 'TBA';
    return trim(ucfirst(strtolower($season)) . ' ' . ($year ?? ''));
}

function compactNumber($n): string
{
    if (!$n) return '0';
    if ($n >= 1000000) return number_format($n / 1000000, 1) . 'M';
    if ($n >= 1000) return number_format($n / 1000, 1) . 'K';
    return (string) $n;
}

/* -------------------------------- titles ---------------------------------- */

function pickTitle(?array $title, string $lang = 'en'): string
{
    if (!$title) return 'Unknown';
    $s = $lang === 'jp'
        ? ($title['romaji'] ?: $title['english'])
        : ($title['english'] ?: $title['romaji']);
    return $s ?? 'Unknown';
}

/** Display title with romaji + english fallback, like <TitleText>. */
function displayTitle(?array $title): string
{
    if (!$title) return 'Unknown';
    return $title['english'] ?: ($title['romaji'] ?: ($title['native'] ?: 'Unknown'));
}

/* ------------------------------ episodes ---------------------------------- */

function episodeTotal(array $m): int
{
    $episodes = $m['episodes'] ?? null;
    $next = $m['nextAiringEpisode'] ?? null;
    if ($next) {
        $aired = max(0, ($next['episode'] ?? 0) - 1);
        return ($episodes && $episodes > 0) ? min($aired, $episodes) : $aired;
    }
    if (($m['status'] ?? null) === 'NOT_YET_RELEASED') return 0;
    if ($episodes && $episodes > 0) return $episodes;
    return 0;
}

/* ------------------------------- seasons ---------------------------------- */

function seasonFor(?int $ts = null): array
{
    $ts = $ts ?? time();
    $m = (int) date('n', $ts); // 1-12
    $year = (int) date('Y', $ts);
    if ($m <= 2) return ['season' => 'WINTER', 'year' => $year];
    if ($m <= 5) return ['season' => 'SPRING', 'year' => $year];
    if ($m <= 8) return ['season' => 'SUMMER', 'year' => $year];
    return ['season' => 'FALL', 'year' => $year];
}

/* ------------------------------ url helpers ------------------------------- */

function cover(?array $m): ?string
{
    return $m['coverImage']['extraLarge'] ?? $m['coverImage']['large'] ?? null;
}

function epath(string $path): string
{
    return e($path);
}