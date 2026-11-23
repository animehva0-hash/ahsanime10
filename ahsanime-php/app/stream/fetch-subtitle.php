<?php

declare(strict_types=1);

/**
 * Fetch a remote subtitle (VTT/SRT/ASS) and normalize to VTT cues.
 * Cached on disk for 30 minutes to make repeated translations cheap.
 */

class SubtitleFetchError extends RuntimeException
{
    public int $httpStatus;
    public function __construct(string $message, int $status = 502)
    {
        parent::__construct($message);
        $this->httpStatus = $status;
    }
}

const SUBTITLE_MAX_BYTES = 5 * 1024 * 1024;

/**
 * Fetch subtitle cues for a URL.
 * Returns ['vtt' => string, 'cues' => array].
 *
 * @param string $refererOverride  optional explicit Referer (e.g. provider's own)
 */
function fetch_subtitle_cues(string $rawUrl, string $refererOverride = ''): array
{
    // Resolve origin-relative URLs against the default referer's origin.
    if (str_starts_with($rawUrl, '/')) {
        $resolvedUrl = (string) parse_url(http_default_referer(), PHP_URL_SCHEME)
            . '://' . parse_url(http_default_referer(), PHP_URL_HOST) . $rawUrl;
    } else {
        $resolvedUrl = $rawUrl;
    }

    $cacheKey = stream_key('sub', $resolvedUrl);
    [$cached, $age] = cache_file_get($cacheKey);
    if ($cached !== null && $age !== null && $age < 1800 && isset($cached['vtt'], $cached['cues'])) {
        return ['vtt' => $cached['vtt'], 'cues' => $cached['cues']];
    }

    [$scheme, $host, $path, $query] = http_assert_safe_url($resolvedUrl);
    $url = $scheme . '://' . $host . $path . $query;
    $referer = $refererOverride !== '' ? $refererOverride : http_referer_for($host);

    $ua = STREAM_UA;
    $accept = 'text/vtt, subtitle/srt, application/x-subrip, text/plain, */*';

    // First attempt with the host-specific referer; on 403, retry with the
    // default referer, then with no referer at all.
    $attempts = [$referer, http_default_referer(), ''];
    $res = null;
    foreach ($attempts as $ref) {
        try {
            $headers = ['User-Agent' => $ua, 'Accept' => $accept];
            if ($ref !== '') {
                $headers['Referer'] = $ref;
                $headers['Origin'] = (string) parse_url($ref, PHP_URL_SCHEME) . '://' . parse_url($ref, PHP_URL_HOST);
            }
            $res = stream_http_request('GET', $url, $headers, null, 20);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                // 403 → try the next referer; other errors → stop.
                if ($res['status'] === 403 && $ref !== end($attempts)) continue;
                throw new SubtitleFetchError("Subtitle source responded {$res['status']}", 502);
            }
            break;
        } catch (SubtitleFetchError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SubtitleFetchError('Could not reach subtitle host: ' . $e->getMessage());
        }
    }

    if ($res === null || $res['status'] < 200 || $res['status'] >= 300) {
        throw new SubtitleFetchError('Could not reach subtitle host');
    }

    $bytes = strlen($res['body']);
    if ($bytes === 0) throw new SubtitleFetchError('Empty subtitle file', 422);
    if ($bytes > SUBTITLE_MAX_BYTES) throw new SubtitleFetchError('Subtitle file too large', 413);

    $vtt = vtt_normalize($res['body']);
    $cues = vtt_parse($vtt);
    if (!$cues) throw new SubtitleFetchError('No subtitle cues found', 422);

    cache_file_put($cacheKey, ['vtt' => $vtt, 'cues' => $cues], 1800);
    return ['vtt' => $vtt, 'cues' => $cues];
}