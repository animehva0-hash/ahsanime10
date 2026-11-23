<?php

declare(strict_types=1);

/**
 * Subtitle translation endpoint logic (port of src/app/api/stream/subtitle/translate/route.ts).
 *
 * handle_translate(array $sp): array  — $sp is the query-string params.
 * Returns ['status'=>int, 'headers'=>array<string,string>, 'body'=>string].
 */

/** Apply post-translation cleanup (replacements + English stripping) per language. */
function translate_polish(array $cues, string $langCode): array
{
    return array_map(static function ($c) use ($langCode) {
        $text = $langCode === 'hinglish'
            ? apply_replacements($c['text'])
            : strip_remaining_english(apply_replacements($c['text']));
        $c['text'] = vtt_normalise_glyphs($text);
        $c['translated'] = true;
        return $c;
    }, $cues);
}

function vtt_response(string $vtt): array
{
    return ['status' => 200, 'headers' => ['Content-Type' => 'text/vtt; charset=utf-8', 'Cache-Control' => 'no-store'], 'body' => $vtt];
}

function json_response(array $payload, int $status, array $extraHeaders = []): array
{
    return [
        'status' => $status,
        'headers' => array_merge(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], $extraHeaders),
        'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * @param array<string,mixed> $sp  query-string params (url, lang, animeId, ep, sessionId, t0, t1)
 */
function handle_translate(array $sp): array
{
    $url = (string) ($sp['url'] ?? '');
    $lang = (string) ($sp['lang'] ?? '');
    $animeId = (string) ($sp['animeId'] ?? '');
    $ep = (string) ($sp['ep'] ?? '');
    $sessionId = (string) ($sp['sessionId'] ?? '');

    if ($url === '' || $lang === '' || $animeId === '' || $ep === '' || $sessionId === '') {
        return json_response(['ok' => false, 'error' => 'url, lang, animeId, ep and sessionId are required'], 400);
    }
    if (language_for($lang) === null) {
        return json_response(['ok' => false, 'error' => "Unknown language: {$lang}"], 400);
    }

    // Validate the subtitle URL before any upstream work.
    // PHP already URL-decodes $_GET once, so we must NOT decodeURIComponent again
    // (that would corrupt inner-encoded tokens like the shonen zoko `u=` base64,
    // which legitimately contains %3D / %2F).
    try {
        [$scheme] = http_assert_safe_url($url);
        if ($scheme !== 'https' && $scheme !== 'http') throw new InvalidArgumentException();
    } catch (Throwable $e) {
        return json_response(['ok' => false, 'error' => 'Invalid subtitle URL'], 400);
    }

    if (!is_gemini_configured()) {
        return json_response([
            'ok' => false,
            'code' => 'not_configured',
            'provider' => translate_provider(),
            'model' => translate_model(),
            'configured' => false,
            'error' => 'Translation is not configured (missing GOOGLE_TRANSLATE_API_KEY). Original English captions remain available.',
        ], 503);
    }

    $decodedUrl = $url;
    $hasWindow = isset($sp['t0'], $sp['t1']);
    $t0 = $hasWindow ? (float) $sp['t0'] : 0.0;
    $t1 = $hasWindow ? (float) $sp['t1'] : INF;

    // 1) Session cache (full episode) — fastest for repeat views.
    $sessionCached = session_get($sessionId, $animeId, $ep, $lang);
    if ($sessionCached !== null && isset($sessionCached['vtt'])) {
        if (!$hasWindow) return vtt_response($sessionCached['vtt']);
        $slice = array_values(array_filter(vtt_parse($sessionCached['vtt']), static fn($c) => $c['start'] >= $t0 - 0.001 && $c['start'] < $t1));
        if ($slice) return vtt_response(vtt_to_vtt($slice));
    }

    // 2) Global cross-session cache (accumulating per url+lang).
    $global = tc_get($decodedUrl, $lang);
    if ($global !== null && !empty($global['full']) && !empty($global['cues'])) {
        $vttFull = vtt_to_vtt($global['cues']);
        session_put($sessionId, $animeId, $ep, ['vtt' => $vttFull, 'lang' => $lang, 'sourceUrl' => $decodedUrl]);
        if (!$hasWindow) return vtt_response($vttFull);
        $slice = array_values(array_filter($global['cues'], static fn($c) => $c['start'] >= $t0 - 0.001 && $c['start'] < $t1));
        if ($slice) return vtt_response(vtt_to_vtt($slice));
    }
    if ($hasWindow) {
        $win = tc_get_window($decodedUrl, $lang, $t0, $t1);
        if ($win !== null) return vtt_response(vtt_to_vtt($win));
    }

    // Per-IP abuse guard only when real translation work is needed.
    // Progressive windowed playback sends multiple chunk requests → higher burst.
    [$rlOk, $rlReset] = rate_allow('tr:' . stream_client_ip(), $hasWindow ? 90 : 20, 60_000);
    if (!$rlOk) {
        return json_response(
            ['ok' => false, 'code' => 'rate_limited', 'error' => 'Too many translations — try again shortly.'],
            429,
            ['Retry-After' => (string) max(2, (int) ceil($rlReset / 1000))],
        );
    }

    try {
        $ref = (string) ($sp['ref'] ?? '');
        $fetched = fetch_subtitle_cues($decodedUrl, $ref);
        $safeCues = vtt_merge_overlapping($fetched['cues']);

        if ($hasWindow) {
            $target = array_values(array_filter($fetched['cues'], static fn($c) => $c['start'] >= $t0 - 0.001 && $c['start'] < $t1));
            $toTranslate = $target ?: array_values(array_filter($safeCues, static fn($c) => $c['start'] >= $t0 - 0.001 && $c['start'] < $t1));
            if (!$toTranslate) return vtt_response("WEBVTT\n\n");
            $translated = gemini_translate_cues($toTranslate, $lang);
            $polished = translate_polish($translated, $lang);
            tc_put($decodedUrl, $lang, $polished, false);
            return vtt_response(vtt_to_vtt($polished));
        }

        if (!$safeCues) return vtt_response("WEBVTT\n\n");
        $translated = gemini_translate_cues($safeCues, $lang);
        $polished = translate_polish($translated, $lang);
        tc_put($decodedUrl, $lang, $polished, true);
        $vttText = vtt_to_vtt($polished);
        session_put($sessionId, $animeId, $ep, ['vtt' => $vttText, 'lang' => $lang, 'sourceUrl' => $decodedUrl]);
        return vtt_response($vttText);
    } catch (Throwable $e) {
        $isTE = $e instanceof TranslationError;
        $isFE = $e instanceof SubtitleFetchError;
        $status = $isTE ? $e->httpStatus : ($isFE ? $e->httpStatus : 500);
        $code = $isTE ? $e->errorCode : 'fetch_failed';
        return json_response([
            'ok' => false,
            'provider' => translate_provider(),
            'model' => translate_model(),
            'code' => $code,
            'error' => $e->getMessage(),
        ], $status);
    }
}