<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

header('Access-Control-Allow-Origin: ' . bw_config()['cors_origin']);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/api', PHP_URL_PATH) ?: '/api';
$path = preg_replace('#^/api(?:/index\.php)?#', '', $uriPath) ?? '';
$path = trim($path, '/');

try {
    if ($method === 'GET' && ($path === '' || $path === 'health')) {
        bw_json([
            'success' => true,
            'service' => 'BookWriter API',
            'version' => '1.0.0',
            'php' => PHP_VERSION,
            'time' => bw_now(),
        ]);
    }

    if ($method === 'POST' && $path === 'auth/register') {
        $body = bw_body();
        $email = mb_strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $displayName = trim((string)($body['display_name'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            bw_error('Adresse e-mail invalide.', 422);
        }
        if (mb_strlen($password) < 8) {
            bw_error('Le mot de passe doit contenir au moins 8 caractères.', 422);
        }
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 50) {
            bw_error('Le nom doit contenir entre 2 et 50 caractères.', 422);
        }

        try {
            $stmt = bw_db()->prepare('INSERT INTO users (email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $displayName, bw_now()]);
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                bw_error('Un compte utilise déjà cette adresse e-mail.', 409);
            }
            throw $e;
        }

        bw_boot_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)bw_db()->lastInsertId();
        $user = bw_current_user();
        bw_json(['success' => true, 'user' => bw_user_public($user)], 201);
    }

    if ($method === 'POST' && $path === 'auth/login') {
        $body = bw_body();
        $email = mb_strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');

        $stmt = bw_db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            bw_error('E-mail ou mot de passe incorrect.', 401);
        }

        bw_boot_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        bw_json(['success' => true, 'user' => bw_user_public($user)]);
    }

    if ($method === 'POST' && $path === 'auth/logout') {
        bw_boot_session();
        $_SESSION = [];
        session_destroy();
        bw_json(['success' => true]);
    }

    if ($method === 'GET' && $path === 'auth/me') {
        $user = bw_current_user();
        bw_json([
            'success' => true,
            'authenticated' => $user !== null,
            'user' => $user ? bw_user_public($user) : null,
        ]);
    }

    if ($method === 'GET' && $path === 'books') {
        $user = bw_require_user();
        $stmt = bw_db()->prepare('SELECT * FROM books WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([(int)$user['id']]);
        $books = array_map(fn(array $book): array => bw_book_payload($book, false), $stmt->fetchAll());
        bw_json(['success' => true, 'books' => $books]);
    }

    if ($method === 'POST' && $path === 'books') {
        $user = bw_require_user();
        $body = bw_body();
        $title = trim((string)($body['title'] ?? 'Nouveau livre'));
        $description = trim((string)($body['description'] ?? ''));
        $content = (string)($body['content'] ?? '');
        $coverUrl = trim((string)($body['cover_url'] ?? ''));

        if ($title === '' || mb_strlen($title) > 150) {
            bw_error('Titre invalide.', 422);
        }

        $now = bw_now();
        $stmt = bw_db()->prepare('INSERT INTO books (user_id, title, slug, description, content, cover_url, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['id'], $title, bw_unique_slug($title), $description, $content, $coverUrl, 'draft', $now, $now]);
        $book = bw_book_for_owner((int)bw_db()->lastInsertId(), (int)$user['id']);
        bw_json(['success' => true, 'book' => bw_book_payload($book)], 201);
    }

    if (preg_match('#^books/(\d+)$#', $path, $matches)) {
        $user = bw_require_user();
        $bookId = (int)$matches[1];
        $book = bw_book_for_owner($bookId, (int)$user['id']);

        if ($method === 'GET') {
            bw_json(['success' => true, 'book' => bw_book_payload($book)]);
        }

        if ($method === 'PATCH') {
            $body = bw_body();
            $title = array_key_exists('title', $body) ? trim((string)$body['title']) : $book['title'];
            $description = array_key_exists('description', $body) ? trim((string)$body['description']) : $book['description'];
            $content = array_key_exists('content', $body) ? (string)$body['content'] : $book['content'];
            $coverUrl = array_key_exists('cover_url', $body) ? trim((string)$body['cover_url']) : $book['cover_url'];

            if ($title === '' || mb_strlen($title) > 150) {
                bw_error('Titre invalide.', 422);
            }

            $slug = $title !== $book['title'] ? bw_unique_slug($title, $bookId) : $book['slug'];
            $stmt = bw_db()->prepare('UPDATE books SET title = ?, slug = ?, description = ?, content = ?, cover_url = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$title, $slug, $description, $content, $coverUrl, bw_now(), $bookId, (int)$user['id']]);
            bw_json(['success' => true, 'book' => bw_book_payload(bw_book_for_owner($bookId, (int)$user['id']))]);
        }

        if ($method === 'DELETE') {
            $stmt = bw_db()->prepare('DELETE FROM books WHERE id = ? AND user_id = ?');
            $stmt->execute([$bookId, (int)$user['id']]);
            bw_json(['success' => true]);
        }
    }

    if ($method === 'POST' && preg_match('#^books/(\d+)/(publish|unpublish)$#', $path, $matches)) {
        $user = bw_require_user();
        $bookId = (int)$matches[1];
        $action = $matches[2];
        $book = bw_book_for_owner($bookId, (int)$user['id']);

        if ($action === 'publish' && trim($book['content']) === '') {
            bw_error('Ajoute du contenu avant de publier.', 422);
        }

        if ($action === 'publish') {
            $now = bw_now();
            $stmt = bw_db()->prepare("UPDATE books SET status = 'published', published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$now, $now, $bookId, (int)$user['id']]);
        } else {
            $stmt = bw_db()->prepare("UPDATE books SET status = 'draft', updated_at = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([bw_now(), $bookId, (int)$user['id']]);
        }

        bw_json(['success' => true, 'book' => bw_book_payload(bw_book_for_owner($bookId, (int)$user['id']))]);
    }

    if ($method === 'GET' && $path === 'public/books') {
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $stmt = bw_db()->prepare("SELECT b.*, u.display_name AS author_name FROM books b JOIN users u ON u.id = b.user_id WHERE b.status = 'published' ORDER BY b.published_at DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $books = array_map(function (array $book): array {
            $payload = bw_book_payload($book, false);
            $payload['author_name'] = $book['author_name'];
            return $payload;
        }, $stmt->fetchAll());
        bw_json(['success' => true, 'books' => $books]);
    }

    if ($method === 'GET' && preg_match('#^public/books/([a-z0-9-]+)$#', $path, $matches)) {
        $stmt = bw_db()->prepare("SELECT b.*, u.display_name AS author_name FROM books b JOIN users u ON u.id = b.user_id WHERE b.slug = ? AND b.status = 'published' LIMIT 1");
        $stmt->execute([$matches[1]]);
        $book = $stmt->fetch();
        if (!$book) {
            bw_error('Livre publié introuvable.', 404);
        }
        $payload = bw_book_payload($book, true);
        $payload['author_name'] = $book['author_name'];
        bw_json(['success' => true, 'book' => $payload]);
    }

    if ($method === 'GET' && $path === 'keys') {
        $user = bw_require_user();
        $stmt = bw_db()->prepare('SELECT id, name, key_prefix, created_at, last_used_at FROM api_keys WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([(int)$user['id']]);
        bw_json(['success' => true, 'keys' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $path === 'keys') {
        $user = bw_require_user();
        $body = bw_body();
        $name = trim((string)($body['name'] ?? 'Ma clé API'));
        if ($name === '' || mb_strlen($name) > 80) {
            bw_error('Nom de clé invalide.', 422);
        }

        $rawKey = 'bw_' . bin2hex(random_bytes(24));
        $stmt = bw_db()->prepare('INSERT INTO api_keys (user_id, name, key_hash, key_prefix, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['id'], $name, hash('sha256', $rawKey), substr($rawKey, 0, 11) . '…', bw_now()]);
        bw_json([
            'success' => true,
            'api_key' => $rawKey,
            'warning' => 'Copie cette clé maintenant : elle ne sera plus affichée intégralement.',
        ], 201);
    }

    if ($method === 'DELETE' && preg_match('#^keys/(\d+)$#', $path, $matches)) {
        $user = bw_require_user();
        $stmt = bw_db()->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?');
        $stmt->execute([(int)$matches[1], (int)$user['id']]);
        if ($stmt->rowCount() === 0) {
            bw_error('Clé API introuvable.', 404);
        }
        bw_json(['success' => true]);
    }

    if ($method === 'GET' && $path === 'google/connect') {
        $user = bw_require_user();
        bw_boot_session();
        if (!bw_google_ready()) {
            bw_error('Configure GOOGLE_CLIENT_ID et GOOGLE_CLIENT_SECRET.', 503);
        }
        if (bw_bearer_token() !== null) {
            bw_error('L OAuth Google doit être lancé depuis le navigateur.', 400);
        }

        $_SESSION['google_oauth_state'] = bin2hex(random_bytes(24));
        $config = bw_config();
        $query = http_build_query([
            'client_id' => $config['google_client_id'],
            'redirect_uri' => bw_google_redirect_uri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $_SESSION['google_oauth_state'],
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 302);
        exit;
    }

    if ($method === 'GET' && $path === 'google/callback') {
        bw_boot_session();
        $user = bw_current_user();
        if (!$user) {
            header('Location: /auth.php?action=login', true, 302);
            exit;
        }

        $state = (string)($_GET['state'] ?? '');
        $expected = (string)($_SESSION['google_oauth_state'] ?? '');
        unset($_SESSION['google_oauth_state']);
        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            bw_error('État OAuth Google invalide.', 400);
        }
        if (!empty($_GET['error'])) {
            header('Location: /drive.php?google=denied', true, 302);
            exit;
        }

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            bw_error('Code OAuth Google manquant.', 400);
        }

        $config = bw_config();
        $payload = http_build_query([
            'code' => $code,
            'client_id' => $config['google_client_id'],
            'client_secret' => $config['google_client_secret'],
            'redirect_uri' => bw_google_redirect_uri(),
            'grant_type' => 'authorization_code',
        ]);
        $response = bw_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], $payload);
        $json = json_decode($response['body'], true);
        if ($response['status'] >= 400 || !is_array($json) || empty($json['access_token'])) {
            bw_error('Impossible de connecter Google Drive.', 502);
        }

        $stmt = bw_db()->prepare('UPDATE users SET google_access_token = ?, google_refresh_token = ?, google_token_expires_at = ? WHERE id = ?');
        $stmt->execute([
            (string)$json['access_token'],
            (string)($json['refresh_token'] ?? ($user['google_refresh_token'] ?? '')),
            time() + (int)($json['expires_in'] ?? 3600),
            (int)$user['id'],
        ]);
        header('Location: /drive.php?google=connected', true, 302);
        exit;
    }

    if ($method === 'GET' && $path === 'google/files') {
        $user = bw_require_user();
        $q = "trashed = false and (mimeType = 'application/vnd.google-apps.document' or mimeType = 'text/plain' or mimeType = 'text/markdown')";
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $q,
            'pageSize' => 100,
            'orderBy' => 'modifiedTime desc',
            'fields' => 'files(id,name,mimeType,modifiedTime,size,webViewLink)',
        ]);
        $response = bw_google_api($user, $url);
        $json = json_decode($response['body'], true);
        bw_json(['success' => true, 'files' => $json['files'] ?? []]);
    }

    if ($method === 'POST' && $path === 'google/import') {
        $user = bw_require_user();
        $body = bw_body();
        $fileId = trim((string)($body['file_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{10,}$/', $fileId)) {
            bw_error('Identifiant Google Drive invalide.', 422);
        }

        $metadataResponse = bw_google_api($user, 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?fields=id,name,mimeType');
        $metadata = json_decode($metadataResponse['body'], true);
        if (!is_array($metadata) || empty($metadata['name']) || empty($metadata['mimeType'])) {
            bw_error('Fichier Google Drive invalide.', 422);
        }

        $mime = (string)$metadata['mimeType'];
        if ($mime === 'application/vnd.google-apps.document') {
            $contentResponse = bw_google_api($user, 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '/export?mimeType=' . rawurlencode('text/plain'));
        } elseif (in_array($mime, ['text/plain', 'text/markdown'], true)) {
            $contentResponse = bw_google_api($user, 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media');
        } else {
            bw_error('Type de fichier non pris en charge.', 422);
        }

        $title = trim((string)$metadata['name']);
        $title = preg_replace('/\.(txt|md|markdown)$/i', '', $title) ?: $title;
        $now = bw_now();
        $stmt = bw_db()->prepare('INSERT INTO books (user_id, title, slug, description, content, cover_url, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['id'], $title, bw_unique_slug($title), 'Importé depuis Google Drive', $contentResponse['body'], '', 'draft', $now, $now]);
        $book = bw_book_for_owner((int)bw_db()->lastInsertId(), (int)$user['id']);
        bw_json(['success' => true, 'book' => bw_book_payload($book)], 201);
    }

    bw_error('Route API introuvable.', 404, ['path' => $path]);
} catch (Throwable $e) {
    error_log('BookWriter API: ' . $e->getMessage());
    bw_error('Erreur interne du serveur.', 500);
}
