<?php

declare(strict_types=1);

/**
 * Animo / 4animo resolver (the site's own "AHSANIME" provider).
 *
 * Flow (per server + language):
 *   1. GET https://cdn.4animo.xyz/embed/{server}/ani/{anilistId}/{ep}/{sub|dub}
 *        → 301 redirect to the live origin (currently novuscloud.buzz). We follow
 *          manually to learn the FINAL origin, since the embed token is origin-bound.
 *   2. HTML contains `sourcesUrl = '/stream/getSources?t=...'`.
 *   3. GET {finalOrigin}{sourcesUrl} with X-Requested-With: XMLHttpRequest
 *        → { sources:[{file:'/p?t=...',type:'hls'}], tracks:[{file,label,kind}] }
 *   4. HLS + tracks are relative to the final origin.
 *
 * The embed token is single-use, so steps 1-3 run in one fresh pass (cached result).
 */

const ANIMO_EMBED_ORIGIN = 'https://cdn.4animo.xyz';
const ANIMO_SERVERS = ['hd-1', 'hd-2', 'hd-4'];

/**
 * Resolve Animo servers for an episode.
 * Returns list of ['name','type','hls','vtt'=>[['label','url']],'referer'].
 */
function animo_resolve(int $anilistId, int $ep, bool $dub = false): array
{
    $key = stream_key('animo', (string) $anilistId, (string) $ep, $dub ? 'dub' : 'sub');
    [$cached, $age] = cache_file_get($key);
    if ($cached !== null && $age !== null) {
        $ttl = !empty($cached['servers']) ? 1500 : 300;
        if ($age < $ttl) return $cached['servers'];
    }

    $languages = $dub ? ['dub'] : ['sub'];
    $out = [];

    foreach (ANIMO_SERVERS as $server) {
        foreach ($languages as $language) {
            $embedUrl = ANIMO_EMBED_ORIGIN . "/embed/{$server}/ani/{$anilistId}/{$ep}/{$language}";

            try {
                $emb = http_request_followed('GET', $embedUrl, [
                    'User-Agent' => STREAM_UA,
                    'Accept' => 'text/html',
                    'Referer' => 'https://indanime.co/',
                ], null, 15);
            } catch (Throwable $e) {
                continue;
            }
            if ($emb['status'] < 200 || $emb['status'] >= 300) continue;

            $origin = http_origin($emb['url']);
            if ($origin === '') continue;

            $html = $emb['body'];
            if (!preg_match("#sourcesUrl\s*=\s*['\"]([^'\"]+)['\"]#i", $html, $m)) continue;
            $sourcesPath = $m[1];

            try {
                $src = stream_http_request_retry('GET', $origin . $sourcesPath, [
                    'User-Agent' => STREAM_UA,
                    'Accept' => 'application/json',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $origin . embed_url_path($embedUrl),
                ], null, 15);
            } catch (Throwable $e) {
                continue;
            }
            if ($src['status'] < 200 || $src['status'] >= 300) continue;

            $data = json_decode($src['body'], true);
            $sources = $data['sources'] ?? [];
            if (!is_array($sources) || !$sources) continue;

            $tracks = [];
            foreach (($data['tracks'] ?? []) as $t) {
                $tf = $t['file'] ?? null;
                if (!$tf) continue;
                $tracks[] = [
                    'label' => $t['label'] ?? 'English',
                    'url' => proxy_absolute($tf, $origin),
                ];
            }

            foreach ($sources as $i => $s) {
                $file = $s['file'] ?? null;
                if (!$file) continue;
                $out[] = [
                    'name' => $i === 0 ? ($server === 'hd-1' ? 'HD 1' : strtoupper($server)) : ($server . ' #' . ($i + 1)),
                    'type' => $language,
                    'hls' => proxy_absolute($file, $origin),
                    'vtt' => $tracks,
                    'referer' => $origin . '/',
                ];
            }
        }
    }

    cache_file_put($key, ['servers' => $out], !empty($out) ? 1500 : 300);
    return $out;
}

/** Path portion of a URL (e.g. "/embed/hd-1/ani/21/1/sub"). */
function embed_url_path(string $url): string
{
    $p = parse_url($url, PHP_URL_PATH);
    return is_string($p) ? $p : '/';
}

/** Scheme+host[:port] origin of a URL (e.g. "https://novuscloud.buzz"). */
function http_origin(string $url): string
{
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT);
    if (!$scheme || !$host) return '';
    $origin = $scheme . '://' . $host;
    if ($port) $origin .= ':' . $port;
    return $origin;
}