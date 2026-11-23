<?php

declare(strict_types=1);

/**
 * shonen.to resolver.
 *
 * Chain:
 *   GET /sources/shonen?id={anilistId}&episode={n}&language={sub|dub}
 *     → { data: { source, sources: [{file, type, label, tracks:[{file,label,kind}], intro, outro}] } }
 *
 * Returns direct HLS + VTT tracks (English default). Dub may be absent
 * (SOURCE_NOT_FOUND) for some titles/episodes.
 */

const SHONEN_BASE = 'https://shonen.to';

/**
 * Resolve shonen.to servers for an episode.
 * Returns list of ['name','type','hls','vtt'=>[['label','url']],'referer'].
 */
function shonen_resolve(int $anilistId, int $ep): array
{
    $key = stream_key('shonen', (string) $anilistId, (string) $ep);
    [$cached, $age] = cache_file_get($key);
    if ($cached !== null && $age !== null) {
        $ttl = !empty($cached['servers']) ? 1500 : 300;
        if ($age < $ttl) return $cached['servers'];
    }

    $out = [];
    foreach (['sub', 'dub'] as $language) {
        $path = "/sources/shonen?id={$anilistId}&episode={$ep}&language={$language}";
        try {
            $res = stream_http_request_retry('GET', SHONEN_BASE . $path, [
                'User-Agent' => STREAM_UA,
                'Accept' => 'application/json',
                'Referer' => SHONEN_BASE . '/watch',
            ], null, 15);
        } catch (Throwable $e) {
            continue;
        }
        if ($res['status'] < 200 || $res['status'] >= 300) continue;
        $data = json_decode($res['body'], true);
        $sources = $data['data']['sources'] ?? [];
        if (!is_array($sources) || !$sources) continue;

        $primary = $data['data']['source'] ?? null;
        foreach ($sources as $i => $s) {
            $file = $s['file'] ?? null;
            $hls = $file ?: $primary;
            if (!$hls) continue;

            $tracks = [];
            foreach (($s['tracks'] ?? []) as $t) {
                if (!empty($t['file'])) {
                    $tracks[] = [
                        'label' => $t['label'] ?? 'English',
                        'url' => proxy_absolute($t['file'], SHONEN_BASE),
                    ];
                }
            }

            $out[] = [
                'name' => $s['label'] ?? ($i === 0 ? 'Primary' : 'Secondary ' . ($i + 1)),
                'type' => $language,
                'hls' => proxy_absolute($hls, SHONEN_BASE),
                'vtt' => $tracks,
                'referer' => SHONEN_BASE . '/',
            ];
        }
    }

    cache_file_put($key, ['servers' => $out], !empty($out) ? 1500 : 300);
    return $out;
}