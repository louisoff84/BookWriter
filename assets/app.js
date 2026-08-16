(() => {
  'use strict';

  const API_BASE = 'https://condescending-driscoll.82-26-80-25.plesk.page/api';
  const state = { publicBooks: [], books: [], currentBook: null, user: null, apiOnline: null, saveTimer: null };
  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];
  const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

  class ApiError extends Error {
    constructor(message, status = 0, payload = null) { super(message); this.status = status; this.payload = payload; }
  }

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    if (options.body && typeof options.body !== 'string' && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    let response;
    try {
      response = await fetch(API_BASE + path, {
        ...options,
        headers,
        credentials: 'include',
        body: options.body && typeof options.body !== 'string' && !(options.body instanceof FormData) ? JSON.stringify(options.body) : options.body
      });
    } catch (_) {
      state.apiOnline = false;
      throw new ApiError('Le backend BookWriter n’est pas encore disponible.');
    }
    state.apiOnline = true;
    const type = response.headers.get('content-type') || '';
    const data = type.includes('application/json') ? await response.json().catch(() => ({})) : await response.text();
    if (!response.ok) throw new ApiError(data?.error || data?.message || `Erreur API ${response.status}`, response.status, data);
    return data;
  }

  const API = {
    health: () => api('/health'),
    me: () => api('/auth/me'),
    login: (email, password) => api('/auth/login', { method: 'POST', body: { email, password } }),
    logout: () => api('/auth/logout', { method: 'POST' }),
    publicBooks: () => api('/public/books'),
    publicBook: slug => api('/public/books/' + encodeURIComponent(slug)),
    books: () => api('/books'),
    book: id => api('/books/' + id),
    createBook: body => api('/books', { method: 'POST', body }),
    updateBook: (id, body) => api('/books/' + id, { method: 'PATCH', body }),
    deleteBook: id => api('/books/' + id, { method: 'DELETE' }),
    publishBook: id => api('/books/' + id + '/publish', { method: 'POST' }),
    unpublishBook: id => api('/books/' + id + '/unpublish', { method: 'POST' }),
    driveFiles: () => api('/google/files'),
    importDrive: fileId => api('/google/import', { method: 'POST', body: { file_id: fileId } }),
    driveConnect: () => API_BASE + '/google/connect'
  };

  function toast(message, type = '') {
    const stack = $('#toast-stack'); if (!stack) return;
    const el = document.createElement('div'); el.className = 'toast ' + type; el.textContent = message; stack.appendChild(el);
    setTimeout(() => el.remove(), 3800);
  }

  function route() {
    const name = (location.hash.match(/^#\/([^/?]+)/)?.[1] || 'home').toLowerCase();
    $$('.page').forEach(p => p.classList.toggle('active', p.dataset.page === name));
    $$('#main-nav [data-route]').forEach(a => a.classList.toggle('active', a.dataset.route === name));
    $('#main-nav')?.classList.remove('open');
    window.scrollTo({ top: 0, behavior: 'instant' });
    if (name === 'explore') loadPublicBooks();
    if (name === 'studio') loadStudio();
    if (name === 'integrations') prepareIntegrations();
  }

  function renderBookCard(book) {
    const cover = book.cover_url ? `style="background-image:url('${esc(book.cover_url)}')"` : '';
    const title = esc(book.title || 'Sans titre');
    const author = esc(book.author_name || book.author || 'Auteur');
    const cat = esc(book.category || 'other');
    return `<article class="book-card" data-slug="${esc(book.slug || '')}" data-category="${cat}">
      <div class="book-cover-art" ${cover}><span>${title.slice(0,1)}</span></div>
      <div class="book-info"><div class="book-meta"><span>${author}</span><span>${cat}</span></div><h3>${title}</h3><p>${esc(book.description || 'Aucune description.')}</p></div>
    </article>`;
  }

  function bindReaderCards(root) {
    $$('.book-card', root).forEach(card => card.addEventListener('click', () => openReader(card.dataset.slug)));
  }

  async function loadPublicBooks(force = false) {
    if (state.publicBooks.length && !force) { renderPublicBooks(); return; }
    try {
      const data = await API.publicBooks(); state.publicBooks = data.books || []; renderPublicBooks();
    } catch (e) {
      state.publicBooks = [];
      renderPublicBooks();
      if (location.hash.includes('/explore')) toast(e.message, 'error');
    }
  }

  function renderPublicBooks() {
    const featured = $('#featured-books');
    if (featured) {
      featured.innerHTML = state.publicBooks.length ? state.publicBooks.slice(0, 3).map(renderBookCard).join('') : offlineEmpty('Les publications apparaîtront ici dès que l’API sera en ligne.');
      bindReaderCards(featured);
    }
    filterExplore();
  }

  function filterExplore() {
    const root = $('#explore-books'); if (!root) return;
    const q = ($('#book-search')?.value || '').trim().toLowerCase();
    const active = $('#filter-chips .active')?.dataset.filter || 'all';
    const filtered = state.publicBooks.filter(b => {
      const hay = `${b.title || ''} ${b.author_name || ''} ${b.description || ''}`.toLowerCase();
      const cat = b.category || 'other';
      return (!q || hay.includes(q)) && (active === 'all' || cat === active);
    });
    root.innerHTML = filtered.map(renderBookCard).join('');
    bindReaderCards(root);
    $('#explore-empty')?.classList.toggle('hidden', filtered.length !== 0 || state.publicBooks.length === 0);
    if (!state.publicBooks.length) root.innerHTML = offlineEmpty('La bibliothèque publique sera alimentée par l’API PHP.');
  }

  function offlineEmpty(text) { return `<div class="empty-state" style="grid-column:1/-1"><div>⌁</div><h3>API bientôt disponible</h3><p>${esc(text)}</p></div>`; }

  async function openReader(slug) {
    if (!slug) return;
    try {
      let book = state.publicBooks.find(b => b.slug === slug);
      if (!book || !book.content) { const data = await API.publicBook(slug); book = data.book; }
      $('#reader-title').textContent = book.title || 'Sans titre';
      $('#reader-author').textContent = `par ${book.author_name || 'Auteur'}`;
      $('#reader-description').textContent = book.description || '';
      $('#reader-category').textContent = (book.category || 'LIVRE').toUpperCase();
      $('#reader-text').textContent = book.content || '';
      const cover = $('#reader-cover'); cover.style.backgroundImage = book.cover_url ? `url('${book.cover_url}')` : '';
      $('#reader-modal').classList.remove('hidden');
    } catch (e) { toast(e.message, 'error'); }
  }

  async function loadStudio() {
    const empty = $('#editor-empty');
    try {
      const me = await API.me();
      if (!me.authenticated) {
        state.user = null; state.books = []; renderStudioList(); showEditorEmpty('Connectez-vous via le futur backend pour gérer vos livres.'); return;
      }
      state.user = me.user;
      const data = await API.books(); state.books = data.books || []; renderStudioList();
      if (state.currentBook) selectBook(state.currentBook.id);
      else if (state.books[0]) selectBook(state.books[0].id);
      else if (empty) empty.classList.remove('hidden');
    } catch (e) {
      state.user = null; state.books = []; renderStudioList(); showEditorEmpty('Le Studio sera actif dès que l’API PHP sera déployée.');
    }
  }

  function showEditorEmpty(message) {
    $('#editor-workspace')?.classList.add('hidden');
    const empty = $('#editor-empty'); if (!empty) return; empty.classList.remove('hidden');
    const p = $('p', empty); if (p) p.textContent = message;
  }

  function renderStudioList() {
    const root = $('#studio-book-list'); if (!root) return;
    $('#draft-count').textContent = state.books.filter(b => b.status !== 'published').length;
    $('#published-count').textContent = state.books.filter(b => b.status === 'published').length;
    root.innerHTML = state.books.length ? state.books.map(b => `<div class="studio-book-item ${state.currentBook?.id == b.id ? 'active' : ''}" data-book-id="${b.id}"><strong>${esc(b.title || 'Sans titre')}</strong><span>${b.status === 'published' ? 'Publié' : 'Brouillon'}</span></div>`).join('') : '<div class="empty-state"><p>Aucun livre chargé.</p></div>';
    $$('.studio-book-item', root).forEach(el => el.addEventListener('click', () => selectBook(el.dataset.bookId)));
  }

  async function selectBook(id) {
    try {
      const data = await API.book(id); state.currentBook = data.book; fillEditor(); renderStudioList();
    } catch (e) { toast(e.message, 'error'); }
  }

  function fillEditor() {
    const b = state.currentBook; if (!b) return;
    $('#editor-empty')?.classList.add('hidden'); $('#editor-workspace')?.classList.remove('hidden');
    $('#edit-title').value = b.title || ''; $('#edit-description').value = b.description || ''; $('#edit-content').value = b.content || '';
    if ($('#edit-category')) $('#edit-category').value = b.category || 'other';
    setStatus(b.status); updateCounts();
  }

  function setStatus(status) {
    const pill = $('#editor-status'); if (!pill) return;
    pill.classList.toggle('published', status === 'published'); pill.innerHTML = `<span></span>${status === 'published' ? 'Publié' : 'Brouillon'}`;
    const pub = $('#publish-book'); if (pub) pub.textContent = status === 'published' ? 'Dépublier' : 'Publier';
  }

  function updateCounts() {
    const text = $('#edit-content')?.value || ''; const words = (text.trim().match(/\S+/g) || []).length;
    $('#word-count').textContent = `${words} mot${words > 1 ? 's' : ''}`; $('#read-time').textContent = `${Math.max(1, Math.ceil(words / 220))} min de lecture`;
  }

  function queueSave() {
    updateCounts(); if (!state.currentBook) return;
    $('#save-indicator').textContent = 'Modifications en attente…'; clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(saveCurrentBook, 800);
  }

  async function saveCurrentBook() {
    if (!state.currentBook) return;
    const body = { title: $('#edit-title').value.trim() || 'Sans titre', description: $('#edit-description').value.trim(), content: $('#edit-content').value, category: $('#edit-category')?.value || 'other' };
    $('#save-indicator').textContent = 'Enregistrement via API…';
    try {
      const data = await API.updateBook(state.currentBook.id, body); state.currentBook = data.book;
      const idx = state.books.findIndex(b => b.id == data.book.id); if (idx >= 0) state.books[idx] = data.book;
      renderStudioList(); $('#save-indicator').textContent = 'Toutes les modifications sont enregistrées';
    } catch (e) { $('#save-indicator').textContent = 'API indisponible'; toast(e.message, 'error'); }
  }

  async function createBookFromApi(initial = {}) {
    try {
      const data = await API.createBook({ title: initial.title || 'Nouveau livre', description: initial.description || '', content: initial.content || '' });
      state.books.unshift(data.book); state.currentBook = data.book; renderStudioList(); fillEditor(); location.hash = '#/studio'; toast('Livre créé.', 'success');
    } catch (e) { toast(e.message, 'error'); }
  }

  async function publishToggle() {
    if (!state.currentBook) return;
    await saveCurrentBook();
    try {
      const data = state.currentBook.status === 'published' ? await API.unpublishBook(state.currentBook.id) : await API.publishBook(state.currentBook.id);
      state.currentBook = data.book; setStatus(data.book.status); const idx = state.books.findIndex(b => b.id == data.book.id); if (idx >= 0) state.books[idx] = data.book; renderStudioList(); toast(data.book.status === 'published' ? 'Livre publié !' : 'Livre repassé en brouillon.', 'success'); loadPublicBooks(true);
    } catch (e) { toast(e.message, 'error'); }
  }

  async function deleteCurrent() {
    if (!state.currentBook || !confirm('Supprimer définitivement ce livre ?')) return;
    try { await API.deleteBook(state.currentBook.id); state.books = state.books.filter(b => b.id != state.currentBook.id); state.currentBook = null; renderStudioList(); showEditorEmpty('Sélectionnez un livre ou créez-en un nouveau.'); toast('Livre supprimé.', 'success'); } catch (e) { toast(e.message, 'error'); }
  }

  function prepareIntegrations() {
    const card = $('.featured-integration .integration-copy p');
    if (card) card.textContent = 'La connexion OAuth Google et l’import des documents passeront exclusivement par l’API PHP BookWriter.';
  }

  async function importLocalFile(file) {
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) return toast('Fichier trop volumineux.', 'error');
    try {
      const content = await file.text(); const title = file.name.replace(/\.(txt|md)$/i, '') || 'Document importé';
      await createBookFromApi({ title, content });
    } catch (e) { toast('Impossible de lire ce fichier.', 'error'); }
  }

  function patchApiFirstCopy() {
    const step = $$('.steps article')[0];
    $$('.steps article p').forEach(p => { if (p.textContent.includes('enregistrés localement')) p.textContent = 'Un éditeur confortable avec sauvegarde de vos brouillons directement via l’API BookWriter.'; });
    const auth = $('#auth-modal');
    if (auth) {
      const p = $('p', auth); const btn = $('#demo-login'); const small = $('small', auth);
      if (p) p.textContent = 'La connexion réelle sera gérée par le backend PHP BookWriter. Aucun compte fictif n’est stocké dans le navigateur.';
      if (btn) btn.textContent = 'Connexion disponible avec l’API';
      if (small) small.textContent = 'Le frontend GitHub Pages ne stocke ni compte ni livre localement.';
    }
    const localCard = $$('.integration-card')[1];
    if (localCard) {
      const p = $('.integration-copy p', localCard); if (p) p.textContent = 'Choisissez un fichier texte ou Markdown : il sera envoyé à l’API pour créer un brouillon, sans stockage local.';
    }
  }

  function wireEvents() {
    window.addEventListener('hashchange', route);
    $('#mobile-menu')?.addEventListener('click', () => $('#main-nav')?.classList.toggle('open'));
    $('#theme-toggle')?.addEventListener('click', () => document.body.classList.toggle('light'));
    $('#login-button')?.addEventListener('click', () => $('#auth-modal')?.classList.remove('hidden'));
    $('#demo-login')?.addEventListener('click', () => toast('Le formulaire de connexion sera activé avec le backend PHP.', 'error'));
    $$('[data-close]').forEach(btn => btn.addEventListener('click', () => $('#' + btn.dataset.close)?.classList.add('hidden')));
    $$('.modal-backdrop').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.add('hidden'); }));
    $('#book-search')?.addEventListener('input', filterExplore);
    $$('#filter-chips button').forEach(btn => btn.addEventListener('click', () => { $$('#filter-chips button').forEach(b => b.classList.remove('active')); btn.classList.add('active'); filterExplore(); }));
    $('#new-book')?.addEventListener('click', () => createBookFromApi()); $('#new-book-empty')?.addEventListener('click', () => createBookFromApi());
    ['#edit-title','#edit-description','#edit-content','#edit-category'].forEach(s => $(s)?.addEventListener('input', queueSave));
    $('#publish-book')?.addEventListener('click', publishToggle); $('#delete-book')?.addEventListener('click', deleteCurrent);
    $('#preview-book')?.addEventListener('click', () => { if (!state.currentBook) return; const temp = {...state.currentBook,title:$('#edit-title').value,description:$('#edit-description').value,content:$('#edit-content').value,category:$('#edit-category')?.value}; state.publicBooks.push(temp); openReader(temp.slug || 'preview'); state.publicBooks.pop(); });
    $('#connect-drive')?.addEventListener('click', () => { window.location.href = API.driveConnect(); });
    $('#import-local-button')?.addEventListener('click', () => $('#local-file-input')?.click());
    $('#local-file-input')?.addEventListener('change', e => importLocalFile(e.target.files?.[0]));
    $$('[data-copy]').forEach(btn => btn.addEventListener('click', async () => { try { await navigator.clipboard.writeText(btn.dataset.copy); toast('Copié.', 'success'); } catch { toast('Copie impossible.', 'error'); } }));
  }

  async function boot() {
    patchApiFirstCopy(); wireEvents(); route();
    try {
      await API.health(); state.apiOnline = true;
      const me = await API.me().catch(() => null); state.user = me?.user || null;
      if (state.user && $('#login-button')) $('#login-button').textContent = state.user.display_name || 'Mon compte';
    } catch (_) { state.apiOnline = false; }
    loadPublicBooks();
  }

  boot();
})();
