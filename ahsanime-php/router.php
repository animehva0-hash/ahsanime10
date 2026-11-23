<?php
/**
 * Router for PHP's built-in development server.
 * Usage: php -S localhost:8080 router.php   (run from the ahsanime-php dir)
 *
 * Serves real files under /public and /cache directly; routes the rest to
 * index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$full = __DIR__ . urldecode($path);

// Serve existing static files directly.
if ($path !== '/' && is_file($full) && !is_dir($full)) {
    return false; // let the built-in server handle it
}

require __DIR__ . '/index.php';