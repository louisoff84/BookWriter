<?php

declare(strict_types=1);

// The backend files are deployed directly in the Plesk document root.
const BW_ROOT = __DIR__;
const BW_STORAGE = BW_ROOT . '/storage';

function bw_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

function bw_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function bw_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function bw_base_url(): string
{
    $configured = bw_env('APP_URL');
    if ($configured) {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function bw_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'app_name' => bw_env('APP_NAME', 'BookWriter'),
        'app_url' => bw_base_url(),
        'frontend_url' => rtrim((string)bw_env('FRONTEND_URL', 'https://louisoff84.github.io/BookWriter'), '/'),
        'google_client_id' => bw_env('GOOGLE_CLIENT_ID', ''),
        'google_client_secret' => bw_env('GOOGLE_CLIENT_SECRET', ''),
        'cors_origins' => array_values(array_filter(array_map('trim', explode(',', (string)bw_env('CORS_ORIGINS', 'https://louisoff84.github.io'))))),
        'access_token_ttl' => max(3600, (int)bw_env('ACCESS_TOKEN_TTL', '604800')),
        'debug' => bw_env('APP_DEBUG', '0') === '1',
    ];

    return $config;
}

function bw_apply_cors(): void
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowed = bw_config()['cors_origins'];
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    header('X-Content-Type-Options: nosniff');
}

function bw_boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = str_starts_with(bw_base_url(), 'https://');
    session_name('bookwriter_session');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function bw_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(BW_STORAGE)) {
        mkdir(BW_STORAGE, 0775, true);
    }

    $dbPath = BW_STORAGE . '/bookwriter.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    bw_init_schema($pdo);
    return $pdo;
}

function bw_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

function bw_init_schema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    google_access_token TEXT,
    google_refresh_token TEXT,
    google_token_expires_at INTEGER,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    content TEXT NOT NULL DEFAULT '',
    cover_url TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'other',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'published')),
    published_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    key_hash TEXT NOT NULL UNIQUE,
    key_prefix TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS oauth_states (
    state_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    return_url TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_books_user_id ON books(user_id);
CREATE INDEX IF NOT EXISTS idx_books_status ON books(status);
CREATE INDEX IF NOT EXISTS idx_books_category ON books(category);
CREATE INDEX IF NOT EXISTS idx_api_keys_user_id ON api_keys(user_id);
CREATE INDEX IF NOT EXISTS idx_auth_tokens_user_id ON auth_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_auth_tokens_expires_at ON auth_tokens(expires_at);
SQL);

    if (!bw_column_exists($pdo, 'books', 'category')) {
        $pdo->exec("ALTER TABLE books ADD COLUMN category TEXT NOT NULL DEFAULT 'other'");
    }

    $pdo->prepare('DELETE FROM auth_tokens WHERE expires_at < ?')->execute([time()]);
    $pdo->prepare('DELETE FROM oauth_states WHERE expires_at < ?')->execute([time()]);
    $initialized = true;
}

function bw_now(): string
{
    return gmdate('c');
}

function bw_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function bw_error(string $message, int $status = 400, array $extra = []): never
{
    bw_json(array_merge(['success' => false, 'error' => $message], $extra), $status);
}

function bw_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    if (strlen($raw) > 5 * 1024 * 1024) {
        bw_error('Payload trop volumineux.', 413);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        bw_error('JSON invalide.', 400);
    }
    return $data;
}

function bw_user_public(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'display_name' => (string)$user['display_name'],
        'google_connected' => !empty($user['google_access_token']) || !empty($user['google_refresh_token']),
        'created_at' => (string)$user['created_at'],
    ];
}

function bw_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function bw_user_from_token(string $token): ?array
{
    $pdo = bw_db();
    $hash = hash('sha256', $token);

    if (str_starts_with($token, 'bwa_')) {
        $stmt = $pdo->prepare(
            'SELECT u.* FROM users u JOIN auth_tokens t ON t.user_id = u.id WHERE t.token_hash = ? AND t.expires_at > ? LIMIT 1'
        );
        $stmt->execute([$hash, time()]);
        $user = $stmt->fetch();
        if ($user) {
            $pdo->prepare('UPDATE auth_tokens SET last_used_at = ? WHERE token_hash = ?')->execute([bw_now(), $hash]);
            return $user;
        }
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT u.* FROM users u JOIN api_keys k ON k.user_id = u.id WHERE k.key_hash = ? LIMIT 1'
    );
    $stmt->execute([$hash]);
    $user = $stmt->fetch();
    if ($user) {
        $pdo->prepare('UPDATE api_keys SET last_used_at = ? WHERE key_hash = ?')->execute([bw_now(), $hash]);
        return $user;
    }

    return null;
}

function bw_current_user(): ?array
{
    $token = bw_bearer_token();
    if ($token !== null) {
        return bw_user_from_token($token);
    }

    bw_boot_session();
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }

    $stmt = bw_db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function bw_require_user(): array
{
    $user = bw_current_user();
    if (!$user) {
        bw_error('Authentification requise.', 401);
    }
    return $user;
}

function bw_issue_access_token(int $userId): array
{
    $raw = 'bwa_' . bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $expiresAt = time() + (int)bw_config()['access_token_ttl'];

    $stmt = bw_db()->prepare(
        'INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $hash, $expiresAt, bw_now()]);

    return [
        'access_token' => $raw,
        'token_type' => 'Bearer',
        'expires_at' => gmdate('c', $expiresAt),
    ];
}

function bw_revoke_access_token(?string $token): void
{
    if (!$token || !str_starts_with($token, 'bwa_')) {
        return;
    }
    bw_db()->prepare('DELETE FROM auth_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
}

function bw_slugify(string $value): string
{
    $value = trim(bw_lower($value));
    if (function_exists('transliterator_transliterate')) {
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'livre';
}

function bw_unique_slug(string $title, ?int $ignoreId = null): string
{
    $pdo = bw_db();
    $base = bw_slugify($title);
    $slug = $base;
    $counter = 2;

    while (true) {
        if ($ignoreId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM books WHERE slug = ? AND id != ? LIMIT 1');
            $stmt->execute([$slug, $ignoreId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM books WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        }
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $counter++;
    }
}

function bw_normalize_category(string $value): string
{
    $allowed = ['fiction', 'fantasy', 'science-fiction', 'romance', 'thriller', 'poetry', 'non-fiction', 'other'];
    $value = trim(bw_lower($value));
    return in_array($value, $allowed, true) ? $value : 'other';
}

function bw_book_for_owner(int $id, int $userId): array
{
    $stmt = bw_db()->prepare('SELECT * FROM books WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$id, $userId]);
    $book = $stmt->fetch();
    if (!$book) {
        bw_error('Livre introuvable.', 404);
    }
    return $book;
}

function bw_book_payload(array $book, bool $includeContent = true): array
{
    $payload = [
        'id' => (int)$book['id'],
        'user_id' => (int)$book['user_id'],
        'title' => (string)$book['title'],
        'slug' => (string)$book['slug'],
        'description' => (string)$book['description'],
        'cover_url' => (string)$book['cover_url'],
        'category' => (string)($book['category'] ?? 'other'),
        'status' => (string)$book['status'],
        'published_at' => $book['published_at'],
        'created_at' => (string)$book['created_at'],
        'updated_at' => (string)$book['updated_at'],
    ];
    if ($includeContent) {
        $payload['content'] = (string)$book['content'];
    }
    return $payload;
}

function bw_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        bw_error('L’extension PHP cURL est requise.', 500);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_USERAGENT => 'BookWriter/2.0',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        bw_error('Erreur réseau: ' . $error, 502);
    }
    return ['status' => $status, 'body' => (string)$response];
}

function bw_google_ready(): bool
{
    $config = bw_config();
    return $config['google_client_id'] !== '' && $config['google_client_secret'] !== '';
}

function bw_google_redirect_uri(): string
{
    return bw_config()['app_url'] . '/google/callback';
}

function bw_google_access_token(array $user): string
{
    if (!bw_google_ready()) {
        bw_error('Google Drive n’est pas configuré sur le serveur.', 503);
    }

    $access = (string)($user['google_access_token'] ?? '');
    $expires = (int)($user['google_token_expires_at'] ?? 0);
    if ($access !== '' && $expires > time() + 60) {
        return $access;
    }

    $refresh = (string)($user['google_refresh_token'] ?? '');
    if ($refresh === '') {
        bw_error('Reconnecte Google Drive.', 401, ['code' => 'google_reconnect_required']);
    }

    $config = bw_config();
    $payload = http_build_query([
        'client_id' => $config['google_client_id'],
        'client_secret' => $config['google_client_secret'],
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
    ]);
    $response = bw_http('POST', 'https://oauth2.googleapis.com/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], $payload);
    $json = json_decode($response['body'], true);
    if ($response['status'] >= 400 || !is_array($json) || empty($json['access_token'])) {
        bw_error('Impossible de renouveler la connexion Google Drive.', 502);
    }

    $newAccess = (string)$json['access_token'];
    $newExpires = time() + (int)($json['expires_in'] ?? 3600);
    bw_db()->prepare('UPDATE users SET google_access_token = ?, google_token_expires_at = ? WHERE id = ?')
        ->execute([$newAccess, $newExpires, (int)$user['id']]);
    return $newAccess;
}

function bw_google_api(array $user, string $url): array
{
    $token = bw_google_access_token($user);
    $response = bw_http('GET', $url, ['Authorization: Bearer ' . $token]);
    if ($response['status'] >= 400) {
        bw_error('Google Drive a refusé la requête.', 502, ['google_status' => $response['status']]);
    }
    return $response;
}

function bw_frontend_return_url(?string $requested): string
{
    $base = (string)bw_config()['frontend_url'];
    $default = $base . '/import.html';
    $requested = trim((string)$requested);
    if ($requested === '') {
        return $default;
    }

    $baseParts = parse_url($base);
    $requestedParts = parse_url($requested);
    if (!is_array($baseParts) || !is_array($requestedParts)
        || ($requestedParts['scheme'] ?? '') !== ($baseParts['scheme'] ?? '')
        || ($requestedParts['host'] ?? '') !== ($baseParts['host'] ?? '')) {
        return $default;
    }

    $basePath = rtrim((string)($baseParts['path'] ?? ''), '/');
    $requestedPath = (string)($requestedParts['path'] ?? '/');
    if ($basePath !== '' && !str_starts_with($requestedPath, $basePath . '/') && $requestedPath !== $basePath) {
        return $default;
    }
    return $requested;
}
