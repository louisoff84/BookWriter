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

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = trim($uriPath, '/');

function bw_google_login_prepare_schema(): void
{
    $pdo = bw_db();

    if (!bw_column_exists($pdo, 'users', 'google_sub')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN google_sub TEXT');
    }
    if (!bw_column_exists($pdo, 'users', 'avatar_url')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar_url TEXT NOT NULL DEFAULT ''");
    }

    $pdo->exec(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_google_sub ON users(google_sub) WHERE google_sub IS NOT NULL;

CREATE TABLE IF NOT EXISTS google_login_states (
    state_hash TEXT PRIMARY KEY,
    return_url TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS google_login_codes (
    code_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_google_login_codes_user_id ON google_login_codes(user_id);
SQL);

    $pdo->prepare('DELETE FROM google_login_states WHERE expires_at < ?')->execute([time()]);
    $pdo->prepare('DELETE FROM google_login_codes WHERE expires_at < ?')->execute([time()]);
}

function bw_google_login_redirect_uri(): string
{
    return bw_config()['app_url'] . '/auth/google/callback';
}

function bw_google_login_url(string $state): string
{
    $config = bw_config();
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $config['google_client_id'],
        'redirect_uri' => bw_google_login_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => $state,
        'include_granted_scopes' => 'true',
        'prompt' => 'select_account',
    ]);
}

function bw_google_login_exchange_code(string $code): array
{
    $config = bw_config();
    $response = bw_http('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'code' => $code,
        'client_id' => $config['google_client_id'],
        'client_secret' => $config['google_client_secret'],
        'redirect_uri' => bw_google_login_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]));

    $json = json_decode($response['body'], true);
    if ($response['status'] >= 400 || !is_array($json) || empty($json['access_token'])) {
        bw_error('Impossible de terminer la connexion Google.', 502);
    }
    return $json;
}

function bw_google_login_userinfo(string $accessToken): array
{
    $response = bw_http('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
        'Authorization: Bearer ' . $accessToken,
    ]);
    $user = json_decode($response['body'], true);

    if ($response['status'] >= 400 || !is_array($user)) {
        bw_error('Impossible de récupérer le profil Google.', 502);
    }
    if (empty($user['sub']) || empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        bw_error('Le compte Google ne fournit pas les informations nécessaires.', 422);
    }
    if (array_key_exists('email_verified', $user) && !$user['email_verified']) {
        bw_error('L’adresse e-mail Google doit être vérifiée.', 403);
    }
    return $user;
}

function bw_google_login_find_or_create(array $google): array
{
    $pdo = bw_db();
    $sub = (string)$google['sub'];
    $email = bw_lower(trim((string)$google['email']));
    $name = trim((string)($google['name'] ?? ''));
    $avatar = trim((string)($google['picture'] ?? ''));
    if ($name === '') {
        $name = strstr($email, '@', true) ?: 'Auteur';
    }
    if (bw_strlen($name) > 50) {
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 50) : substr($name, 0, 50);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_sub = ? LIMIT 1');
    $stmt->execute([$sub]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $pdo->prepare('UPDATE users SET google_sub = ?, display_name = ?, avatar_url = ? WHERE id = ?')
                ->execute([$sub, $name, $avatar, (int)$user['id']]);
        } else {
            $disabledPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (email, password_hash, display_name, google_sub, avatar_url, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, $disabledPassword, $name, $sub, $avatar, bw_now()]);
            $id = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $user = $stmt->fetch();
        }
    } else {
        $pdo->prepare('UPDATE users SET email = ?, display_name = ?, avatar_url = ? WHERE id = ?')
            ->execute([$email, $name, $avatar, (int)$user['id']]);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetch();
}

function bw_append_query(string $url, array $params): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query($params);
}

try {
    bw_google_login_prepare_schema();

    if (($path === 'auth/login' || $path === 'auth/register')) {
        bw_error('Connexion par mot de passe désactivée. Utilise Google.', 410, ['code' => 'google_auth_required']);
    }

    if ($method === 'GET' && $path === 'auth/google') {
        if (!bw_google_ready()) {
            bw_error('Google OAuth n’est pas configuré sur le serveur.', 503);
        }

        $returnUrl = bw_frontend_return_url((string)($_GET['return_url'] ?? ''));
        $state = bin2hex(random_bytes(32));
        bw_db()->prepare('INSERT INTO google_login_states (state_hash, return_url, expires_at) VALUES (?, ?, ?)')
            ->execute([hash('sha256', $state), $returnUrl, time() + 600]);

        header('Cache-Control: no-store');
        header('Location: ' . bw_google_login_url($state), true, 302);
        exit;
    }

    if ($method === 'GET' && $path === 'auth/google/callback') {
        $state = trim((string)($_GET['state'] ?? ''));
        if ($state === '') {
            bw_error('État OAuth Google manquant.', 400);
        }

        $stateHash = hash('sha256', $state);
        $stmt = bw_db()->prepare('SELECT * FROM google_login_states WHERE state_hash = ? AND expires_at > ? LIMIT 1');
        $stmt->execute([$stateHash, time()]);
        $saved = $stmt->fetch();
        bw_db()->prepare('DELETE FROM google_login_states WHERE state_hash = ?')->execute([$stateHash]);
        if (!$saved) {
            bw_error('État OAuth Google invalide ou expiré.', 400);
        }

        $returnUrl = (string)$saved['return_url'];
        if (!empty($_GET['error'])) {
            header('Location: ' . bw_append_query($returnUrl, ['google' => 'denied']), true, 302);
            exit;
        }

        $code = trim((string)($_GET['code'] ?? ''));
        if ($code === '') {
            bw_error('Code OAuth Google manquant.', 400);
        }

        $tokenData = bw_google_login_exchange_code($code);
        $google = bw_google_login_userinfo((string)$tokenData['access_token']);
        $user = bw_google_login_find_or_create($google);

        $loginCode = 'bwc_' . bin2hex(random_bytes(32));
        bw_db()->prepare('INSERT INTO google_login_codes (code_hash, user_id, expires_at) VALUES (?, ?, ?)')
            ->execute([hash('sha256', $loginCode), (int)$user['id'], time() + 120]);

        header('Cache-Control: no-store');
        header('Location: ' . bw_append_query($returnUrl, ['code' => $loginCode]), true, 302);
        exit;
    }

    if ($method === 'POST' && $path === 'auth/google/exchange') {
        $body = bw_body();
        $code = trim((string)($body['code'] ?? ''));
        if (!str_starts_with($code, 'bwc_')) {
            bw_error('Code de connexion Google invalide.', 422);
        }

        $hash = hash('sha256', $code);
        $stmt = bw_db()->prepare('SELECT * FROM google_login_codes WHERE code_hash = ? AND expires_at > ? LIMIT 1');
        $stmt->execute([$hash, time()]);
        $saved = $stmt->fetch();
        bw_db()->prepare('DELETE FROM google_login_codes WHERE code_hash = ?')->execute([$hash]);
        if (!$saved) {
            bw_error('Code de connexion Google invalide ou expiré.', 401);
        }

        $stmt = bw_db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$saved['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            bw_error('Utilisateur introuvable.', 404);
        }

        bw_json([
            'success' => true,
            'user' => bw_user_public($user),
            'auth' => bw_issue_access_token((int)$user['id']),
        ]);
    }

    bw_error('Route Google Auth introuvable.', 404);
} catch (Throwable $e) {
    error_log('BookWriter Google Auth: ' . $e->getMessage());
    if (bw_config()['debug']) {
        bw_error('Erreur interne: ' . $e->getMessage(), 500);
    }
    bw_error('Erreur interne du serveur.', 500);
}
