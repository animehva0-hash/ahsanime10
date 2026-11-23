<?php

declare(strict_types=1);

/** Page controllers. Each renders a view by setting up data and including it. */

function render(string $view, array $data = []): void
{
    // Extract data into local scope for the view. Use a differently-named
    // holder so a 'data' key is not shadowed by this function's own $data.
    $__data = $data;
    $view_name = $view;
    unset($data, $view);
    extract($__data, EXTR_OVERWRITE);
    include __DIR__ . '/../../views/' . $view_name . '.php';
}

/* --------------------------------- home ---------------------------------- */

function render_home(): void
{
    try {
        $data = getHomeData();
    } catch (Throwable $e) {
        render('error', ['error' => $e->getMessage()]);
        return;
    }
    render('home', ['data' => $data]);
}

/* -------------------------------- search --------------------------------- */

function render_search(): void
{
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $items = [];
    $pageInfo = null;

    if ($q !== '') {
        try {
            $res = searchMedia(['search' => $q, 'page' => $page, 'sort' => ['SEARCH_MATCH'], 'ttl' => 300]);
            $items = $res['media'];
            $pageInfo = $res['pageInfo'];
        } catch (Throwable $e) {
            // render empty with error tolerance
        }
    }

    render('browse', [
        'heading' => $q !== '' ? 'Search: "' . $q . '"' : 'Search',
        'blurb'   => $q === '' ? 'Type in the search box above to find anime.' : null,
        'items'   => $items,
        'pageInfo'=> $pageInfo,
        'query'   => array_filter(['q' => $q]),
        'activeNav' => '',
    ]);
}

/* ------------------------------- discover -------------------------------- */

function render_discover(): void
{
    // /discover/{kind}
    $path = $_SERVER['REQUEST_URI'] ?? '';
    $segments = array_values(array_filter(explode('/', trim(parse_url($path, PHP_URL_PATH) ?: '/', '/')), static fn($s) => $s !== ''));
    $kind = $segments[1] ?? 'trending';
    $cfg = discoverConfig($kind);
    if (!$cfg) $cfg = discoverConfig('trending');

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $params = ['page' => $page, 'sort' => $cfg['sort']];
    if (!empty($cfg['status'])) $params['status'] = $cfg['status'];

    $items = [];
    $pageInfo = null;
    try {
        $res = searchMedia($params);
        $items = $res['media'];
        $pageInfo = $res['pageInfo'];
    } catch (Throwable $e) {
        // tolerate
    }

    render('browse', [
        'heading' => $cfg['title'],
        'blurb'   => $cfg['blurb'],
        'items'   => $items,
        'pageInfo'=> $pageInfo,
        'query'   => array_filter(['page' => '']),
        'activeNav' => '/discover/' . $kind,
    ]);
}

/* --------------------------------- browse -------------------------------- */

function render_browse(array $overrides = []): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $params = ['page' => $page];
    $query = [];

    if (!empty($overrides['genre']) || isset($_GET['genre'])) {
        $genre = $overrides['genre'] ?? $_GET['genre'];
        $params['genre'] = $genre;
        $query['genre'] = $genre;
    }
    if (!empty($_GET['format'])) {
        $params['format'] = strtoupper($_GET['format']);
        $query['format'] = $_GET['format'];
    }
    if (!empty($_GET['status'])) {
        $params['status'] = strtoupper($_GET['status']);
        $query['status'] = $_GET['status'];
    }
    if (!empty($_GET['sort'])) {
        $params['sort'] = [strtoupper($_GET['sort'])];
        $query['sort'] = $_GET['sort'];
    }

    $items = [];
    $pageInfo = null;
    try {
        $res = searchMedia($params);
        $items = $res['media'];
        $pageInfo = $res['pageInfo'];
    } catch (Throwable $e) {
        // tolerate
    }

    $heading = $overrides['genre'] ?? $_GET['genre'] ?? 'Browse Anime';
    if (isset($overrides['genre']) || isset($_GET['genre'])) {
        $heading = ($overrides['genre'] ?? $_GET['genre']) . ' Anime';
    }

    render('browse', [
        'heading' => $heading,
        'blurb'   => null,
        'items'   => $items,
        'pageInfo'=> $pageInfo,
        'query'   => $query,
        'activeNav' => '',
    ]);
}

/* --------------------------------- anime --------------------------------- */

function render_anime(int $id): void
{
    $media = null;
    try {
        $media = getMediaDetail($id);
    } catch (Throwable $e) {
        render('error', ['error' => $e->getMessage()]);
        return;
    }
    if (!$media) {
        render_not_found();
        return;
    }
    render('anime', ['media' => $media]);
}

/* --------------------------------- watch --------------------------------- */

function render_watch(int $animeId, int $ep): void
{
    if ($ep < 1) {
        render_not_found();
        return;
    }
    $media = null;
    try {
        $media = getMediaDetail($animeId);
    } catch (Throwable $e) {
        render('error', ['error' => $e->getMessage()]);
        return;
    }
    if (!$media) {
        render_not_found();
        return;
    }
    $total = episodeTotal($media);
    $isMovie = ($media['format'] ?? null) === 'MOVIE';
    if ($total > 0 && $ep > $total) {
        render_not_found();
        return;
    }
    if ($isMovie && $ep !== 1) {
        render_not_found();
        return;
    }
    render('watch', ['media' => $media, 'episode' => $ep, 'total' => $total]);
}

/* ------------------------------- not found ------------------------------- */

function render_not_found(): void
{
    http_response_code(404);
    render('notfound');
}

function render_error(string $msg = ''): void
{
    render('error', ['error' => $msg]);
}