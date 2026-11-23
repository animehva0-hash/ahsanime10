<?php

declare(strict_types=1);

/**
 * AHSANIME (PHP edition) — front controller + router.
 * All requests are rewritten here by .htaccess.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/app/helpers.php';
require __DIR__ . '/app/constants.php';
require __DIR__ . '/app/sources.php';
require __DIR__ . '/app/Anilist.php';
require __DIR__ . '/app/queries.php';

// Stream subsystem (native HLS player + subtitle translation).
require __DIR__ . '/app/stream/Http.php';
require __DIR__ . '/app/stream/store.php';
require __DIR__ . '/app/stream/proxy_pool.php';
require __DIR__ . '/app/stream/vtt.php';
require __DIR__ . '/app/stream/languages.php';
require __DIR__ . '/app/stream/replacements.php';
require __DIR__ . '/app/stream/Shiro.php';
require __DIR__ . '/app/stream/Shonen.php';
require __DIR__ . '/app/stream/Animo.php';
require __DIR__ . '/app/stream/fetch-subtitle.php';
require __DIR__ . '/app/stream/gemini.php';
require __DIR__ . '/app/stream/translate.php';
require __DIR__ . '/app/stream/proxy.php';

// ---- request parsing -------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');

// Static assets under /public are served directly by the web server; anything
// reaching here is an application route.

// ---- API endpoints ---------------------------------------------------------

if (preg_match('#^/api/suggest$#', $path)) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    try {
        $items = getSuggestions($q);
        $out = array_map(static fn($m) => [
            'id' => $m['id'],
            'title' => $m['title']['english'] ?: $m['title']['romaji'],
            'romaji' => $m['title']['romaji'],
            'cover' => $m['coverImage']['large'] ?? null,
            'format' => $m['format'],
            'year' => $m['seasonYear'],
            'score' => $m['averageScore'] ? number_format($m['averageScore'] / 10, 1) : null,
        ], $items);
        echo json_encode(['results' => $out]);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['results' => [], 'error' => 'upstream']);
    }
    exit;
}

if (preg_match('#^/api/random$#', $path)) {
    header('Content-Type: application/json');
    $id = getRandomAnimeId();
    echo json_encode(['id' => $id]);
    exit;
}

if (preg_match('#^/api/health$#', $path)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'ts' => time()]);
    exit;
}

/** Emit a ['status','headers','body'] response array and stop. */
function emit(array $res): void
{
    http_response_code($res['status'] ?? 200);
    foreach (($res['headers'] ?? []) as $k => $v) {
        if (!headers_sent()) header($k . ': ' . $v);
    }
    echo $res['body'] ?? '';
    exit;
}

// ---- stream APIs -----------------------------------------------------------

/**
 * Resolve servers across providers (Shiro → Shonen → Animo), tagging each with
 * its provider and deduplicating by HLS URL. Shiro is tried first (translate-
 * ready, single POST); Shonen and Animo are id-only fallbacks. If Shiro is
 * reachable we return only Shiro (cleanest); otherwise we merge fallbacks.
 */
function resolve_servers(int $anilistId, int $ep): array
{
    $collect = static function (array $list, string $provider): array {
        $out = [];
        foreach ($list as $s) {
            if (empty($s['hls'])) continue;
            $s['provider'] = $provider;
            $out[] = $s;
        }
        return $out;
    };

    // 1. Shiro (primary; translate-ready).
    try {
        $shiro = $collect(shiro_resolve($anilistId, $ep), 'shiro');
    } catch (Throwable $e) {
        $shiro = [];
    }
    if ($shiro) return $shiro;

    // 2. Shonen (id-only, direct HLS + English VTT).
    try {
        $shonen = $collect(shonen_resolve($anilistId, $ep), 'shonen');
    } catch (Throwable $e) {
        $shonen = [];
    }

    // 3. Animo (id-only, multiple servers).
    try {
        $animo = $collect(animo_resolve($anilistId, $ep, false), 'animo');
    } catch (Throwable $e) {
        $animo = [];
    }

    // Merge, dedup by HLS URL, preserve order (Shonen first — usually cleaner).
    // Drop provider names (Animo/Shonen) from the label. Servers that carry a
    // quality tag become "HD-1", "HD-2", "HD-4"…; the rest are plain numbers.
    $merged = [];
    $seen = [];
    $plainNum = 0;
    foreach (array_merge($shonen, $animo) as $s) {
        if (isset($seen[$s['hls']])) continue;
        $seen[$s['hls']] = true;
        $name = trim((string) ($s['name'] ?? ''));
        // Quality-tagged servers (Animo "HD 1"/"HD-2"/"HD-4", etc.) → HD-N.
        if (preg_match('/hd[\s\-_]*(\d+)/i', $name, $m)) {
            $s['name'] = 'HD-' . (int) $m[1];
        } else {
            // Generic servers (Shonen "Primary"/"Secondary"/empty) → 1, 2, 3…
            $s['name'] = (string) (++$plainNum);
        }
        $merged[] = $s;
    }
    return $merged;
}

// Resolve playable servers for an episode. Tries providers in order and merges
// the results so the client can fall back if one provider is down.
if (preg_match('#^/api/stream/sources$#', $path)) {
    header('Content-Type: application/json; charset=utf-8');
    $animeId = (int) ($_GET['animeId'] ?? 0);
    $ep = (int) ($_GET['ep'] ?? 1);
    if ($animeId < 1) {
        http_response_code(400);
        echo json_encode(['servers' => [], 'error' => 'animeId required']);
        exit;
    }
    $servers = resolve_servers($animeId, $ep);
    echo json_encode(['servers' => $servers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Media proxy: HLS playlists (rewritten) + segments/keys/MP4 passthrough.
if (preg_match('#^/api/stream/proxy$#', $path)) {
    $range = $_SERVER['HTTP_RANGE'] ?? null;
    emit(handle_proxy(['url' => $_GET['url'] ?? '', 'ref' => $_GET['ref'] ?? ''], $range));
}

// Language catalog + provider/model status.
if (preg_match('#^/api/stream/subtitle/languages$#', $path)) {
    header('Content-Type: application/json; charset=utf-8');
    $configured = is_gemini_configured();
    echo json_encode([
        'ok' => true,
        'provider' => translate_provider(),
        'model' => translate_model(),
        'configured' => $configured,
        'status' => $configured ? 'ready' : 'GOOGLE_TRANSLATE_API_KEY not configured — original English captions remain available',
        'languages' => subtitle_languages(),
        'count' => count(subtitle_languages()),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch + normalize a subtitle to VTT (English originals).
if (preg_match('#^/api/stream/subtitle/translate$#', $path)) {
    emit(handle_translate($_GET));
}

if (preg_match('#^/api/stream/subtitle$#', $path)) {
    $url = (string) ($_GET['url'] ?? '');
    $ref = (string) ($_GET['ref'] ?? '');
    try {
        $fetched = fetch_subtitle_cues($url, $ref);
        emit(vtt_response($fetched['vtt']));
    } catch (Throwable $e) {
        $status = $e instanceof SubtitleFetchError ? $e->httpStatus : 502;
        emit(json_response(['ok' => false, 'error' => $e->getMessage()], $status));
    }
}

// ---- page routes -----------------------------------------------------------

require __DIR__ . '/app/controllers/pages.php';

$segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));

try {
    // Home
    if (count($segments) === 0) {
        render_home();
        exit;
    }

    // Search  /search?q=
    if ($segments[0] === 'search') {
        render_search();
        exit;
    }

    // Discover  /discover?kind=
    if ($segments[0] === 'discover') {
        render_discover();
        exit;
    }

    // Browse listing  /browse?page=&genre=&format=&status=&sort=
    if ($segments[0] === 'browse') {
        render_browse();
        exit;
    }

    // Genre  /genre/{name}
    if ($segments[0] === 'genre' && isset($segments[1])) {
        render_browse(['genre' => urldecode($segments[1])]);
        exit;
    }

    // Anime detail  /anime/{id}
    if ($segments[0] === 'anime' && isset($segments[1])) {
        render_anime((int) $segments[1]);
        exit;
    }

    // Watch  /watch/{id}/{episode}
    if ($segments[0] === 'watch' && isset($segments[1], $segments[2])) {
        render_watch((int) $segments[1], (int) $segments[2]);
        exit;
    }

    // Random anime  /random  → redirect to a random title's first episode.
    if ($segments[0] === 'random' && count($segments) === 1) {
        try {
            $id = getRandomAnimeId();
            if ($id > 0) {
                header('Location: /watch/' . $id . '/1', true, 302);
                exit;
            }
        } catch (Throwable $e) { /* fall through to 404 */ }
    }

    http_response_code(404);
    render_not_found();
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    render_error($e->getMessage());
    exit;
}