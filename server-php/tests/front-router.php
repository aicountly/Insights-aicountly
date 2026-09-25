<?php

declare(strict_types=1);

/**
 * Router for `php -S` during local browser verification.
 *
 * Serves the built React app and this API from ONE origin, which is how the
 * product is actually deployed (server-php goes to <document root>/api). Doing
 * it any other way would exercise a CORS path that does not exist in
 * production and would miss the same-origin assumptions in the auth relay.
 *
 * DEVELOPMENT ONLY — it is not deployed and nothing outside tests/ references it.
 */

$root = dirname(__DIR__);
$dist = dirname($root) . '/web/dist';

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// --- the API ---------------------------------------------------------------

if ($path === '/api' || str_starts_with($path, '/api/')) {
    // index.php strips its own mount point from the URI using SCRIPT_NAME, so
    // tell it the truth about where it is mounted.
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    require $root . '/index.php';
    return true;
}

// --- static files ----------------------------------------------------------

$file = realpath($dist . $path);
if ($file !== false && is_file($file) && str_starts_with($file, realpath($dist) ?: $dist)) {
    $types = [
        'js' => 'text/javascript', 'css' => 'text/css', 'html' => 'text/html',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'ico' => 'image/x-icon', 'json' => 'application/json', 'woff2' => 'font/woff2',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

// --- history fallback ------------------------------------------------------
//
// /dashboards/abc and /auth/callback are client routes; without this they 404
// and the portal round trip cannot be tested at all.

header('Content-Type: text/html');
readfile($dist . '/index.html');
return true;
