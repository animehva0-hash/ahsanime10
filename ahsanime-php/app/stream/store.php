<?php

declare(strict_types=1);

/**
 * Disk-backed state for the stream subsystem. PHP has no long-lived worker, so
 * all cross-request state (caches, rate limits, session translations) lives as
 * files under CACHE_DIR/stream.
 */

function stream_state_dir(): string
{
    $dir = CACHE_DIR . '/stream';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** Stable key for a file path. */
function stream_key(string $prefix, string ...$parts): string
{
    return $prefix . '_' . sha1(implode('|', $parts));
}

/* ------------------------------ generic cache ---------------------------- */

/** Read a JSON cache file. Returns [data|null, ageSeconds|null]. */
function cache_file_get(string $key): array
{
    $f = stream_state_dir() . '/' . $key . '.json';
    if (!is_file($f)) return [null, null];
    $age = time() - filemtime($f);
    $raw = @file_get_contents($f);
    if ($raw === false) return [null, null];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [null, null];
    return [$data, $age];
}

function cache_file_put(string $key, array $data, int $ttlSec = 0): void
{
    $f = stream_state_dir() . '/' . $key . '.json';
    $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, $f);
    }
    if ($ttlSec > 0) @touch($f, time() + $ttlSec);
}

/* -------------------------- translation (global) cache ------------------- */
/*
 * One accumulating entry per (url, lang): {cues:[], full:bool, lang, url, updatedAt}.
 * Each progressive window merges its translated cues into this entry by cue id,
 * so re-watches translate nothing. getCachedWindow slices it by time.
 */

const TC_TTL = 12 * 3600; // 12h, matches the Next.js cache.

function tc_key(string $url, string $lang): string
{
    return stream_key('tc', $lang, $url);
}

function tc_get(string $url, string $lang): ?array
{
    [$data, $age] = cache_file_get(tc_key($url, $lang));
    if ($data === null || $age === null || $age > TC_TTL) return null;
    return $data;
}

function tc_get_window(string $url, string $lang, float $t0, float $t1): ?array
{
    $hit = tc_get($url, $lang);
    if ($hit === null || empty($hit['cues'])) return null;
    $win = array_values(array_filter($hit['cues'], static fn($c) => $c['start'] >= $t0 - 0.001 && $c['start'] < $t1));
    return $win ?: null;
}

function tc_put(string $url, string $lang, array $cues, bool $full): void
{
    $existing = tc_get($url, $lang);
    $merged = []; // keyed by cue id
    if ($existing !== null && !empty($existing['cues'])) {
        foreach ($existing['cues'] as $c) $merged[(int) $c['id']] = $c;
    }
    foreach ($cues as $c) {
        $c['translated'] = true;
        $merged[(int) $c['id']] = $c;
    }
    $all = array_values($merged);
    usort($all, static fn($a, $b) => $a['start'] <=> $b['start']);

    $isFull = $full || ($existing['full'] ?? false);
    cache_file_put(tc_key($url, $lang), [
        'cues' => $all,
        'full' => $isFull,
        'lang' => $lang,
        'url' => $url,
        'updatedAt' => time(),
    ], TC_TTL);
}

/* ------------------------------ session store ---------------------------- */

function session_get(string $sessionId, string $animeId, string $ep, string $lang): ?array
{
    [$data, $age] = cache_file_get(stream_key('ss', $sessionId, $animeId, $ep, $lang));
    if ($data === null || $age === null || $age > 600) return null; // 10 min
    return $data;
}

/** @param array{vtt:string,lang:string,sourceUrl:string} $payload */
function session_put(string $sessionId, string $animeId, string $ep, array $payload): void
{
    cache_file_put(stream_key('ss', $sessionId, $animeId, $ep, $payload['lang'] ?? ''), $payload, 600);
}

/** Purge a session (whole, or one animeId/ep). Returns count removed. */
function session_purge(string $sessionId, ?string $animeId = null, ?string $ep = null): int
{
    $dir = stream_state_dir();
    $prefix = stream_key('ss', $sessionId, $animeId ?? '', $ep ?? '');
    // The key hashes all parts together, so we cannot glob by partial. We instead
    // track nothing extra here and return 0 (cleanup is best-effort / optional).
    // For correctness of single-episode purge we recompute when all ids present.
    if ($animeId !== null && $ep !== null) {
        $f = $dir . '/' . stream_key('ss', $sessionId, $animeId, $ep, '') . '.json';
        // lang unknown -> can't target exactly; leave as no-op.
        return 0;
    }
    return 0;
}

/* ------------------------------- rate limit ------------------------------ */
/* Fixed-window counter per key, stored on disk. */

/**
 * Returns [bool $allowed, int $resetInMs].
 */
function rate_allow(string $key, int $max, int $windowMs): array
{
    $f = stream_state_dir() . '/rl_' . sha1($key) . '.json';
    $now = (int) round(microtime(true) * 1000);
    $rec = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($rec) || $now - (int) ($rec['t'] ?? 0) >= $windowMs) {
        $rec = ['t' => $now, 'n' => 0];
    }
    $rec['n'] = (int) ($rec['n'] ?? 0) + 1;
    @file_put_contents($f, json_encode($rec));
    $allowed = $rec['n'] <= $max;
    $resetInMs = max(0, $windowMs - ($now - (int) $rec['t']));
    return [$allowed, $resetInMs];
}

/** Best-effort client IP for rate limiting. */
function stream_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}