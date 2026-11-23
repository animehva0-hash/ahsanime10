<?php

declare(strict_types=1);

/**
 * Shiro resolver (https://shiro.so).
 *
 * POST https://shiro.so/api/episode  { anilistId, malId, episode }
 *   → { status: "ready", variants: [{ id, label, sources: [...] }] }
 *
 * variant.id  "sub" | "dub" | "hsub"
 * source      { id, label, url, type, tracks: [] }
 *   type  "application/vnd.apple.mpegurl" → multi-quality master
 *         "video/mp4"                      → progressive single file
 *   url    origin-relative "/stream/{token}/index.m3u8" | "/file"
 *   track  { id, src, type: "vtt", label, language, default }
 *
 * Streams are same-origin on shiro.so and need no special Referer. English VTT
 * ships alongside the video, so the Gemini translate pipeline works as-is.
 */

const SHIRO_ORIGIN = 'https://shiro.so';
const SHIRO_EPISODE_API = 'https://shiro.so/api/episode';
const SHIRO_REFERER = 'https://shiro.so/';

/** Turn an origin-relative "/stream/…" into an absolute shiro.so URL. */
function shiro_absolute(string $raw): string
{
    if (str_starts_with($raw, 'http')) return $raw;
    return SHIRO_ORIGIN . (str_starts_with($raw, '/') ? $raw : '/' . $raw);
}

function shiro_variant_type(string $id): string
{
    $v = strtolower($id);
    if ($v === 'dub') return 'dub';
    if ($v === 'hsub' || $v === 'hardsub' || $v === 'hard') return 'hsub';
    return 'sub';
}

function shiro_is_playable(string $url): bool
{
    return (bool) preg_match('/\.(m3u8)(\?|$)/i', $url) || (bool) preg_match('/\/file(\?|$)/i', $url);
}

/**
 * POST the episode API. Returns the decoded response array or null.
 */
function shiro_post_episode(int $anilistId, int $ep, int $timeoutSec = 12): ?array
{
    $body = json_encode(['anilistId' => $anilistId, 'malId' => null, 'episode' => $ep]);
    $headers = [
        'User-Agent' => STREAM_UA,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Origin' => SHIRO_ORIGIN,
        'Referer' => SHIRO_REFERER,
    ];
    try {
        $res = stream_http_request('POST', SHIRO_EPISODE_API, $headers, $body, $timeoutSec);
    } catch (Throwable $e) {
        return null;
    }
    if ($res['status'] < 200 || $res['status'] >= 300) return null;
    $json = json_decode($res['body'], true);
    return is_array($json) ? $json : null;
}

/**
 * Resolve playable servers for an episode.
 * Returns a list of ['name', 'type', 'hls', 'vtt'=>[['label','url']...], 'referer'].
 */
function shiro_resolve(int $anilistId, int $ep): array
{
    $key = stream_key('shiro', (string) $anilistId, (string) $ep);
    [$cached, $age] = cache_file_get($key);
    // Fresh positive cache (25 min) / short negative cache (5 min).
    if ($cached !== null && $age !== null) {
        $ttl = !empty($cached['servers']) ? 1500 : 300;
        if ($age < $ttl) return $cached['servers'];
    }

    $json = shiro_post_episode($anilistId, $ep);
    if ($json === null || ($json['status'] ?? null) !== 'ready' || !isset($json['variants']) || !is_array($json['variants'])) {
        cache_file_put($key, ['servers' => []], 300);
        return [];
    }

    $out = [];
    foreach ($json['variants'] as $variant) {
        $type = shiro_variant_type((string) ($variant['id'] ?? 'sub'));
        $sources = isset($variant['sources']) && is_array($variant['sources']) ? $variant['sources'] : [];

        // HLS first, then progressive MP4 — keeps the quality menu meaningful.
        usort($sources, static function ($a, $b) {
            $ah = preg_match('/mpegurl|m3u8/i', ($a['type'] ?? '') . ' ' . ($a['url'] ?? '')) ? 0 : 1;
            $bh = preg_match('/mpegurl|m3u8/i', ($b['type'] ?? '') . ' ' . ($b['url'] ?? '')) ? 0 : 1;
            return $ah - $bh;
        });

        foreach ($sources as $source) {
            if (empty($source['url'])) continue;
            $url = shiro_absolute((string) $source['url']);
            if (!shiro_is_playable($url)) continue;

            $tracks = [];
            foreach (($source['tracks'] ?? []) as $t) {
                if (empty($t['src'])) continue;
                if (!preg_match('/vtt/i', (string) ($t['type'] ?? 'vtt'))) continue;
                $tracks[] = [
                    'label' => $t['label'] ?: ($t['language'] ?: 'English'),
                    'url' => shiro_absolute((string) $t['src']),
                ];
            }
            // English first so the translate bar picks it up automatically.
            usort($tracks, static fn($a, $b) => (preg_match('/eng/i', $a['label']) ? 0 : 1) <=> (preg_match('/eng/i', $b['label']) ? 0 : 1));

            $out[] = [
                'name' => trim((string) ($source['label'] ?? '')) ?: 'Shiro',
                'type' => $type,
                'hls' => $url,
                'vtt' => $tracks,
                'referer' => SHIRO_REFERER,
            ];
        }
    }

    cache_file_put($key, ['servers' => $out], !empty($out) ? 1500 : 300);
    return $out;
}