(() => {
  'use strict';
  const config = window.BookWriterConfig || {};
  const API_BASE = String(config.API_BASE || '').replace(/\/+$/, '');
  const TOKEN_KEY = 'bw_access_token';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const auth = {
    get token() { return sessionStorage.getItem(TOKEN_KEY) || ''; },
    set token(v) { v ? sessionStorage.setItem(TOKEN_KEY, v) : sessionStorage.removeItem(TOKEN_KEY); }
  };

  function apiUrl(path) {
    const raw = String(path || '').replace(/^\/+/, '');

    // Les vrais fichiers PHP sont appelés directement.
    if (/^[^?]+\.php(?:\?|$)/i.test(raw)) {
      return `${API_BASE}/${raw}`;
    }

    // Toutes les routes JSON passent par api.php : aucun .htaccess requis.
    const [route, query = ''] = raw.split('?', 2);
    const url = new URL(`${API_BASE}/api.php`);
    url.searchParams.set('route', route);
    if (query) {
      const params = new URLSearchParams(query);
      params.forEach((value, key) => url.searchParams.append(key, value));
    }
    return url.toString();
  }

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    if (options.body && !(options.body instanceof FormData) && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    if (auth.token) headers.set('Authorization', `Bearer ${auth.token}`);
    const response = await fetch(apiUrl(path), {...options, headers, credentials: 'omit'});
    const text = await response.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch { data = {error: text || `Erreur HTTP ${response.status}`}; }
    if (!response.ok) {
      if (response.status === 401) auth.token = '';
      const error = new Error(data.error || `Erreur HTTP ${response.status}`);
      error.status = response.status; error.data = data; throw error;
    }
    return data;
  }

  function toast(message, kind = 'info') {
    let stack = $('#toast-stack');
    if (!stack) { stack = document.createElement('div'); stack.id = 'toast-stack'; stack.className = 'toast-stack'; document.body.append(stack); }
    const item = document.createElement('div');
    item.className = `toast ${kind}`; item.textContent = message; stack.append(item);
    requestAnimationFrame(() => item.classList.add('show'));
    setTimeout(() => { item.classList.remove('show'); setTimeout(() => item.remove(), 250); }, 3400);
  }

  function loading(button, state, label = 'Chargement…') {
    if (!button) return;
    if (state) { button.dataset.old = button.textContent; button.disabled = true; button.textContent = label; }
    else { button.disabled = false; button.textContent = button.dataset.old || button.textContent; }
  }

  async function getMe() {
    if (!auth.token) return null;
    try { const d = await api('auth/me'); return d.authenticated ? d.user : null; }
    catch { auth.token = ''; return null; }
  }

  function redirectLogin() {
    const url = new URL('login.html', location.href);
    url.searchParams.set('return', location.pathname.split('/').pop() + location.search);
    location.href = url.href;
  }

  async function requireUser() {
    const user = await getMe();
    if (!user) { redirectLogin(); throw new Error('Authentification requise.'); }
    return user;
  }

  function cover(book) {
    const el = document.createElement('div');
    el.className = 'book-cover'; el.dataset.category = book.category || 'other';
    if (book.cover_url) {
      const img = document.createElement('img');
      img.src = book.cover_url; img.alt = ''; img.loading = 'lazy'; img.referrerPolicy = 'no-referrer';
      img.onerror = () => img.remove(); el.append(img);
    }
    const fallback = document.createElement('div'); fallback.className = 'cover-fallback';
    const title = document.createElement('strong'); title.textContent = book.title || 'Sans titre';
    const author = document.createElement('span'); author.textContent = book.author_name || '';
    fallback.append(title, author); el.append(fallback); return el;
  }

  function bookCard(book) {
    const article = document.createElement('article'); article.className = 'book-card';
    const link = document.createElement('a'); link.className = 'book-card-link'; link.href = `reader.html?slug=${encodeURIComponent(book.slug)}`;
    link.append(cover(book));
    const info = document.createElement('div'); info.className = 'book-card-info';
    const cat = document.createElement('span'); cat.className = 'book-category'; cat.textContent = book.category || 'other';
    const h = document.createElement('h3'); h.textContent = book.title;
    const by = document.createElement('p'); by.textContent = `par ${book.author_name || 'Auteur'}`;
    const desc = document.createElement('p'); desc.className = 'book-description'; desc.textContent = book.description || 'Aucune description.';
    info.append(cat, h, by, desc); link.append(info); article.append(link); return article;
  }

  async function headerAuth() {
    const slots = $$('.auth-state'); if (!slots.length) return;
    const user = await getMe();
    slots.forEach(slot => {
      slot.textContent = ''; const a = document.createElement('a'); a.className = 'button button-ghost';
      a.href = user ? 'account.html' : 'login.html'; a.textContent = user ? user.display_name : 'Connexion'; slot.append(a);
    });
  }

  function setup() {
    $('#mobile-menu')?.addEventListener('click', () => $('#main-nav')?.classList.toggle('open'));
    const key = 'bw_theme'; if (localStorage.getItem(key) === 'light') document.documentElement.dataset.theme = 'light';
    $('#theme-toggle')?.addEventListener('click', () => {
      const light = document.documentElement.dataset.theme === 'light';
      if (light) { delete document.documentElement.dataset.theme; localStorage.setItem(key, 'dark'); }
      else { document.documentElement.dataset.theme = 'light'; localStorage.setItem(key, 'light'); }
    });
    headerAuth();
  }

  window.BW = {config, API_BASE, $, $$, auth, api, apiUrl, toast, loading, getMe, requireUser, redirectLogin, cover, bookCard};
  setup();
})();
