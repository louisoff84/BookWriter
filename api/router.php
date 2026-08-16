<?php

declare(strict_types=1);

require __DIR__ . '/env-loader.php';

// When BookWriter is hosted under a sub-path (for example /book),
// normalize REQUEST_URI before passing it to the API router.
$appUrl = rtrim((string)(getenv('APP_URL') ?: ''), '/');
$appPath = parse_url($appUrl, PHP_URL_PATH);
$basePath = is_string($appPath) ? rtrim($appPath, '/') : '';

if ($basePath !== '') {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $query = parse_url($requestUri, PHP_URL_QUERY);

    if ($requestPath === $basePath) {
        $requestPath = '/';
    } elseif (str_starts_with($requestPath, $basePath . '/')) {
        $requestPath = substr($requestPath, strlen($basePath));
    }

    $_SERVER['REQUEST_URI'] = $requestPath . ($query !== null && $query !== '' ? '?' . $query : '');
}

require __DIR__ . '/index.php';
