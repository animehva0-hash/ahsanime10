<?php

declare(strict_types=1);

/**
 * Shared HTTP transport + host policies for the stream subsystem.
 *
 * The host runs PHP without cURL or mbstring, so every request goes through
 * PHP's stream (fopen) wrapper with an optional cURL fast path when present.
 * HTTPS works via the openssl extension.
 */

const STREAM_UA =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

/**
 * Host-specific Referer policies (hotlink-protected CDNs).
 * Order matters — first match wins.
 */
function http_referer_policies(): array
{
    static $policies = null;
    if ($policies !== null) return $policies;

    $policies = [
        ['/hls2\.aniwatchtv\.uk/i', 'https://zokoanime.video/'],
        ['/animegg\.org|vidcache\.net/i', 'https://www.animegg.org/'],
        ['/akage\.lol/i', 'https://anikage.cc/'],
        ['/watching\.onl/i', 'https://megaplay.buzz/'],
        ['/kryntal\.top|megap\./i', 'https://megaplay.buzz/'],
        ['/akirax\.buzz|shiora\.top|mikora\.top|vidtub/i', 'https://vidtube.site/'],
        ['/shiro\.so/i', 'https://shiro.so/'],
        ['/anichan\.to|toroplay\.cymru/i', 'https://anichan.to/'],
        ['/vibevibe\.workers\.dev|bibiemb\.xyz/i', 'https://bibiemb.xyz/'],
        ['/cdn-centaurus\.com|retailservices\.shop|otakuhg\.site/i', 'https://otakuhg.site/'],
        ['/anizara\.store/i', 'https://www3.anikai.cc/'],
        ['/echovideo|burntburst/i', 'https://play2.echovideo.ru/'],
        ['/nexabloom\.top|imgnex\.top|ncdn\./i', 'https://megaplay.buzz/'],
        ['/shonen\.to|stream\.shonen\.to/i', 'https://shonen.to/'],
        ['/anikoto\.cz|anikoto\.cat/i', 'https://anikoto.cz/'],
        ['/4animo\.xyz/i', 'https://indanime.co/'],
        ['/novuscloud\.buzz/i', 'https://novuscloud.buzz/'],
        ['/anigato\.lol/i', 'https://anigato.lol/'],
        ['/zokoanime\.(xyz|com|fun|live|net|io)/i', 'https://hianime.at/'],
        ['/dramahot\.top/i', 'https://zokoanime.video/'],
    ];
    return $policies;
}

/** Segment CDNs rotate hostnames, so default to the MegaPlay referer. */
function http_default_referer(): string
{
    return 'https://megaplay.buzz/';
}

function http_referer_for(string $host): string
{
    foreach (http_referer_policies() as [$re, $ref]) {
        if (preg_match($re, $host)) return $ref;
    }
    return http_default_referer();
}

/**
 * Validate a URL and reject private/local hosts (SSRF guard).
 * Returns [scheme, host, path, query] or throws InvalidArgumentException.
 */
function http_assert_safe_url(string $raw): array
{
    $parts = parse_url($raw);
    if ($parts === false || empty($parts['scheme'])) {
        throw new InvalidArgumentException('Invalid URL');
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        throw new InvalidArgumentException('Unsupported protocol');
    }
    $host = strtolower($parts['host'] ?? '');
    if (
        $host === '' ||
        $host === 'localhost' ||
        $host === '127.0.0.1' ||
        $host === '0.0.0.0' ||
        str_starts_with($host, '192.168.') ||
        str_starts_with($host, '10.') ||
        str_ends_with($host, '.internal')
    ) {
        throw new InvalidArgumentException('Host not allowed');
    }
    return [
        $scheme,
        $host,
        $parts['path'] ?? '/',
        isset($parts['query']) ? ('?' . $parts['query']) : '',
    ];
}

/**
 * Low-level HTTP request. Returns ['status'=>int, 'headers'=>array, 'body'=>string].
 * Binary-safe body. Uses cURL when available, otherwise the stream wrapper.
 *
 * @param array<string,string> $headers
 * @param bool $follow  follow redirects automatically (default true)
 * @param string|null $proxy optional "ip:port" HTTP proxy (resolver fallback only)
 */
function stream_http_request(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    int $timeout = 20,
    bool $follow = true,
    ?string $proxy = null,
): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headerList = [];
        foreach ($headers as $k => $v) $headerList[] = $k . ': ' . $v;
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders) {
                if (preg_match('#^([^:]+):\s*(.*)\r?$#', $line, $m)) {
                    $respHeaders[strtolower(trim($m[1]))] = trim($m[2]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('HTTP request failed');
        }
        // Free/shared hosts often cap PHP memory at 64MB. A large TS segment or
        // MP4 chunk can blow that budget if we hold the whole thing in RAM, so
        // refuse anything over 16MB rather than OOMing the worker.
        if (strlen($resp) > 16 * 1024 * 1024) {
            throw new RuntimeException('upstream body too large (>' . (16 * 1024 * 1024) . ' bytes)');
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $resp];
    }

    // Stream fallback.
    $headerLines = [];
    foreach ($headers as $k => $v) $headerLines[] = $k . ': ' . $v;
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'timeout' => $timeout,
            'ignore_errors' => true,      // capture 4xx/5xx bodies
            'follow_location' => $follow ? 1 : 0,
            'max_redirects' => 5,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
    if ($body !== null) $opts['http']['content'] = $body;
    if ($proxy) {
        // Stream wrapper HTTP proxy (deck: "ip:port" or "tcp://ip:port").
        $opts['http']['proxy'] = str_contains($proxy, '://') ? $proxy : 'tcp://' . $proxy;
        $opts['http']['request_fulluri'] = true;
    }

    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    // file_get_contents() populates $http_response_header in this scope.
    $seen = $http_response_header ?? [];
    if ($resp === false) {
        throw new RuntimeException('HTTP request failed');
    }
    // Free/shared hosts often cap PHP memory at 64MB. A large TS segment or
    // MP4 chunk can blow that budget if we hold the whole thing in RAM, so
    // refuse anything over 16MB rather than OOMing the worker.
    if (strlen($resp) > 16 * 1024 * 1024) {
        throw new RuntimeException('upstream body too large (>' . (16 * 1024 * 1024) . ' bytes)');
    }
    $status = 0;
    $respHeaders = [];
    foreach ($seen as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (preg_match('#^([^:]+):\s*(.*)$#', $line, $m)) {
            $respHeaders[strtolower(trim($m[1]))] = trim($m[2]);
        }
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => $resp];
}

/**
 * Resolver/metadata fetch with optional proxy fallback.
 *
 * Tries the request directly first. If it throws or returns a 5xx, retries via a
 * few free HTTP proxies from the proxifly pool (best-effort). This exists only
 * for small JSON/metadata endpoints that providers may temporarily IP-block —
 * never route video segments through here.
 *
 * @param array<string,string> $headers
 * @return array{status:int,headers:array,body:string}
 */
function stream_http_request_retry(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    int $timeout = 15,
    int $proxyTries = 3,
): array {
    try {
        $res = stream_http_request($method, $url, $headers, $body, 8);
        if ($res['status'] < 500) return $res; // 2xx/3xx/4xx: direct answer stands
        $last = $res;
    } catch (Throwable $e) {
        $last = ['status' => 0, 'headers' => [], 'body' => ''];
    }

    if (!function_exists('proxy_pool_get')) return $last;

    foreach (array_slice(proxy_pool_get(6), 0, 2) as $proxy) {
        try {
            $res = stream_http_request($method, $url, $headers, $body, 5, true, $proxy);
            if ($res['status'] >= 200 && $res['status'] < 400) return $res;
        } catch (Throwable $e) {
            continue; // dead proxy, try next
        }
    }
    return $last;
}

/**
 * Manually follow redirects (up to $maxRedirects) and return the FINAL url,
 * status, headers and body. Needed when the caller must know the post-redirect
 * origin (e.g. to resolve a relative token URL against the right host).
 *
 * @param array<string,string> $headers
 * @return array{url:string,status:int,headers:array,body:string}
 */
function http_request_followed(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    int $timeout = 20,
    int $maxRedirects = 5,
): array {
    $current = $url;
    for ($i = 0; $i <= $maxRedirects; $i++) {
        $res = stream_http_request($method, $current, $headers, $body, $timeout, false);
        $status = $res['status'];
        if (($status >= 300 && $status < 400) && !empty($res['headers']['location'])) {
            $loc = $res['headers']['location'];
            $current = proxy_absolute($loc, $current);
            continue;
        }
        $res['url'] = $current;
        return $res;
    }
    $res['url'] = $current;
    return $res;
}

/** Build the standard upstream request headers with the right Referer/Origin. */
function http_upstream_headers(string $referer, ?string $range = null, array $extra = []): array
{
    $h = [
        'User-Agent' => STREAM_UA,
        'Accept' => '*/*',
        'Referer' => $referer,
    ];
    // NOTE: do NOT auto-inject an Origin header. Some CDNs (e.g. shonen.to /
    // dramaht.top) reject requests with 403 when Origin is present, while still
    // requiring a valid Referer. Providers that genuinely need Origin pass it
    // explicitly via $extra.
    if ($range) $h['Range'] = $range;
    // Never let forwarded headers override Referer/Origin.
    foreach ($extra as $k => $v) {
        if (strcasecmp($k, 'referer') === 0 || strcasecmp($k, 'origin') === 0) continue;
        $h[$k] = $v;
    }
    return $h;
}