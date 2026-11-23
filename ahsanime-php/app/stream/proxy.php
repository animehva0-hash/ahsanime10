<?php

declare(strict_types=1);

/**
 * Media proxy for HLS playback (port of src/app/api/stream/proxy/route.ts).
 *
 * handle_proxy(array $sp, ?string $range): array
 *   $sp = ['url'=>string, 'ref'=>string]
 * Returns ['status'=>int, 'headers'=>array<string,string>, 'body'=>string].
 *
 * - .m3u8 → fetched with the correct Referer, rewritten so every URI
 *   (variants, audio/subtitle playlists, keys, segments) routes back here.
 * - segments/keys → fetched and served with corrected content-type; providers
 *   mislabeling MPEG-TS as image/jpeg are detected via the 0x47 TS sync byte.
 *
 * Note: segments are fetched per-request (small). Shiro serves HLS first, so
 * progressive MP4 is a fallback path only.
 */

function proxy_url(string $path, string $absolute, string $ref): string
{
    return $path . '?url=' . urlencode($absolute) . '&ref=' . urlencode($ref);
}

/**
 * @param array{url?:string,ref?:string} $sp
 */
function handle_proxy(array $sp, ?string $range): array
{
    [$ok, $reset] = rate_allow('px:' . stream_client_ip(), 240, 60_000);
    if (!$ok) {
        return json_response(['ok' => false, 'error' => 'rate limited'], 429, ['Retry-After' => (string) max(1, (int) ceil($reset / 1000))]);
    }

    $raw = (string) ($sp['url'] ?? '');
    $inheritedRef = (string) ($sp['ref'] ?? '');

    // Resolve origin-relative child URLs against the inherited referer's origin.
    if (str_starts_with($raw, '/')) {
        $baseOrigin = http_default_referer();
        if ($inheritedRef !== '' && parse_url($inheritedRef, PHP_URL_SCHEME)) {
            $baseOrigin = parse_url($inheritedRef, PHP_URL_SCHEME) . '://' . parse_url($inheritedRef, PHP_URL_HOST);
        }
        $raw = rtrim($baseOrigin, '/') . $raw;
    }

    try {
        [$scheme, $host, $path, $query] = http_assert_safe_url($raw);
    } catch (Throwable $e) {
        return json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }

    $url = $scheme . '://' . $host . $path . $query;
    $effectiveRef = $inheritedRef !== '' ? $inheritedRef : (http_referer_for($host) ?: http_default_referer());

    $headers = http_upstream_headers($effectiveRef, $range);

    // Direct first (fast path). If the deployed server's IP is geo-blocked
    // (common on shared hosts), fall back to a few free HTTP proxies.
    $upstream = null;
    try {
        $upstream = stream_http_request('GET', $url, $headers, null, 10);
    } catch (Throwable $e) {
        $upstream = null;
    }
    if (!$upstream || $upstream['status'] < 200 || $upstream['status'] >= 300) {
        if (function_exists('proxy_pool_get')) {
            foreach (array_slice(proxy_pool_get(6), 0, 2) as $proxy) {
                try {
                    $upstream = stream_http_request('GET', $url, $headers, null, 5, true, $proxy);
                    if ($upstream && $upstream['status'] >= 200 && $upstream['status'] < 300) break;
                } catch (Throwable $e) {
                    $upstream = null;
                }
            }
        }
    }
    if (!$upstream) {
        return json_response(['ok' => false, 'error' => 'upstream unreachable'], 502);
    }
    if ($upstream['status'] < 200 || $upstream['status'] >= 300) {
        return json_response(['ok' => false, 'error' => "upstream {$upstream['status']}"], 502);
    }

    $ctype = strtolower($upstream['headers']['content-type'] ?? '');
    $looksPlaylist = str_contains($ctype, 'mpegurl') || (bool) preg_match('/\.m3u8($|\?)/i', $path . $query);

    if ($looksPlaylist) {
        $base = $scheme . '://' . $host . $path . $query;
        $lines = preg_split('/\n/', $upstream['body']);
        $rewritten = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                if (str_starts_with($trimmed, '#') && str_contains($trimmed, 'URI=')) {
                    $line = preg_replace_callback('/URI="([^"]+)"/', static function ($m) use ($base, $effectiveRef) {
                        try {
                            $abs = proxy_absolute((string) $m[1], $base);
                            return 'URI="' . proxy_url('/api/stream/proxy', $abs, $effectiveRef) . '"';
                        } catch (Throwable $e) {
                            return $m[0];
                        }
                    }, $line);
                }
                $rewritten[] = $line;
                continue;
            }
            try {
                $rewritten[] = proxy_url('/api/stream/proxy', proxy_absolute($trimmed, $base), $effectiveRef);
            } catch (Throwable $e) {
                $rewritten[] = $line;
            }
        }
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/vnd.apple.mpegurl', 'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'],
            'body' => implode("\n", $rewritten),
        ];
    }

    // Progressive MP4: pass the upstream body through.
    $looksMp4 = str_contains($ctype, 'video/mp4') || (bool) preg_match('/\.mp4($|\?)/i', $path . $query);
    if ($looksMp4) {
        $out = [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'video/mp4',
                'Access-Control-Allow-Origin' => '*',
                'Accept-Ranges' => $upstream['headers']['accept-ranges'] ?? 'bytes',
                'Cache-Control' => 'public, max-age=300',
            ],
            'body' => $upstream['body'],
        ];
        if (!empty($upstream['headers']['content-length'])) $out['headers']['Content-Length'] = $upstream['headers']['content-length'];
        if (!empty($upstream['headers']['content-range'])) $out['headers']['Content-Range'] = $upstream['headers']['content-range'];
        return $out;
    }

    // MPEG-TS sync-byte detection (0x47 every 188 bytes).
    $buf = $upstream['body'];
    $len = strlen($buf);
    $isTs = $len > 376 && ord($buf[0]) === 0x47 && ord($buf[188]) === 0x47 && ord($buf[376]) === 0x47;
    $contentType = $isTs
        ? 'video/mp2t'
        : (str_contains($ctype, 'image/') && preg_match('/\.(ts|m2ts)($|\?)/i', $path) ? 'video/mp2t' : ($ctype ?: 'application/octet-stream'));

    return [
        'status' => 200,
        'headers' => ['Content-Type' => $contentType, 'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300'],
        'body' => $buf,
    ];
}

/** Resolve a possibly-relative URI against a base URL string. */
function proxy_absolute(string $uri, string $base): string
{
    if (preg_match('#^https?://#i', $uri)) return $uri;
    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    if (str_starts_with($uri, '//')) return $scheme . ':' . $uri;
    if (str_starts_with($uri, '/')) return "{$scheme}://{$host}{$uri}";
    $dir = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
    return "{$scheme}://{$host}{$dir}{$uri}";
}