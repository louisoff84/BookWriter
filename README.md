# BookWriter

BookWriter est un site pour écrire, importer et publier des livres.

## Architecture

- **Frontend GitHub Pages** : HTML, CSS et JavaScript natifs, sans Bootstrap.
- **Backend PHP** : code source dans `/api/`, hébergé séparément sur Plesk à la racine du domaine.
- **Base de données** : SQLite par défaut (`storage/bookwriter.sqlite`).
- **Google Drive** : OAuth 2.0 en lecture seule pour importer Google Docs, TXT et Markdown.

Le frontend GitHub Pages ne contient pas le backend. Il appelle l’API distante via `fetch()`.

## Pages frontend

- `index.html` — accueil
- `explore.html` — bibliothèque publique
- `studio.html` — éditeur et gestion des livres
- `import.html` — Google Drive et import local
- `reader.html?slug=...` — lecture publique
- `login.html` / `register.html` — authentification
- `account.html` — profil et clés API
- `developers.html` — documentation API

L’URL de l’API est centralisée dans `assets/config.js`.

## API PHP

Base utilisée par le frontend :

`https://condescending-driscoll.82-26-80-25.plesk.page`

Exemples :

- `GET https://condescending-driscoll.82-26-80-25.plesk.page/health`
- `POST https://condescending-driscoll.82-26-80-25.plesk.page/auth/login`
- `GET https://condescending-driscoll.82-26-80-25.plesk.page/public/books`
- `POST https://condescending-driscoll.82-26-80-25.plesk.page/books`

Routes principales :

- `GET /health`
- `POST /auth/register`
- `POST /auth/login`
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

## Déploiement Plesk

Le dossier `api/` est le **code source du backend** dans le repo. Pour l’hébergement Plesk, copie **le contenu de `api/` directement dans le document root** de `condescending-driscoll.82-26-80-25.plesk.page`.

Exemple de document root Plesk :

```text
httpdocs/
├── index.php
├── init.php
├── google-connect-url.php
├── .htaccess
└── storage/
```

Le dossier `storage/` doit être accessible en écriture par PHP. Le serveur doit avoir `PDO`, `pdo_sqlite` et `cURL` activés.

Variables d’environnement recommandées (voir `api/.env.example`) :

```env
APP_URL=https://condescending-driscoll.82-26-80-25.plesk.page
FRONTEND_URL=https://louisoff84.github.io/BookWriter
CORS_ORIGINS=https://louisoff84.github.io
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
APP_DEBUG=0
```

Le routeur PHP accepte les routes directement à la racine du domaine. L’ancien préfixe `/api/` reste compatible pour le callback OAuth existant si nécessaire.

Dans Google Cloud, l’URI OAuth actuellement compatible est :

`https://condescending-driscoll.82-26-80-25.plesk.page/api/google/callback`

## GitHub Pages

Le workflow `.github/workflows/pages.yml` ne publie que les fichiers `*.html` et `assets/`. Le dossier PHP `/api/` reste exclu du site statique.
