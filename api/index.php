<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

bw_apply_cors();

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
            'version' => '2.0.0',
            'php' => PHP_VERSION,
            'database' => 'sqlite',
            'google_drive' => bw_google_ready(),
            'time' => bw_now(),
        ]);
    }

    if ($method === 'POST' && $path === 'auth/register') {
        $body = bw_body();
        $email = bw_lower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $displayName = trim((string)($body['display_name'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            bw_error('Adresse e-mail invalide.', 422);
        }
        if (bw_strlen($password) < 8 || bw_strlen($password) > 200) {
            bw_error('Le mot de passe doit contenir entre 8 et 200 caractères.', 422);
        }
        if (bw_strlen($displayName) < 2 || bw_strlen($displayName) > 50) {
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

        $userId = (int)bw_db()->lastInsertId();
        $stmt = bw_db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        bw_boot_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;

        bw_json([
            'success' => true,
            'user' => bw_user_public($user),
            'auth' => bw_issue_access_token($userId),
        ], 201);
    }

    if ($method === 'POST' && $path === 'auth/login') {
        $body = bw_body();
        $email = bw_lower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');

        $stmt = bw_db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            bw_error('E-mail ou mot de passe incorrect.', 401);
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            bw_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
        }

        bw_boot_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];

        bw_json([
            'success' => true,
            'user' => bw_user_public($user),
            'auth' => bw_issue_access_token((int)$user['id']),
        ]);
    }

    if ($method === 'POST' && $path === 'auth/logout') {
        bw_revoke_access_token(bw_bearer_token());
        if (session_status() !== PHP_SESSION_ACTIVE) {
            bw_boot_session();
        }
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
        $category = bw_normalize_category((string)($body['category'] ?? 'other'));

        if ($title === '' || bw_strlen($title) > 150) {
            bw_error('Le titre doit contenir entre 1 et 150 caractères.', 422);
        }
        if (bw_strlen($description) > 1000) {
            bw_error('La description est trop longue.', 422);
        }
        if ($coverUrl !== '' && !filter_var($coverUrl, FILTER_VALIDATE_URL)) {
            bw_error('URL de couverture invalide.', 422);
        }

        $now = bw_now();
        $stmt = bw_db()->prepare('INSERT INTO books (user_id, title, slug, description, content, cover_url, category, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['id'], $title, bw_unique_slug($title), $description, $content, $coverUrl, $category, 'draft', $now, $now]);
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
            $title = array_key_exists('title', $body) ? trim((string)$body['title']) : (string)$book['title'];
            $description = array_key_exists('description', $body) ? trim((string)$body['description']) : (string)$book['description'];
            $content = array_key_exists('content', $body) ? (string)$body['content'] : (string)$book['content'];
            $coverUrl = array_key_exists('cover_url', $body) ? trim((string)$body['cover_url']) : (string)$book['cover_url'];
            $category = array_key_exists('category', $body) ? bw_normalize_category((string)$body['category']) : (string)($book['category'] ?? 'other');

            if ($title === '' || bw_strlen($title) > 150) {
                bw_error('Le titre doit contenir entre 1 et 150 caractères.', 422);
            }
            if (bw_strlen($description) > 1000) {
                bw_error('La description est trop longue.', 422);
            }
            if ($coverUrl !== '' && !filter_var($coverUrl, FILTER_VALIDATE_URL)) {
                bw_error('URL de couverture invalide.', 422);
            }

            $slug = $title !== $book['title'] ? bw_unique_slug($title, $bookId) : (string)$book['slug'];
            $stmt = bw_db()->prepare('UPDATE books SET title = ?, slug = ?, description = ?, content = ?, cover_url = ?, category = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$title, $slug, $description, $content, $coverUrl, $category, bw_now(), $bookId, (int)$user['id']]);
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

        if ($action === 'publish' && trim((string)$book['content']) === '') {
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
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $search = trim((string)($_GET['search'] ?? ''));
        $categoryRaw = trim((string)($_GET['category'] ?? ''));
        $category = $categoryRaw === '' || $categoryRaw === 'all' ? null : bw_normalize_category($categoryRaw);
        $where = ["b.status = 'published'"];
        $params = [];

        if ($search !== '') {
            $where[] = '(b.title LIKE ? OR b.description LIKE ? OR u.display_name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            array_push($params, $like, $like, $like);
        }
        if ($category !== null) {
            $where[] = 'b.category = ?';
            $params[] = $category;
        }

        $sql = "SELECT b.*, u.display_name AS author_name FROM books b JOIN users u ON u.id = b.user_id WHERE " . implode(' AND ', $where) . " ORDER BY b.published_at DESC LIMIT ? OFFSET ?";
        $stmt = bw_db()->prepare($sql);
        $index = 1;
        foreach ($params as $param) {
            $stmt->bindValue($index++, $param, PDO::PARAM_STR);
        }
        $stmt->bindValue($index++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($index, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $books = array_map(function (array $book): array {
            $payload = bw_book_payload($book, false);
            $payload['author_name'] = (string)$book['author_name'];
            return $payload;
        }, $stmt->fetchAll());
        bw_json(['success' => true, 'books' => $books, 'pagination' => ['limit' => $limit, 'offset' => $offset, 'returned' => count($books)]]);
    }

    if ($method === 'GET' && preg_match('#^public/books/([a-z0-9-]+)$#', $path, $matches)) {
        $stmt = bw_db()->prepare("SELECT b.*, u.display_name AS author_name FROM books b JOIN users u ON u.id = b.user_id WHERE b.slug = ? AND b.status = 'published' LIMIT 1");
        $stmt->execute([$matches[1]]);
        $book = $stmt->fetch();
        if (!$book) {
            bw_error('Livre publié introuvable.', 404);
        }
        $payload = bw_book_payload($book, true);
        $payload['author_name'] = (string)$book['author_name'];
        bw_json(['success' => true, 'book' => $payload]);
    }

    if ($method === 'GET' && $path === 'keys') {
        $user = bw_require_user();
        $stmt = bw_db()->prepare('SELECT id, name, key_prefix, created_at, last_used_at FROM api_keys WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([(int)$user['id']]);
        $keys = array_map(function (array $key): array { $key['id'] = (int)$key['id']; return $key; }, $stmt->fetchAll());
        bw_json(['success' => true, 'keys' => $keys]);
    }

    if ($method === 'POST' && $path === 'keys') {
        $user = bw_require_user();
        $body = bw_body();
        $name = trim((string)($body['name'] ?? 'Ma clé API'));
        if ($name === '' || bw_strlen($name) > 80) {
            bw_error('Nom de clé invalide.', 422);
        }
        $rawKey = 'bw_' . bin2hex(random_bytes(24));
        $stmt = bw_db()->prepare('INSERT INTO api_keys (user_id, name, key_hash, key_prefix, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['id'], $name, hash('sha256', $rawKey), substr($rawKey, 0, 11) . '…', bw_now()]);
        bw_json(['success' => true, 'api_key' => $rawKey, 'warning' => 'Copie cette clé maintenant : elle ne sera plus affichée intégralement.'], 201);
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
        if (!bw_google_ready()) {
            bw_error('Google Drive n’est pas configuré.', 503);
        }

        $queryToken = trim((string)($_GET['access_token'] ?? ''));
        $user = $queryToken !== '' ? bw_user_from_token($queryToken) : bw_current_user();
        if (!$user) {
            bw_error('Authentification requise pour connecter Google Drive.', 401);
        }

        $returnUrl = bw_frontend_return_url((string)($_GET['return_url'] ?? ''));
        $state = bw_create_oauth_state((int)$user['id'], $returnUrl);
        $config = bw_config();
        $query = http_build_query([
            'client_id' => $config['google_client_id'],
            'redirect_uri' => bw_google_redirect_uri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 302);
        exit;
    }

    if ($method === 'GET' && $path === 'google/callback') {
        $state = (string)($_GET['state'] ?? '');
        $oauth = bw_consume_oauth_state($state);
        if (!$oauth) {
            bw_error('État OAuth Google invalide ou expiré.', 400);
        }

        $returnUrl = bw_frontend_return_url((string)$oauth['return_url']);
        if (!empty($_GET['error'])) {
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            header('Location: ' . $returnUrl . $separator . 'google=denied', true, 302);
            exit;
        }

        $code = trim((string)($_GET['code'] ?? ''));
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
        $stmt->execute([(string)$json['access_token'], (string)($json['refresh_token'] ?? ($oauth['google_refresh_token'] ?? '')), time() + (int)($json['expires_in'] ?? 3600), (int)$oauth['oauth_user_id']]);

        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        header('Location: ' . $returnUrl . $separator . 'google=connected', true, 302);
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
        bw_json(['success' => true, 'files' => is_array($json) ? ($json['files'] ?? []) : []]);
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

        if (strlen($contentResponse['body']) > 5 * 1024 * 1024) {
            bw_error('Le fichier importé est trop volumineux.', 413);
        }

        $title = trim((string)$metadata['name']);
        $title = preg_replace('/\.(txt|md|markdown)$/i', '', $title) ?: $title;
        $now = bw_now();
        $stmt = bw_db()->prepare('INSERT INTO books (user_id, title, slug, description, content, cover_url, category, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['id'], bw_substr($title, 0, 150), bw_unique_slug($title), 'Importé depuis Google Drive', $contentResponse['body'], '', 'other', 'draft', $now, $now]);
        $book = bw_book_for_owner((int)bw_db()->lastInsertId(), (int)$user['id']);
        bw_json(['success' => true, 'book' => bw_book_payload($book)], 201);
    }

    bw_error('Route API introuvable.', 404, ['path' => $path]);
} catch (Throwable $e) {
    error_log('BookWriter API: ' . $e->getMessage());
    $debug = bw_env('APP_DEBUG', '0') === '1';
    bw_error('Erreur interne du serveur.', 500, $debug ? ['detail' => $e->getMessage()] : []);
}
