<?php

declare(strict_types=1);

require __DIR__ . '/env-loader.php';

$route = trim((string)($_GET['route'] ?? ''), '/');
if ($route === '') {
    $route = 'health';
}

// Rebuild REQUEST_URI so the existing API router can stay unchanged.
$query = $_GET;
unset($query['route']);
$_SERVER['REQUEST_URI'] = '/' . $route . ($query ? '?' . http_build_query($query) : '');

require __DIR__ . '/index.php';
