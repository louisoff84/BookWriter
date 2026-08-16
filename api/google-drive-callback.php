<?php

declare(strict_types=1);

require __DIR__ . '/env-loader.php';
require __DIR__ . '/init.php';

$state = trim((string)($_GET['state'] ?? ''));
if ($state === '') {
    bw_error('État OAuth Google manquant.', 400);
}

$stateHash = hash('sha256', $state);
$stmt = bw_db()->prepare('SELECT * FROM oauth_states WHERE state_hash = ? AND expires_at > ? LIMIT 1');
$stmt->execute([$stateHash, time()]);
$oauth = $stmt->fetch();
bw_db()->prepare('DELETE FROM oauth_states WHERE state_hash = ?')->execute([$stateHash]);

if (!$oauth) {
    bw_error('État OAuth Google invalide ou expiré.', 400);
}

$returnUrl = bw_frontend_return_url((string)$oauth['return_url']);
if (!empty($_GET['error'])) {
    $sep = str_contains($returnUrl, '?') ? '&' : '?';
    header('Location: ' . $returnUrl . $sep . 'google=denied', true, 302);
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    bw_error('Code OAuth Google manquant.', 400);
}

$config = bw_config();
$redirectUri = rtrim((string)$config['app_url'], '/') . '/google-drive-callback.php';
$payload = http_build_query([
    'code' => $code,
    'client_id' => $config['google_client_id'],
    'client_secret' => $config['google_client_secret'],
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
]);

$response = bw_http('POST', 'https://oauth2.googleapis.com/token', [
    'Content-Type: application/x-www-form-urlencoded',
], $payload);
$json = json_decode($response['body'], true);

if ($response['status'] >= 400 || !is_array($json) || empty($json['access_token'])) {
    bw_error('Impossible de connecter Google Drive.', 502);
}

$stmt = bw_db()->prepare('SELECT google_refresh_token FROM users WHERE id = ? LIMIT 1');
$stmt->execute([(int)$oauth['user_id']]);
$currentUser = $stmt->fetch();
$refreshToken = (string)($json['refresh_token'] ?? ($currentUser['google_refresh_token'] ?? ''));

bw_db()->prepare('UPDATE users SET google_access_token = ?, google_refresh_token = ?, google_token_expires_at = ? WHERE id = ?')
    ->execute([
        (string)$json['access_token'],
        $refreshToken,
        time() + (int)($json['expires_in'] ?? 3600),
        (int)$oauth['user_id'],
    ]);

$sep = str_contains($returnUrl, '?') ? '&' : '?';
header('Location: ' . $returnUrl . $sep . 'google=connected', true, 302);
exit;
