<?php

declare(strict_types=1);

/**
 * Optional upstream proxy pool backed by proxifly's free-proxy-list.
 *
 * IMPORTANT: this is ONLY a fallback for small resolver/metadata JSON fetches
 * when a direct request fails (e.g. a provider temporarily blocks this
 * server's IP). It is intentionally NOT used for video segments/playlists —
 * free proxies are slow and short-lived and would wreck playback.
 *
 * PHP here has no curl and no SOCKS support in the stream wrapper, so only the
 * HTTP proxy subset is usable. Most entries are unreliable; callers should try
 * only a handful and treat the whole thing as best-effort.
 */

const PROXY_POOL_URL =
    'https://cdn.jsdelivr.net/gh/proxifly/free-proxy-list@main/proxies/protocols/http/data.txt';
const PROXY_POOL_TTL = 600; // 10 minutes

/**
 * Return a small cached list of "ip:port" HTTP proxies (most-dead, best-effort).
 *
 * @return string[]
 */
function proxy_pool_get(int $limit = 8): array
{
    $cacheKey = 'proxypool:http:' . $limit;
    [$cached, $age] = cache_file_get($cacheKey);
    // Only trust a non-empty cached pool (never serve a poisoned empty list).
    if (!empty($cached) && $age !== null && $age < PROXY_POOL_TTL) {
        return $cached;
    }

    try {
        $res = stream_http_request('GET', PROXY_POOL_URL, [
            'User-Agent' => STREAM_UA,
            'Accept' => 'text/plain',
        ], null, 12);
    } catch (Throwable $e) {
        return $cached ?? [];
    }
    if ($res['status'] < 200 || $res['status'] >= 300) {
        return $cached ?? [];
    }

    $lines = preg_split('/\r?\n/', $res['body']) ?: [];
    $pool = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // Entries look like "http://1.2.3.4:8080" — strip the scheme.
        $line = preg_replace('#^[a-z]+://#i', '', $line);
        // Only accept host:port (skip anything with a leftover path).
        if (!preg_match('#^\d{1,3}(?:\.\d{1,3}){3}:\d{1,5}$#', $line)) continue;
        $pool[] = $line;
        if (count($pool) >= $limit) break;
    }

    // Shuffle so we don't always hammer the same (likely-dead) head entries.
    if ($pool) {
        shuffle($pool);
        cache_file_put($cacheKey, $pool, PROXY_POOL_TTL);
    }
    return $pool;
}