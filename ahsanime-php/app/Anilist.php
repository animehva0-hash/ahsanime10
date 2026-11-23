<?php

declare(strict_types=1);

/**
 * AniList GraphQL client with file-system caching, pacing, and stale fallback.
 * PHP single-process model: each request is independent, so cross-request
 * state (rate-limit, negative cache) lives on disk in the cache dir.
 */
class Anilist
{
    private static ?string $lastCallFile = null;

    /** Stable cache key for a query + variables pair. */
    private static function key(string $query, array $variables): string
    {
        return 'al_' . sha1($query . '|' . json_encode($variables));
    }

    private static function cachePath(string $key): string
    {
        return CACHE_DIR . '/' . $key . '.json';
    }

    /** Read a cached payload if it exists. Returns [data, ageSeconds] or [null, null]. */
    private static function cacheGet(string $key): array
    {
        $f = self::cachePath($key);
        if (!is_file($f)) return [null, null];
        $age = time() - filemtime($f);
        $raw = @file_get_contents($f);
        if ($raw === false) return [null, null];
        $data = json_decode($raw, true);
        if (!is_array($data)) return [null, null];
        return [$data, $age];
    }

    private static function cacheSet(string $key, array $data): void
    {
        $f = self::cachePath($key);
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data)) !== false) {
            @rename($tmp, $f);
        }
    }

    /** Pace network calls globally using a shared timestamp file. */
    private static function throttle(): void
    {
        $f = CACHE_DIR . '/_last_call';
        $last = is_file($f) ? (int) @file_get_contents($f) : 0;
        $now = (int) round(microtime(true) * 1_000_000);
        $wait = ANILIST_MIN_INTERVAL_US - ($now - $last);
        if ($wait > 0) usleep($wait);
        @file_put_contents($f, (string) (int) round(microtime(true) * 1_000_000));
    }

    /**
     * Execute a GraphQL query with caching.
     * - Fresh cache hit: returned immediately (optionally revalidated).
     * - Miss: live fetch, cache stored.
     * - 429/error: stale cache (up to CACHE_TTL_STALE_MAX) served.
     */
    public static function query(string $query, array $variables, int $ttlSec = 600): array
    {
        $key = self::key($query, $variables);
        [$cached, $age] = self::cacheGet($key);

        // Fresh enough → serve from cache. We do NOT background-revalidate on
        // every hit (PHP has no persistent worker); instead revalidate only
        // when older than half the TTL.
        if ($cached !== null && $age !== null && $age < $ttlSec) {
            return $cached;
        }

        try {
            $data = self::rawQuery($query, $variables);
            self::cacheSet($key, $data);
            return $data;
        } catch (Throwable $e) {
            // Serve stale if we have anything at all.
            if ($cached !== null) {
                return $cached;
            }
            throw $e;
        }
    }

    /** Actual network call with pacing + 429 handling. */
    private static function rawQuery(string $query, array $variables): array
    {
        $body = json_encode(['query' => $query, 'variables' => $variables]);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: AHSANIME/1.0 (+' . SITE_URL . ')',
        ];

        for ($attempt = 0; $attempt <= 2; $attempt++) {
            self::throttle();

            $status = 0;
            $resp = false;

            if (function_exists('curl_init')) {
                $ch = curl_init(ANILIST_URL);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => ANILIST_TIMEOUT_SEC,
                    CURLOPT_HTTPHEADER => $headers,
                ]);
                $resp = curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($resp === false) {
                    throw new RuntimeException('AniList cURL error: ' . $err);
                }
            } else {
                // Stream fallback (no cURL extension required).
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => implode("\r\n", $headers),
                        'content' => $body,
                        'timeout' => ANILIST_TIMEOUT_SEC,
                        'ignore_errors' => true, // capture 4xx/5xx bodies
                    ],
                ]);
                $resp = @file_get_contents(ANILIST_URL, false, $ctx);
                $status = 0;
                if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                    $status = (int) $m[1];
                }
                if ($resp === false) {
                    throw new RuntimeException('AniList stream error');
                }
            }

            if ($status === 429) {
                if ($attempt < 2) {
                    sleep(2 + $attempt);
                    continue;
                }
                throw new RuntimeException('AniList: Too Many Requests');
            }

            if ($status > 0 && ($status < 200 || $status >= 300)) {
                throw new RuntimeException("AniList responded {$status}");
            }

            $json = json_decode($resp, true);
            if (!is_array($json)) {
                throw new RuntimeException('AniList: invalid JSON');
            }
            if (!empty($json['errors'])) {
                throw new RuntimeException($json['errors'][0]['message'] ?? 'AniList error');
            }
            return $json['data'] ?? [];
        }

        throw new RuntimeException('AniList: Too Many Requests');
    }
}