<?php

declare(strict_types=1);

require __DIR__ . '/env-loader.php';
require __DIR__ . '/init.php';

bw_apply_cors();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    bw_error('Méthode non autorisée.', 405);
}
if (!bw_google_ready()) {
    bw_error('Google Drive n’est pas configuré.', 503);
}

$user = bw_require_user();
$body = bw_body();
$returnUrl = bw_frontend_return_url((string)($body['return_url'] ?? ''));
$state = bw_create_oauth_state((int)$user['id'], $returnUrl);
$config = bw_config();
$redirectUri = rtrim((string)$config['app_url'], '/') . '/google-drive-callback.php';

$query = http_build_query([
    'client_id' => $config['google_client_id'],
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/drive.readonly',
    'access_type' => 'offline',
    'prompt' => 'consent',
    'include_granted_scopes' => 'true',
    'state' => $state,
]);

bw_json([
    'success' => true,
    'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $query,
]);
