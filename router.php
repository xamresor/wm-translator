<?php
declare(strict_types=1);

/**
 * Роутер для встроенного PHP-сервера:
 *   php -S 0.0.0.0:8088 -t public router.php
 *
 * /api/translate   → public/api.php
 * /api/phrasebook  → public/api.php?action=phrasebook
 * всё остальное    → статика из public/ (index.html по умолчанию)
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$root = __DIR__ . '/public';

if ($uri === '/api/translate') {
    require $root . '/api.php';
    return true;
}
if ($uri === '/api/langs') {
    $_GET['action'] = 'langs';
    require $root . '/api.php';
    return true;
}
if ($uri === '/api/stats') {
    $_GET['action'] = 'stats';
    require $root . '/api.php';
    return true;
}

$path = $root . $uri;
if ($uri === '/' || is_dir($path)) {
    require $root . '/index.html';
    return true;
}

// существующий статический файл — пусть сервер отдаёт сам
if (is_file($path)) {
    return false;
}

http_response_code(404);
echo 'Not found';
return true;
