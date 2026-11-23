<?php

declare(strict_types=1);

/**
 * Gemini (AI Studio) structured subtitle translation.
 *
 * Env:
 *   GOOGLE_TRANSLATE_API_KEY   - AI Studio key, read server-side only
 *   GEMINI_TRANSLATE_MODEL     - defaults to gemini-3.5-flash-lite
 *
 * - Structured JSON output (responseSchema) — no numbered-line parsing issues
 * - Batches of cues per request to keep completions precise
 * - 408/429/5xx retried up to 3 total attempts with 3s / 6s / 9s backoff
 * - Model fallback chain on 404
 */

class TranslationError extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;
    /** @param 'not_configured'|'provider_error'|'rate_limited'|'bad_response' $code */
    public function __construct(string $message, string $code, int $status = 500)
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->httpStatus = $status;
    }
}

/** Build the Gemini system prompt for a target language. */
function gemini_system_prompt(array $lang): string
{
    $base = [
        'You are a professional subtitle translation engine for anime dialogue.',
        "Translate every subtitle cue from English to {$lang['label']}" . (!empty($lang['native']) && $lang['native'] !== $lang['label'] ? " ({$lang['native']})" : '') . '.',
        'Rules:',
        '- You receive a JSON array: [{"id": number, "text": string}, ...].',
        '- Return a JSON array with the SAME ids in the SAME order, with "text" translated.',
        '- Preserve meaning, tone, emotion and punctuation style.',
        '- Transliterate character names, fantasy terms, attack names and Japanese honorifics (-san, -kun, -chan, -sama, -sensei, -senpai) into the target script. Never leave names or terms in English.',
        '- Translate every English word into the target language. Transliterate names, places and proper nouns into the target script. Keep punctuation and numbers as-is. Do not leave English words untranslated, but never drop or empty a cue.',
        '- LINE STRUCTURE IS CRITICAL: if the source text has N lines, return EXACTLY N lines.',
        '- Use a single \\n between lines. Never merge two lines into one, never split one line into two.',
        '- Keep each line under 42 characters so nothing is cut off on screen.',
        '- Do not add quotes around dialogue, and do not add or remove trailing punctuation.',
        '- Never translate song-title placeholders like [OP], [ED], sound tags like (laughs); adapt them naturally in the target language.',
        '- Output ONLY valid JSON. No comments, no markdown fences.',
    ];
    if (!empty($lang['instruction'])) $base[] = '- ' . $lang['instruction'];
    return implode("\n", $base);
}

/**
 * Call Gemini with structured JSON output.
 * Returns the raw text response.
 */
function gemini_call(string $system, string $userPayload, int $attempt = 0, int $modelIdx = 0): string
{
    $key = gemini_api_key();
    $models = gemini_model_chain();
    $model = $models[min($modelIdx, count($models) - 1)];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($key);

    $body = json_encode([
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $userPayload]]]],
        'generationConfig' => array_merge([
            'temperature' => 0.2,
            'topP' => 0.8,
            'maxOutputTokens' => 8192,
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'id' => ['type' => 'INTEGER'],
                        'text' => ['type' => 'STRING'],
                    ],
                    'required' => ['id', 'text'],
                ],
            ],
        ], str_contains($model, '3.5') ? ['thinkingConfig' => ['thinkingBudget' => 256]] : []),
    ]);

    try {
        $res = stream_http_request(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'x-goog-api-key' => $key],
            $body,
            30,
        );
    } catch (Throwable $e) {
        if ($attempt < 3) {
            sleep(3 * ($attempt + 1)); // 3s/6s/9s
            return gemini_call($system, $userPayload, $attempt + 1, $modelIdx);
        }
        throw new TranslationError('Gemini unreachable: ' . $e->getMessage(), 'provider_error', 502);
    }

    $status = $res['status'];

    // Model unavailable -> fall back down the model chain.
    if ($status === 404 && $modelIdx + 1 < count($models)) {
        return gemini_call($system, $userPayload, 0, $modelIdx + 1);
    }

    if ($status === 408 || $status === 429 || $status >= 500) {
        if ($attempt < 3) {
            sleep(3 * ($attempt + 1));
            return gemini_call($system, $userPayload, $attempt + 1, $modelIdx);
        }
        throw new TranslationError(
            $status === 429 ? 'Gemini rate limited' : "Gemini error {$status}",
            $status === 429 ? 'rate_limited' : 'provider_error',
            $status,
        );
    }

    if ($status === 400 || $status === 401 || $status === 403) {
        throw new TranslationError('Gemini rejected the request (check API key/model)', 'not_configured', 503);
    }

    if ($status < 200 || $status >= 300) {
        throw new TranslationError("Gemini error {$status}", 'provider_error', 502);
    }

    $json = json_decode($res['body'], true);
    $text = '';
    if (isset($json['candidates'][0]['content']['parts'])) {
        foreach ($json['candidates'][0]['content']['parts'] as $p) {
            $text .= $p['text'] ?? '';
        }
    }
    if ($text === '') {
        $blocked = $json['promptFeedback']['blockReason'] ?? null;
        throw new TranslationError(
            $blocked ? "Blocked by Gemini: {$blocked}" : 'Empty response from Gemini',
            'bad_response',
            502,
        );
    }
    return $text;
}

const GEMINI_MAX_LINE = 42;

function gemini_clamp_line(string $line): string
{
    $len = function_exists('mb_strlen') ? mb_strlen($line, 'UTF-8') : strlen($line);
    if ($len <= GEMINI_MAX_LINE) return $line;
    // Break on the nearest space rather than mid-word.
    $words = explode(' ', $line);
    $out = [];
    $cur = '';
    foreach ($words as $w) {
        $cand = trim($cur . ' ' . $w);
        $clen = function_exists('mb_strlen') ? mb_strlen($cand, 'UTF-8') : strlen($cand);
        if ($clen > GEMINI_MAX_LINE && $cur !== '') {
            $out[] = trim($cur);
            $cur = $w;
        } else {
            $cur = $cand;
        }
    }
    if ($cur !== '') $out[] = trim($cur);
    return implode("\n", $out);
}

function gemini_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Redistribute $lines into exactly $want lines, respecting MAX_LINE. */
function gemini_rebalance(array $lines, int $want): string
{
    $joined = trim(implode(' ', $lines));
    if ($want <= 1) return gemini_clamp_line($joined);

    $perLine = max(18, (int) ceil(gemini_strlen($joined) / $want));
    $words = explode(' ', $joined);
    $out = [];
    $cur = '';
    foreach ($words as $w) {
        $cand = trim($cur . ' ' . $w);
        if (gemini_strlen($cand) > $perLine && $cur !== '' && count($out) < $want - 1) {
            $out[] = trim($cur);
            $cur = $w;
        } else {
            $cur = $cand;
        }
    }
    if ($cur !== '') $out[] = trim($cur);

    if (count($out) !== $want) return gemini_clamp_line($joined);
    return implode("\n", array_map(static fn($l) => gemini_strlen($l) > GEMINI_MAX_LINE ? gemini_clamp_line($l) : $l, $out));
}

/**
 * Translate a batch of cues via Gemini structured JSON.
 * Returns a map of cue id -> translated text (only ids the model returned).
 */
function gemini_request_batch(array $batch, string $system): array
{
    $payload = json_encode(array_map(static fn($c) => ['id' => $c['id'], 'text' => $c['text']], $batch), JSON_UNESCAPED_UNICODE);
    $raw = gemini_call($system, $payload);

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        throw new TranslationError('Gemini returned malformed JSON', 'bad_response', 502);
    }
    $map = [];
    foreach ($parsed as $p) {
        if (is_array($p) && isset($p['id'], $p['text']) && is_numeric($p['id']) && is_string($p['text'])) {
            $map[(int) $p['id']] = trim($p['text']);
        }
    }
    return $map;
}

/** Enforce source line structure + clamp each line. */
function gemini_enforce_lines(array $cue, ?string $translated): string
{
    $srcLines = array_values(array_filter(array_map('trim', explode("\n", $cue['text'])), static fn($l) => $l !== ''));
    // Never fall back to the original English line — empty translation means
    // no subtitle for that cue, not English text.
    $text = ($translated !== null && $translated !== '') ? $translated : '';

    $wantLines = max(1, count($srcLines));
    $outLines = array_values(array_filter(array_map('trim', preg_split('/\n+/', $text)), static fn($l) => $l !== ''));

    if (count($outLines) === $wantLines) {
        return implode("\n", array_map('gemini_clamp_line', $outLines));
    }
    if (count($outLines) > $wantLines) {
        return gemini_rebalance($outLines, $wantLines);
    }
    // Fewer lines: keep the translation and rebalance across the original count.
    $keep = $outLines ?: [$translated ?? ''];
    return gemini_rebalance($keep, $wantLines);
}

/** Translate a single batch (with id-repair retries). */
function gemini_translate_batch(array $batch, string $system): array
{
    try {
        $byId = gemini_request_batch($batch, $system);
    } catch (Throwable $e) {
        // A totally failed batch never leaks English: cues stay empty.
        return array_map(static fn($c) => $c + ['text' => ''], $batch);
    }

    // Repair pass: re-request any cue the model failed to return, up to 3 times.
    $missing = array_values(array_filter($batch, static fn($c) => !array_key_exists($c['id'], $byId)));
    for ($attempt = 0; $attempt < 3 && $missing; $attempt++) {
        try {
            $repaired = gemini_request_batch($missing, $system);
        } catch (Throwable $e) {
            break;
        }
        foreach ($repaired as $id => $text) $byId[$id] = $text;
        $stillMissing = array_values(array_filter($missing, static fn($c) => !array_key_exists($c['id'], $byId)));
        if (count($stillMissing) === count($missing)) break;
        $missing = $stillMissing;
    }

    $out = [];
    foreach ($batch as $cue) {
        $translated = $byId[$cue['id']] ?? null;
        $cue['text'] = gemini_enforce_lines($cue, $translated);
        $out[] = $cue;
    }
    return $out;
}

/**
 * Translate cues to a target language.
 * Batches of 120 run sequentially (PHP has no async) within rate limits.
 *
 * @param array<int,array{id:int,start:float,end:float,text:string}> $cues
 * @return array<int,array{id:int,start:float,end:float,text:string}>
 */
function gemini_translate_cues(array $cues, string $langCode): array
{
    $lang = language_for($langCode);
    if ($lang === null) throw new TranslationError("Unknown language: {$langCode}", 'bad_response', 400);
    if ($lang['code'] === 'en') return $cues;
    if (!is_gemini_configured()) {
        throw new TranslationError('GOOGLE_TRANSLATE_API_KEY is not configured', 'not_configured', 503);
    }

    $system = gemini_system_prompt($lang);
    $out = [];
    $batchSize = 120;

    foreach (array_chunk($cues, $batchSize) as $batch) {
        foreach (gemini_translate_batch($batch, $system) as $c) $out[] = $c;
    }
    return $out;
}