<?php
/** Global configuration for AHSANIME (PHP edition). */

declare(strict_types=1);

// Site
define('SITE_NAME', 'AHSANIME');
define('SITE_URL', 'https://indanime.co');

// AniList GraphQL
define('ANILIST_URL', 'https://graphql.anilist.co');

// MegaPlay stream provider base
define('MEGAPLAY_BASE', 'https://megaplay.buzz/stream/ani');

// Where generated file caches live (AniList responses, etc.)
$root = __DIR__;
define('CACHE_DIR', $root . '/cache');
define('CACHE_TTL_DEFAULT', 600);      // 10 min
define('CACHE_TTL_STALE_MAX', 604800); // 7 days — stale art beats no art

// AniList pacing
define('ANILIST_MIN_INTERVAL_US', 750000); // 750ms between network calls
define('ANILIST_TIMEOUT_SEC', 20);

// Pages per listing page
define('PER_PAGE', 24);

// Load .env (simple KEY=VALUE) into the environment if present. The Next.js app
// reads these from process.env; the PHP port reads them via getenv(). We look in
// the PHP root first, then the parent (repo root where the original .env lives).
foreach ([$root . '/.env', dirname($root) . '/.env'] as $envFile) {
    if (!is_readable($envFile)) continue;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        if ($k === '' || getenv($k) !== false) continue; // don't override real env
        // Strip surrounding quotes.
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }
}

if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0775, true);
}