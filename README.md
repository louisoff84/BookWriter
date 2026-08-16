# BookWriter

BookWriter est un site pour écrire, importer et publier des livres.

## Architecture

- **Frontend GitHub Pages** : HTML, CSS et JavaScript natifs, sans Bootstrap.
- **Backend PHP** : code source dans `/api/`, hébergé séparément sur Plesk à la racine du domaine.
- **Base de données** : SQLite (`storage/bookwriter.sqlite`).
- **Authentification** : Google OAuth / OpenID Connect uniquement.
- **Google Drive** : autorisation OAuth séparée en lecture seule pour importer Google Docs, TXT et Markdown.

Le frontend GitHub Pages ne contient jamais le backend. Il appelle l’API distante via `fetch()`.

## Pages frontend

- `index.html` — accueil
- `explore.html` — bibliothèque publique
- `studio.html` — éditeur et gestion des livres
- `import.html` — Google Drive et import local
- `reader.html?slug=...` — lecture publique
- `login.html` — connexion Google
- `register.html` — création de compte Google (même flux OAuth)
- `account.html` — profil et clés API
- `developers.html` — documentation API

L’URL de l’API est centralisée dans `assets/config.js`.

## API PHP

Base utilisée par le frontend :

`https://condescending-driscoll.82-26-80-25.plesk.page`

Routes principales :

- `GET /health`
- `GET /auth/google` — démarre la connexion Google
- `GET /auth/google/callback` — callback Google de connexion
- `POST /auth/google/exchange` — échange le code BookWriter temporaire contre un token Bearer
- `POST /auth/logout`
- `GET /auth/me`
- `GET|POST /books`
- `GET|PATCH|DELETE /books/{id}`
- `POST /books/{id}/publish`
- `POST /books/{id}/unpublish`
- `GET /public/books`
- `GET /public/books/{slug}`
- `GET|POST /keys`
- `DELETE /keys/{id}`
- `GET /google/connect`
- `GET /google/callback`
- `GET /google/files`
- `POST /google/import`

`POST /auth/login` et `POST /auth/register` sont volontairement désactivés et renvoient HTTP 410 : BookWriter n’utilise plus de mot de passe local.

## Déploiement Plesk

Copie **le contenu de `api/` directement dans le document root** de `condescending-driscoll.82-26-80-25.plesk.page`.

```text
httpdocs/
├── index.php
├── init.php
├── google-auth.php
├── google-connect-url.php
├── .htaccess
└── storage/
```

Le dossier `storage/` doit être accessible en écriture par PHP. Le serveur doit avoir `PDO`, `pdo_sqlite` et `cURL` activés.

Variables d’environnement recommandées :

```env
APP_URL=https://condescending-driscoll.82-26-80-25.plesk.page
FRONTEND_URL=https://louisoff84.github.io/BookWriter
CORS_ORIGINS=https://louisoff84.github.io
ACCESS_TOKEN_TTL=604800
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
APP_DEBUG=0
```

## Google Cloud OAuth

Crée un client OAuth **Web application** et ajoute ces deux URI de redirection autorisées :

```text
https://condescending-driscoll.82-26-80-25.plesk.page/auth/google/callback
https://condescending-driscoll.82-26-80-25.plesk.page/google/callback
```

La première sert à l’inscription/connexion BookWriter avec `openid profile email`. La seconde sert uniquement à l’autorisation Google Drive.

## GitHub Pages

Le workflow `.github/workflows/pages.yml` ne publie que les fichiers `*.html` et `assets/`. Le dossier PHP `/api/` reste exclu du site statique.
