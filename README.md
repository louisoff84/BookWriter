# BookWriter

BookWriter est un frontend statique HTML/CSS/JavaScript, conçu pour être publié sur GitHub Pages.

## Principe

Le frontend ne stocke pas les comptes ou les livres dans `localStorage`. Toutes les données métier passent par le futur backend PHP BookWriter.

API configurée dans `assets/app.js` :

```text
https://condescending-driscoll.82-26-80-25.plesk.page/api
```

Tant que le backend n'est pas déployé, le site reste navigable et affiche des états « API bientôt disponible » pour les fonctions dynamiques.

## Fonctionnalités frontend

- page d'accueil responsive
- bibliothèque publique
- Studio d'écriture
- création, modification, suppression et publication via API
- aperçu lecteur
- import Google Drive préparé via OAuth backend
- import TXT/Markdown envoyé à l'API
- documentation API
- thème clair/sombre
- déploiement GitHub Pages automatique

## Routes API attendues

- `GET /health`
- `GET /auth/me`
- `POST /auth/login`
- `POST /auth/logout`
- `GET /public/books`
- `GET /public/books/{slug}`
- `GET /books`
- `POST /books`
- `GET /books/{id}`
- `PATCH /books/{id}`
- `DELETE /books/{id}`
- `POST /books/{id}/publish`
- `POST /books/{id}/unpublish`
- `GET /google/connect`
- `GET /google/files`
- `POST /google/import`

Le backend PHP dans `/api/` sera finalisé après validation du frontend.
