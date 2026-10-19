(() => {
  'use strict';

  const root = document.querySelector('.nobash-files-wrap');
  if (!root || typeof NobashFiles === 'undefined') return;

  // Browsers may deny storage in private or embedded admin sessions.
  const readPreference = key => {
    try { return localStorage.getItem(key); } catch (_) { return null; }
  };
  const writePreference = (key, value) => {
    try { localStorage.setItem(key, value); } catch (_) { /* Keep this session usable. */ }
  };

  const view = root.dataset.view;
  let currentPath = '';
  let currentFile = null;
  let originalContent = '';
  let currentItems = [];
  let directoryCursor = null;
  let directoryHasMore = false;
  let directoryPageSize = Number(NobashFiles.directoryPageSize || 250);
  let selected = new Set();
  let modalResolver = null;
  let preferences = {favorites: [], recent: [], favorite_paths: []};
  let favoritePaths = new Set();
  let explorerView = readPreference('nfmExplorerView') === 'grid' ? 'grid' : 'list';
  let sortMode = readPreference('nfmSortMode') || 'name-asc';
  let searchTimer = null;
  let searchAbort = null;
  let smartView = null;
  let navHistory = [];
  let navIndex = -1;
  let activeUpload = null;
  let uploadDragDepth = 0;
  const access = NobashFiles.access || {isAdministrator:true, home:'', roots:[''], deniedPaths:[], permissions:{}};
  const accessHome = access.home || '';
  const pathWithin = (path, root) => !root || path === root || `${path}/`.startsWith(`${root}/`);
  const scopeAllows = (scope, path) => {
    const roots = Array.isArray(scope?.roots) ? scope.roots : [];
    const denied = Array.isArray(scope?.deniedPaths) ? scope.deniedPaths : [];
    return roots.some(root => pathWithin(path, String(root || ''))) && !denied.some(root => pathWithin(path, String(root || '')));
  };
  const hasPerm = permission => {
    if (access.isAdministrator) return true;
    const scopes = access.permissionScopes?.[permission];
    if (!Array.isArray(scopes)) return Boolean(!access.permissions || access.permissions[permission] !== false);
    return scopes.length > 0;
  };
  const clientPathAllowed = path => {
    path = String(path || '').replace(/^\/+|\/+$/g, '');
    if (access.isAdministrator) return true;
    const browseScopes = access.permissionScopes?.browse;
    if (Array.isArray(browseScopes)) return browseScopes.some(scope => scopeAllows(scope, path));
    const roots = Array.isArray(access.roots) ? access.roots : [];
    const denied = Array.isArray(access.deniedPaths) ? access.deniedPaths : [];
    return roots.some(root => pathWithin(path, String(root || ''))) && !denied.some(root => pathWithin(path, String(root || '')));
  };

  const qs = (s, ctx = document) => ctx.querySelector(s);
  const qsa = (s, ctx = document) => [...ctx.querySelectorAll(s)];
  const esc = (s = '') => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmtSize = n => n == null ? '—' : n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : n < 1073741824 ? `${(n / 1048576).toFixed(1)} MB` : `${(n / 1073741824).toFixed(2)} GB`;
  const fmtDate = ts => ts ? new Date(ts * 1000).toLocaleString() : '—';
  const parentPath = path => !path || !path.includes('/') ? '' : path.split('/').slice(0, -1).join('/');
  const basename = path => (path || '').split('/').pop() || '';
  const extensionOf = path => {
    const name = basename(path);
    const idx = name.lastIndexOf('.');
    return idx > 0 ? name.slice(idx + 1).toLowerCase() : '';
  };
  const isArchivePath = path => { const n = basename(path).toLowerCase(); return n.endsWith('.zip') || n.endsWith('.tar') || n.endsWith('.tar.gz') || n.endsWith('.tgz'); };

  async function api(path, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const isGet = method === 'GET';
    const requestOnce = async (attempt = 0) => {
      const headers = {'X-WP-Nonce': NobashFiles.nonce, 'Accept':'application/json', ...(options.headers || {})};
      if (!(options.body instanceof FormData) && options.body !== undefined) headers['Content-Type'] = 'application/json';
      let url = NobashFiles.rest + path;
      if (isGet) {
        const joiner = url.includes('?') ? '&' : '?';
        url += `${joiner}_nobash_files_cb=${Date.now()}_${attempt}`;
      }
      const res = await fetch(url, {...options, method, headers, cache:'no-store', credentials:'same-origin'});
      const raw = await res.text();
      let data = null;
      try { data = raw ? JSON.parse(raw) : null; } catch (_) {}
      if (!res.ok) {
        const err = new Error((data && typeof data === 'object' && data.message) ? data.message : `Request failed (HTTP ${res.status}).`);
        err.code = data && typeof data === 'object' ? (data.code || 'nobash_files_error') : 'nobash_files_invalid_response';
        err.status = data && typeof data === 'object' ? (data?.data?.status || res.status) : res.status;
        throw err;
      }
      if (data === null || (typeof data !== 'object')) {
        if (isGet && attempt === 0) return requestOnce(1);
        const type = String(res.headers.get('content-type') || '').toLowerCase();
        const hint = type.includes('text/html') ? ' WordPress returned an HTML page instead of REST data; a cache, security, or redirect layer may be intercepting the request.' : '';
        throw new Error(`Nobash received an invalid REST response.${hint}`);
      }
      return data;
    };
    return requestOnce(0);
  }



  let bootstrapDirectory = (NobashFiles.bootstrapDirectory && typeof NobashFiles.bootstrapDirectory === 'object') ? NobashFiles.bootstrapDirectory : null;
  let bootstrapConsumed = false;

  function decodeExplorerFrame(raw) {
    const start = '<<<NOBASH_FILES_EXPLORER_V1>>>';
    const end = '<<<END_NOBASH_FILES_EXPLORER_V1>>>';
    const a = String(raw || '').indexOf(start);
    const b = String(raw || '').indexOf(end, a >= 0 ? a + start.length : 0);
    if (a < 0 || b < 0 || b <= a) return null;
    const encoded = String(raw).slice(a + start.length, b).trim();
    if (!encoded) return null;
    try {
      const binary = atob(encoded);
      const bytes = Uint8Array.from(binary, ch => ch.charCodeAt(0));
      const text = new TextDecoder('utf-8').decode(bytes);
      return JSON.parse(text);
    } catch (_) {
      return null;
    }
  }

  async function explorerRequest(path = '', cursor = 0, limit = 0) {
    if (!NobashFiles.explorerUrl || !NobashFiles.explorerNonce) throw new Error('Nobash Files Explorer transport is unavailable.');
    const params = new URLSearchParams();
    params.set('action', 'nobash_files_explorer_list');
    params.set('nonce', NobashFiles.explorerNonce);
    params.set('path', String(path || ''));
    params.set('cursor', String(cursor || '0'));
    params.set('limit', String(Math.max(0, Number(limit || 0))));
    params.set('_nobash_files_cb', `${Date.now()}_${Math.random().toString(36).slice(2)}`);
    const res = await fetch(NobashFiles.explorerUrl, {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      redirect:'follow',
      headers:{'Accept':'text/plain,*/*;q=0.8','Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:params.toString()
    });
    const raw = await res.text();
    const frame = decodeExplorerFrame(raw);
    if (!frame || typeof frame !== 'object') {
      const error = new Error(`Nobash Files Explorer gateway did not return a framed response (HTTP ${res.status}).`);
      error.code = 'nobash_files_explorer_transport_failed';
      error.status = res.status;
      throw error;
    }
    if (!frame.ok || !frame.data || typeof frame.data !== 'object') {
      const error = new Error(frame?.error?.message || 'Unable to load this folder.');
      error.code = frame?.error?.code || 'nobash_files_explorer_error';
      error.status = frame?.error?.status || res.status;
      throw error;
    }
    return frame.data;
  }

  function bootstrapFor(path = '', cursor = 0) {
    if (bootstrapConsumed || String(cursor || '0') !== '0' || !bootstrapDirectory || typeof bootstrapDirectory !== 'object') return null;
    if (!bootstrapDirectory.ok || !bootstrapDirectory.data || typeof bootstrapDirectory.data !== 'object') return null;
    const data = bootstrapDirectory.data;
    if (String(data.path || '') !== String(path || '')) return null;
    bootstrapConsumed = true;
    return data;
  }

  function fullPageFolder(path = '') {
    if (!['manager','editor'].includes(view)) return false;
    const base = view === 'editor' ? (NobashFiles.editorUrl || '') : (NobashFiles.managerUrl || '');
    if (!base) return false;
    const joiner = base.includes('?') ? '&' : '?';
    window.location.assign(`${base}${joiner}nobash_files_path=${encodeURIComponent(String(path || ''))}`);
    return true;
  }

  async function listDirectory(path = '', cursor = 0, limit = 0) {
    const boot = bootstrapFor(path, cursor);
    if (boot) return boot;
    return explorerRequest(path, cursor, limit);
  }


  function notice(message, type = 'success') {
    const el = qs('#nobash-files-notice');
    if (!el) return;
    el.hidden = false;
    el.className = `nobash-files-notice ${type}`;
    el.textContent = message;
    clearTimeout(notice.t);
    notice.t = setTimeout(() => { el.hidden = true; }, 4600);
  }

  function iconFor(item) {
    if (item.type === 'directory') return 'dashicons-category';
    const ext = (item.extension || extensionOf(item.path) || '').toLowerCase();
    if (['png','jpg','jpeg','gif','webp','svg','avif'].includes(ext)) return 'dashicons-format-image';
    if (['php','js','css','json','html','htm','xml','yml','yaml','md','txt','ini','conf'].includes(ext)) return 'dashicons-editor-code';
    if (['zip','tar','gz','tgz','7z'].includes(ext)) return 'dashicons-media-archive';
    if (['mp3','wav','m4a','ogg'].includes(ext)) return 'dashicons-format-audio';
    if (['mp4','mov','webm','mkv','ogv'].includes(ext)) return 'dashicons-format-video';
    if (ext === 'pdf') return 'dashicons-pdf';
    return 'dashicons-media-default';
  }

  function normalizeItem(item) {
    return {...item, extension: item.extension || extensionOf(item.path), writable: item.writable !== false};
  }

  function breadcrumbs(path, label = '') {
    const el = qs('#nobash-files-breadcrumb');
    if (!el) return;
    const homePath = access.isAdministrator ? '' : accessHome;
    const homeLabel = access.isAdministrator ? '' : (basename(homePath) || 'Workspace');
    if (label) {
      el.innerHTML = `<button data-path="${esc(homePath)}" class="nobash-files-crumb" title="${access.isAdministrator ? 'WordPress root' : 'Assigned workspace'}"><span class="dashicons ${access.isAdministrator ? 'dashicons-admin-home' : 'dashicons-lock'}"></span>${homeLabel ? `<span>${esc(homeLabel)}</span>` : ''}</button><span>/</span><span class="nobash-files-crumb-label">${esc(label)}</span>`;
      qsa('.nobash-files-crumb', el).forEach(b => b.addEventListener('click', () => loadDir(b.dataset.path || homePath).catch(() => {})));
      return;
    }
    const normalized = String(path || '').replace(/^\/+|\/+$/g, '');
    const suffix = !access.isAdministrator && homePath && pathWithin(normalized, homePath) ? normalized.slice(homePath.length).replace(/^\/+/, '') : normalized;
    const parts = suffix ? suffix.split('/') : [];
    let acc = homePath;
    const items = [`<button data-path="${esc(homePath)}" class="nobash-files-crumb" title="${access.isAdministrator ? 'WordPress root' : 'Assigned workspace'}"><span class="dashicons ${access.isAdministrator ? 'dashicons-admin-home' : 'dashicons-lock'}"></span>${homeLabel ? `<span>${esc(homeLabel)}</span>` : ''}</button>`];
    parts.forEach(part => {
      acc = acc ? `${acc}/${part}` : part;
      items.push(`<span>/</span><button data-path="${esc(acc)}" class="nobash-files-crumb">${esc(part)}</button>`);
    });
    el.innerHTML = items.join('');
    qsa('.nobash-files-crumb', el).forEach(b => b.addEventListener('click', () => loadDir(b.dataset.path || homePath).catch(() => {})));
  }

  function modalVisual(title = '', danger = false) {
    const value = String(title || '').toLowerCase();
    if (danger || /delete|remove|trash|overwrite|replace|discard|rollback/.test(value)) return {icon:'dashicons-warning', tone:'danger'};
    if (/protected|safe|restore|baseline|certify|validate/.test(value)) return {icon:'dashicons-shield-alt', tone:'safe'};
    if (/folder|copy|move|rename/.test(value)) return {icon:'dashicons-open-folder', tone:'folder'};
    return {icon:'dashicons-admin-generic', tone:'default'};
  }

  function confirmIcon(confirmText = '', danger = false) {
    const value = String(confirmText || '').toLowerCase();
    if (danger || /delete|remove|trash|overwrite|discard/.test(value)) return 'dashicons-trash';
    if (/restore|rollback|reload/.test(value)) return 'dashicons-backup';
    if (/folder/.test(value)) return 'dashicons-category';
    if (/copy/.test(value)) return 'dashicons-admin-page';
    if (/move|rename/.test(value)) return 'dashicons-move';
    if (/certif|validate|safe|continue/.test(value)) return 'dashicons-yes-alt';
    return 'dashicons-yes-alt';
  }

  function setModalOpen(open) {
    document.documentElement.classList.toggle('nobash-files-modal-open', !!open);
  }

  function showModal({title = 'Continue?', message = '', label = 'Value', inputLabel = '', value = '', placeholder = '', confirmText = 'Continue', danger = false, input = true, icon = ''} = {}) {
    const modal = qs('#nobash-files-modal');
    if (!modal) return Promise.resolve({confirmed:false, value:''});
    const visual = modalVisual(title, danger);
    const panel = modal.querySelector('.nobash-files-modal');
    const iconBox = qs('#nobash-files-modal-icon');
    const iconGlyph = qs('#nobash-files-modal-icon-glyph');
    const resolvedLabel = inputLabel || label;
    qs('#nobash-files-modal-title').textContent = title;
    qs('#nobash-files-modal-message').textContent = message;
    qs('#nobash-files-modal-message').hidden = !message;
    qs('#nobash-files-modal-input-label').textContent = resolvedLabel;
    const wrap = qs('#nobash-files-modal-input-wrap');
    const body = modal.querySelector('.nobash-files-modal-body');
    const field = qs('#nobash-files-modal-input');
    wrap.hidden = !input;
    if (body) body.hidden = !input;
    field.value = value;
    field.placeholder = placeholder || '';
    const confirmButton = qs('#nobash-files-modal-confirm');
    qs('#nobash-files-modal-confirm-label').textContent = confirmText;
    qs('#nobash-files-modal-confirm-icon').className = `dashicons ${confirmIcon(confirmText, danger)}`;
    confirmButton.classList.toggle('nobash-files-danger-button', danger);
    panel?.classList.toggle('is-danger', danger);
    if (iconBox) iconBox.dataset.tone = visual.tone;
    if (iconGlyph) iconGlyph.className = `dashicons ${icon || visual.icon}`;
    modal.hidden = false;
    setModalOpen(true);
    requestAnimationFrame(() => input ? field.focus() : confirmButton.focus());
    return new Promise(resolve => { modalResolver = resolve; });
  }

  function closeModal(confirmed) {
    const modal = qs('#nobash-files-modal');
    if (!modal || !modalResolver) return;
    const resolve = modalResolver;
    modalResolver = null;
    const value = qs('#nobash-files-modal-input')?.value || '';
    modal.hidden = true;
    setModalOpen(false);
    resolve({confirmed, value});
  }

  function trapDialogTab(modal, e) {
    if (e.key !== 'Tab') return;
    const focusable = qsa('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]', modal).filter(el => !el.hidden && el.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function initModal() {
    qs('#nobash-files-modal-cancel')?.addEventListener('click', () => closeModal(false));
    qs('#nobash-files-modal-close')?.addEventListener('click', () => closeModal(false));
    qs('#nobash-files-modal-confirm')?.addEventListener('click', () => closeModal(true));
    qs('#nobash-files-modal')?.addEventListener('click', e => { if (e.target.id === 'nobash-files-modal') closeModal(false); });
    qs('#nobash-files-modal')?.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.preventDefault(); closeModal(false); return; }
      trapDialogTab(qs('#nobash-files-modal'), e);
    });
    qs('#nobash-files-modal-input')?.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); closeModal(true); }
    });
  }

  async function confirmProtected() {
    const result = await showModal({
      title: 'Protected WordPress area',
      message: 'This action changes a protected WordPress file or folder. Continue only if you understand the change. Nobash will keep the operation recoverable where supported.',
      confirmText: 'Continue safely',
      danger: true,
      input: false
    });
    return result.confirmed;
  }

  function sortedItems(items) {
    const arr = items.map(normalizeItem);
    const [field, direction] = sortMode.split('-');
    const sign = direction === 'desc' ? -1 : 1;
    arr.sort((a, b) => {
      if (a.type !== b.type) return a.type === 'directory' ? -1 : 1;
      if (field === 'modified') return ((Number(a.modified) || 0) - (Number(b.modified) || 0)) * sign;
      if (field === 'size') return ((Number(a.size) || 0) - (Number(b.size) || 0)) * sign;
      return a.name.localeCompare(b.name, undefined, {numeric:true, sensitivity:'base'}) * sign;
    });
    return arr;
  }

  async function loadDir(path = '', append = false, historyMode = 'push') {
    try {
      const cursor = append && directoryCursor !== null ? directoryCursor : '0';
      const data = await listDirectory(path, cursor, directoryPageSize);
      const validDirectoryPayload = data && !Array.isArray(data) && typeof data.path === 'string' && Array.isArray(data.items) && Object.prototype.hasOwnProperty.call(data, 'parent');
      if (!validDirectoryPayload) {
        throw new Error('Directory data was incomplete. Nobash kept the current view unchanged; use Refresh to retry.');
      }
      smartView = null;
      currentPath = data.path || '';
      if (!append && historyMode !== 'skip') {
        if (navHistory[navIndex] !== currentPath) {
          navHistory = navHistory.slice(0, navIndex + 1);
          navHistory.push(currentPath);
          navIndex = navHistory.length - 1;
        }
      }
      updateNavigationButtons();
      updateStorageStatus(data.storage || null);
      const incoming = (data.items || []).map(normalizeItem);
      if (append) {
        const merged = new Map(currentItems.map(item => [item.path, item]));
        incoming.forEach(item => merged.set(item.path, item));
        currentItems = [...merged.values()];
      } else {
        currentItems = incoming;
        selected.clear();
      }
      directoryHasMore = Boolean(data.has_more || data.truncated);
      directoryCursor = data.next_cursor === null || data.next_cursor === undefined ? null : String(data.next_cursor);
      ['#nobash-files-load-more','#nobash-files-tree-load-more'].forEach(sel => { const button=qs(sel); if(button){button.hidden=!directoryHasMore;button.disabled=!directoryHasMore;} });
      const treeMoreWrap = qs('.nobash-files-tree-more'); if (treeMoreWrap) treeMoreWrap.hidden = !directoryHasMore;
      breadcrumbs(currentPath);
      renderTree({...data, items: currentItems});
      if (view === 'manager') {
        updateMutationAvailability();
        renderFiles();
        renderEmptyDetails();
        updateSelectionUI();
        updateFolderFavoriteButton();
        const state = qs('#nobash-files-folder-state');
        if (state) state.textContent = data.writable ? (directoryHasMore ? `Large folder · ${currentItems.length} items loaded safely` : 'Folder is writable · safe operations enabled') : 'Folder is read only';
      }
      return data;
    } catch (e) {
      const transportFailure = e && ['nobash_files_explorer_transport_failed'].includes(String(e.code || ''));
      if (transportFailure && !append && fullPageFolder(path)) {
        return new Promise(() => {});
      }
      notice(e.message || 'Unable to load this folder.', 'error');
      throw e;
    }
  }

  const protectedMarker = '<span class="nobash-files-protected-shield" role="img" aria-label="Protected" title="Protected WordPress file or folder"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M12 3.2c2.4 1.8 4.8 2.7 7.2 3v5.7c0 4.1-2.6 7.1-7.2 9-4.6-1.9-7.2-4.9-7.2-9V6.2c2.4-.3 4.8-1.2 7.2-3Z" fill="currentColor" fill-opacity=".08" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m8.8 11.8 2.1 2.1 4.4-4.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';

  function renderTree(data) {
    const el = qs('#nobash-files-tree');
    if (!el) return;
    const rootActive = data.path === '';
    let html = `<button class="nobash-files-tree-row nobash-files-tree-root ${rootActive ? 'is-active' : ''}" data-path=""><span class="nobash-files-tree-leading nobash-files-tree-globe dashicons dashicons-admin-site-alt3"></span><span>/ (root)</span><span class="nobash-files-tree-chevron dashicons dashicons-arrow-right-alt2"></span></button>`;
    if (data.parent !== null) html += `<button class="nobash-files-tree-row nobash-files-tree-up" data-path="${esc(data.parent || '')}"><span class="nobash-files-tree-leading dashicons dashicons-arrow-up-alt2"></span><span>Up one level</span><span class="nobash-files-tree-chevron dashicons dashicons-arrow-right-alt2"></span></button>`;
    (data.items || []).filter(i => i.type === 'directory').forEach(i => {
      html += `<button class="nobash-files-tree-row ${i.path === data.path ? 'is-active' : ''}" data-path="${esc(i.path)}"><span class="nobash-files-tree-leading dashicons dashicons-category"></span><span class="nobash-files-tree-name" title="${esc(i.name)}">${esc(i.name)}</span>${i.protected ? protectedMarker : ''}<span class="nobash-files-tree-chevron dashicons dashicons-arrow-right-alt2"></span></button>`;
    });
    if (view === 'editor') {
      (data.items || []).filter(i => i.type === 'file' && i.editable).forEach(i => {
        html += `<button class="nobash-files-tree-row nobash-files-tree-file" data-file="${esc(i.path)}"><span class="nobash-files-tree-leading dashicons ${iconFor(i)}"></span><span class="nobash-files-tree-name" title="${esc(i.name)}">${esc(i.name)}</span>${i.protected ? protectedMarker : ''}</button>`;
      });
    }
    el.innerHTML = html;
    qsa('[data-path]', el).forEach(b => b.addEventListener('click', () => loadDir(b.dataset.path || '').catch(() => {})));
    qsa('[data-file]', el).forEach(b => b.addEventListener('click', () => openEditorFile(b.dataset.file)));
  }

  function updateNavigationButtons() {
    const back = qs('#nobash-files-nav-back');
    const forward = qs('#nobash-files-nav-forward');
    if (back) back.disabled = navIndex <= 0;
    if (forward) forward.disabled = navIndex < 0 || navIndex >= navHistory.length - 1;
  }

  function updateStorageStatus(storage) {
    const text = qs('#nobash-files-storage-text');
    const bar = qs('#nobash-files-storage-progress');
    if (!text || !bar) return;
    if (!storage || !Number(storage.total)) {
      text.textContent = access.isAdministrator ? 'Server managed' : 'Workspace';
      bar.style.width = '0%';
      return;
    }
    text.textContent = `${fmtSize(Number(storage.used || 0))} / ${fmtSize(Number(storage.total || 0))}`;
    bar.style.width = `${Math.max(0, Math.min(100, Number(storage.percent || 0)))}%`;
  }

  function renderFiles() {
    if (view !== 'manager') return;
    const items = sortedItems(currentItems);
    renderList(items);
    renderGrid(items);
    applyExplorerView();
    const count = qs('#nobash-files-count');
    if (count) count.textContent = `${items.length}${directoryHasMore ? '+' : ''} item${items.length === 1 && !directoryHasMore ? '' : 's'} loaded`;
    const more = qs('#nobash-files-load-more');
    if (more) { more.hidden = !directoryHasMore; more.disabled = !directoryHasMore; }
  }

  function renderList(items) {
    const body = qs('#nobash-files-files');
    if (!body) return;
    body.innerHTML = items.map(i => `<tr data-type="${i.type}" data-path="${esc(i.path)}" class="${selected.has(i.path) ? 'is-selected' : ''}">
      <td class="nobash-files-check-col"><input type="checkbox" class="nobash-files-item-check" data-path="${esc(i.path)}" ${selected.has(i.path) ? 'checked' : ''} aria-label="Select ${esc(i.name)}"></td>
      <td><span class="nobash-files-file-name"><span class="nobash-files-file-icon ${i.type === 'directory' ? 'is-folder' : ''}"><span class="dashicons ${iconFor(i)}"></span></span><span class="nobash-files-file-label">${esc(i.name)}</span>${favoritePaths.has(i.path) ? '<span class="dashicons dashicons-star-filled nobash-files-inline-star" title="Favorite"></span>' : ''}</span></td>
      <td>${i.type === 'directory' ? 'Folder' : esc((i.extension || 'File').toUpperCase())}</td>
      <td>${fmtSize(i.size)}</td><td>${fmtDate(i.modified)}</td>
      <td>${i.protected ? protectedMarker : i.writable ? '<span class="nobash-files-badge ready">Ready</span>' : '<span class="nobash-files-badge muted">Read only</span>'}</td>
      <td><button class="nobash-files-row-menu" data-menu-path="${esc(i.path)}" aria-label="Actions for ${esc(i.name)}"><span class="dashicons dashicons-ellipsis"></span></button></td></tr>`).join('') || `<tr><td colspan="7" class="nobash-files-empty-row">This folder is empty.</td></tr>`;
    bindItemEvents(body, 'tr[data-path]');
  }

  function renderGrid(items) {
    const host = qs('#nobash-files-grid-container');
    if (!host) return;
    host.innerHTML = items.length ? items.map(i => `<article class="nobash-files-file-card ${selected.has(i.path) ? 'is-selected' : ''}" data-type="${i.type}" data-path="${esc(i.path)}">
      <div class="nobash-files-file-card-top">
        <label class="nobash-files-card-check"><input type="checkbox" class="nobash-files-item-check" data-path="${esc(i.path)}" ${selected.has(i.path) ? 'checked' : ''} aria-label="Select ${esc(i.name)}"></label>
        <button class="nobash-files-row-menu" data-menu-path="${esc(i.path)}" aria-label="Actions for ${esc(i.name)}"><span class="dashicons dashicons-ellipsis"></span></button>
      </div>
      <div class="nobash-files-file-card-icon"><span class="dashicons ${iconFor(i)}"></span></div>
      <div class="nobash-files-file-card-name" title="${esc(i.name)}">${esc(i.name)}${favoritePaths.has(i.path) ? '<span class="dashicons dashicons-star-filled nobash-files-inline-star"></span>' : ''}</div>
      <div class="nobash-files-file-card-meta"><span>${i.type === 'directory' ? 'Folder' : esc((i.extension || 'File').toUpperCase())}</span><span>${fmtSize(i.size)}</span></div>
      ${i.protected ? protectedMarker : ''}
    </article>`).join('') : '<div class="nobash-files-grid-empty">This folder is empty.</div>';
    bindItemEvents(host, '[data-path]');
  }

  function bindItemEvents(host, selector) {
    qsa(selector, host).forEach(node => {
      node.addEventListener('click', e => {
        if (e.target.closest('input,button,label')) return;
        const path = node.dataset.path;
        if (node.dataset.type === 'directory') loadDir(path).catch(() => {});
        else selectFile(path);
      });
      node.addEventListener('dblclick', e => {
        if (e.target.closest('input,button,label')) return;
        const path = node.dataset.path;
        if (node.dataset.type === 'directory') loadDir(path).catch(() => {});
        else {
          const item = currentItems.find(i => i.path === path);
          if (item?.editable) location.href = `${location.pathname}?page=nobash-files-editor&file=${encodeURIComponent(path)}`;
          else selectFile(path);
        }
      });
      node.addEventListener('contextmenu', e => {
        e.preventDefault();
        const path = node.dataset.path;
        selected.clear();
        selected.add(path);
        renderFiles();
        updateSelectionUI();
        openContextMenu(path, e.clientX, e.clientY);
      });
    });
    qsa('.nobash-files-item-check', host).forEach(check => check.addEventListener('change', e => {
      e.stopPropagation();
      toggleSelection(check.dataset.path, check.checked);
    }));
    qsa('[data-menu-path]', host).forEach(btn => btn.addEventListener('click', e => {
      e.stopPropagation();
      const rect = btn.getBoundingClientRect();
      selected.clear();
      selected.add(btn.dataset.menuPath);
      renderFiles();
      updateSelectionUI();
      openContextMenu(btn.dataset.menuPath, rect.left, rect.bottom + 4);
    }));
  }

  function applyExplorerView() {
    const list = qs('#nobash-files-list-container');
    const grid = qs('#nobash-files-grid-container');
    const listBtn = qs('#nobash-files-list-view');
    const gridBtn = qs('#nobash-files-grid-view');
    if (!list || !grid) return;
    list.hidden = explorerView !== 'list';
    grid.hidden = explorerView !== 'grid';
    listBtn?.classList.toggle('is-active', explorerView === 'list');
    gridBtn?.classList.toggle('is-active', explorerView === 'grid');
  }

  function setExplorerView(next) {
    explorerView = next === 'grid' ? 'grid' : 'list';
    writePreference('nfmExplorerView', explorerView);
    applyExplorerView();
  }

  function toggleSelection(path, checked) {
    if (checked) selected.add(path); else selected.delete(path);
    renderFiles();
    updateSelectionUI();
  }

  function updateSelectionUI() {
    const bar = qs('#nobash-files-selection-bar');
    if (!bar) return;
    const count = selected.size;
    bar.hidden = count === 0;
    const out = qs('#nobash-files-selected-count');
    if (out) out.textContent = `${count} selected`;
    qsa('[data-bulk="rename"],[data-bulk="duplicate"]', bar).forEach(b => { b.disabled = count !== 1; });
    qsa('[data-bulk="extract"]', bar).forEach(b => { b.disabled = count !== 1 || !isArchivePath([...selected][0] || ''); });
    const all = qs('#nobash-files-select-all');
    if (all) {
      all.checked = currentItems.length > 0 && count === currentItems.length;
      all.indeterminate = count > 0 && count < currentItems.length;
    }
  }

  function renderEmptyDetails() {
    const details = qs('#nobash-files-details');
    if (!details) return;
    details.hidden = true;
    root.classList.remove('has-details');
    details.innerHTML = '<div class="nobash-files-empty nobash-files-manager-empty"><div class="nobash-files-empty-folder-art" aria-hidden="true"><span class="dashicons dashicons-category"></span><i></i><b></b></div><strong>Select a file or folder</strong><p>Choose an item to preview it, inspect details, or take a safe action.</p><div class="nobash-files-empty-capabilities"><span><i class="dashicons dashicons-visibility"></i><b>Preview</b><small>View file contents and details</small></span><span><i class="dashicons dashicons-edit"></i><b>Edit</b><small>Open supported files in Code Editor</small></span><span><i class="dashicons dashicons-shield-alt"></i><b>Safe &amp; Secure</b><small>Protected by Safe Mode</small></span></div></div>';
  }

  function previewUrl(path) {
    const params = new URLSearchParams({
      action: 'nobash_files_preview',
      nobash_files_preview_nonce: NobashFiles.previewNonce,
      path
    });
    return `${NobashFiles.downloadUrl}?${params.toString()}`;
  }

  async function buildPreview(info) {
    const preview = info.preview || {previewable:false, kind:'none'};
    if (!preview.previewable) return `<div class="nobash-files-preview-empty"><span class="dashicons ${iconFor(info)}"></span><p>No secure inline preview for this file type.</p></div>`;
    const url = previewUrl(info.path);
    if (preview.kind === 'image') return `<div class="nobash-files-preview-frame image"><img src="${esc(url)}" alt="${esc(info.name)}"></div>`;
    if (preview.kind === 'pdf') return `<div class="nobash-files-preview-frame pdf"><iframe src="${esc(url)}#toolbar=0" title="${esc(info.name)} preview"></iframe></div>`;
    if (preview.kind === 'audio') return `<div class="nobash-files-preview-frame media"><span class="dashicons dashicons-format-audio"></span><audio controls preload="metadata" src="${esc(url)}"></audio></div>`;
    if (preview.kind === 'video') return `<div class="nobash-files-preview-frame video"><video controls preload="metadata" src="${esc(url)}"></video></div>`;
    if (preview.kind === 'text' && info.editable) {
      try {
        const data = await api('file?path=' + encodeURIComponent(info.path));
        const snippet = data.content.length > 5000 ? data.content.slice(0, 5000) + '\n… preview truncated …' : data.content;
        return `<div class="nobash-files-preview-code"><pre>${esc(snippet)}</pre></div>`;
      } catch (e) {
        return `<div class="nobash-files-preview-empty"><span class="dashicons dashicons-editor-code"></span><p>${esc(e.message)}</p></div>`;
      }
    }
    return `<div class="nobash-files-preview-empty"><span class="dashicons ${iconFor(info)}"></span><p>Preview unavailable.</p></div>`;
  }

  async function selectFile(path, addRecent = true) {
    try {
      const info = normalizeItem(await api('info?path=' + encodeURIComponent(path)));
      const details = qs('#nobash-files-details');
      if (!details) return;
      if (addRecent) addRecentPath(info.path);
      const previewHtml = await buildPreview(info);
      details.innerHTML = `<div class="nobash-files-panel-head"><strong>File Details</strong><div class="nobash-files-detail-head-actions"><button class="nobash-files-icon-btn nobash-files-favorite-item ${favoritePaths.has(info.path) ? 'is-favorite' : ''}" data-favorite-path="${esc(info.path)}" type="button" title="Favorite"><span class="dashicons ${favoritePaths.has(info.path) ? 'dashicons-star-filled' : 'dashicons-star-empty'}"></span></button>${info.protected ? protectedMarker : '<span class="nobash-files-badge ready">Ready</span>'}</div></div>
        ${previewHtml}
        <div class="nobash-files-file-hero"><span class="dashicons ${iconFor(info)}"></span><div><strong>${esc(info.name)}</strong><p>${esc(info.path)}</p></div></div>
        <dl class="nobash-files-dl"><dt>Size</dt><dd>${fmtSize(info.size)}</dd><dt>Modified</dt><dd>${fmtDate(info.modified)}</dd><dt>Permissions</dt><dd>${esc(info.permissions)}</dd><dt>Writable</dt><dd>${info.writable ? 'Yes' : 'No'}</dd>${info.preview?.mime ? `<dt>Type</dt><dd>${esc(info.preview.mime)}</dd>` : ''}</dl>
        <div class="nobash-files-safe-actions">
          ${info.editable && hasPerm('edit') ? `<a class="button button-primary" href="${location.pathname}?page=nobash-files-editor&file=${encodeURIComponent(info.path)}">Edit File</a>` : ''}
          ${hasPerm('download') ? `<button class="button" data-detail-action="download" data-path="${esc(info.path)}">Download</button>` : ''}
          ${hasPerm('archive') ? `<button class="button" data-detail-action="archive" data-path="${esc(info.path)}">Create Archive</button>` : ''}
          ${isArchivePath(info.path) && hasPerm('extract') ? `<button class="button" data-detail-action="extract" data-path="${esc(info.path)}">Inspect & Extract</button>` : ''}
          ${hasPerm('rename') ? `<button class="button" data-detail-action="rename" data-path="${esc(info.path)}">Rename</button>` : ''}
          ${hasPerm('copy') ? `<button class="button" data-detail-action="duplicate" data-path="${esc(info.path)}">Duplicate</button>` : ''}
          ${hasPerm('delete') ? `<button class="button nobash-files-danger" data-detail-action="trash" data-path="${esc(info.path)}">Move to Trash</button>` : ''}
        </div>`;
      showDetailsPanel(details);
      qsa('[data-detail-action]', details).forEach(btn => btn.addEventListener('click', () => {
        selected.clear(); selected.add(btn.dataset.path); updateSelectionUI(); bulkAction(btn.dataset.detailAction);
      }));
      qs('[data-favorite-path]', details)?.addEventListener('click', () => toggleFavorite(info.path, true));
    } catch (e) { notice(e.message, 'error'); }
  }

  function showDetailsPanel(details) {
    if (view !== 'manager') return;
    details.hidden = false;
    root.classList.add('has-details');
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'nobash-files-icon-btn nobash-files-close-details';
    close.setAttribute('aria-label', 'Close details');
    close.title = 'Close details';
    close.innerHTML = '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>';
    close.addEventListener('click', () => {
      details.hidden = true;
      root.classList.remove('has-details');
    });
    details.querySelector('.nobash-files-detail-head-actions')?.appendChild(close);
  }

  function renderFolderDetails(item) {
    const details = qs('#nobash-files-details');
    if (!details || !item) return;
    details.innerHTML = `<div class="nobash-files-panel-head"><strong>Folder Details</strong><div class="nobash-files-detail-head-actions"><button class="nobash-files-icon-btn nobash-files-favorite-item ${favoritePaths.has(item.path) ? 'is-favorite' : ''}" data-favorite-path="${esc(item.path)}" type="button" title="Favorite"><span class="dashicons ${favoritePaths.has(item.path) ? 'dashicons-star-filled' : 'dashicons-star-empty'}"></span></button>${item.protected ? protectedMarker : '<span class="nobash-files-badge ready">Ready</span>'}</div></div>
      <div class="nobash-files-folder-preview"><span class="dashicons dashicons-category"></span><strong>${esc(item.name)}</strong><p>${esc(item.path)}</p></div>
      <dl class="nobash-files-dl"><dt>Type</dt><dd>Folder</dd><dt>Modified</dt><dd>${fmtDate(item.modified)}</dd><dt>Writable</dt><dd>${item.writable ? 'Yes' : 'No'}</dd></dl>
      <div class="nobash-files-safe-actions"><button class="button button-primary" data-detail-action="open">Open Folder</button>${hasPerm('download') ? '<button class="button" data-detail-action="download">Download</button>' : ''}${hasPerm('archive') ? '<button class="button" data-detail-action="archive">Create Archive</button>' : ''}${hasPerm('rename') ? '<button class="button" data-detail-action="rename">Rename</button>' : ''}${hasPerm('copy') ? '<button class="button" data-detail-action="duplicate">Duplicate</button>' : ''}${hasPerm('delete') ? '<button class="button nobash-files-danger" data-detail-action="trash">Move to Trash</button>' : ''}</div>`;
    showDetailsPanel(details);
    qsa('[data-detail-action]', details).forEach(btn => btn.addEventListener('click', () => {
      if (btn.dataset.detailAction === 'open') return loadDir(item.path).catch(() => {});
      selected.clear(); selected.add(item.path); updateSelectionUI(); bulkAction(btn.dataset.detailAction);
    }));
    qs('[data-favorite-path]', details)?.addEventListener('click', () => toggleFavorite(item.path, true));
  }

  async function loadPreferences() {
    try {
      preferences = await api('preferences');
      preferences.favorites = (preferences.favorites || []).map(normalizeItem);
      preferences.recent = (preferences.recent || []).map(normalizeItem);
      favoritePaths = new Set(preferences.favorite_paths || preferences.favorites.map(i => i.path));
      if (view === 'manager') {
        renderFiles();
        updateFolderFavoriteButton();
      }
    } catch (e) {
      preferences = {favorites:[], recent:[], favorite_paths:[]};
      favoritePaths = new Set();
    }
  }

  async function toggleFavorite(path, refreshDetails = false) {
    try {
      preferences = await api('preferences', {method:'POST', body:JSON.stringify({action:'toggle_favorite', path})});
      preferences.favorites = (preferences.favorites || []).map(normalizeItem);
      preferences.recent = (preferences.recent || []).map(normalizeItem);
      favoritePaths = new Set(preferences.favorite_paths || []);
      renderFiles();
      updateFolderFavoriteButton();
      if (refreshDetails) {
        const item = currentItems.find(i => i.path === path);
        if (item?.type === 'directory') renderFolderDetails(item);
        else if (path) selectFile(path, false);
      }
      notice(favoritePaths.has(path) ? 'Added to Favorites.' : 'Removed from Favorites.');
    } catch (e) { notice(e.message, 'error'); }
  }

  async function addRecentPath(path) {
    try {
      preferences = await api('preferences', {method:'POST', body:JSON.stringify({action:'add_recent', path})});
      preferences.favorites = (preferences.favorites || []).map(normalizeItem);
      preferences.recent = (preferences.recent || []).map(normalizeItem);
      favoritePaths = new Set(preferences.favorite_paths || []);
    } catch (_) {}
  }

  function updateFolderFavoriteButton() {
    const btn = qs('#nobash-files-favorite-folder');
    if (!btn) return;
    const isFav = favoritePaths.has(currentPath);
    btn.classList.toggle('is-favorite', isFav);
    const icon = qs('.dashicons', btn);
    if (icon) icon.className = `dashicons ${isFav ? 'dashicons-star-filled' : 'dashicons-star-empty'}`;
    const text = qs('span:last-child', btn);
    if (text) text.textContent = isFav ? 'Favorited' : 'Favorite';
  }

  function updateMutationAvailability() {
    const upload = qs('#nobash-files-upload');
    const folder = qs('#nobash-files-new-folder');
    const disabled = Boolean(smartView);
    if (upload && ['manager','editor'].includes(view)) upload.disabled = disabled || !hasPerm('upload');
    if (folder && ['manager','editor'].includes(view)) folder.disabled = disabled || !hasPerm('create');
    if (upload) upload.title = !hasPerm('upload') ? 'Upload is not allowed by your access profile.' : (disabled ? 'Open a real folder before uploading.' : '');
    if (folder) folder.title = !hasPerm('create') ? 'Creating folders is not allowed by your access profile.' : (disabled ? 'Open a real folder before creating a folder.' : '');
  }

  async function showSmart(kind) {
    await loadPreferences();
    smartView = kind;
    currentPath = '';
    updateMutationAvailability();
    const items = kind === 'favorites' ? preferences.favorites : preferences.recent;
    currentItems = items.map(normalizeItem);
    selected.clear();
    breadcrumbs('', kind === 'favorites' ? 'Favorites' : 'Recent');
    renderFiles();
    renderEmptyDetails();
    updateSelectionUI();
    const state = qs('#nobash-files-folder-state');
    if (state) state.textContent = kind === 'favorites' ? 'Saved shortcuts · original files stay in place' : 'Recently opened files';
    if (kind === 'recent' && state) {
      const clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'nobash-files-link-button';
      clear.textContent = 'Clear recent';
      clear.addEventListener('click', clearRecent);
      state.append(' · ', clear);
    }
  }

  async function clearRecent() {
    try {
      preferences = await api('preferences', {method:'POST', body:JSON.stringify({action:'clear_recent'})});
      currentItems = [];
      renderFiles();
      notice('Recent files cleared.');
    } catch (e) { notice(e.message, 'error'); }
  }

  function openContextMenu(path, x, y) {
    const menu = qs('#nobash-files-context-menu');
    if (!menu) return;
    const item = currentItems.find(i => i.path === path) || preferences.favorites.find(i => i.path === path) || preferences.recent.find(i => i.path === path);
    if (!item) return;
    const openLabel = item.type === 'directory' ? 'Open folder' : 'Preview';
    menu.innerHTML = `
      ${hasPerm('read') ? `<button data-context="open"><span class="dashicons ${item.type === 'directory' ? 'dashicons-open-folder' : 'dashicons-visibility'}"></span>${openLabel}</button>` : ''}
      ${item.type === 'file' && item.editable && hasPerm('edit') ? '<button data-context="edit"><span class="dashicons dashicons-editor-code"></span>Edit file</button>' : ''}
      ${hasPerm('read') ? `<button data-context="favorite"><span class="dashicons ${favoritePaths.has(path) ? 'dashicons-star-filled' : 'dashicons-star-empty'}"></span>${favoritePaths.has(path) ? 'Remove favorite' : 'Add favorite'}</button>` : ''}
      <div class="nobash-files-context-separator"></div>
      ${hasPerm('download') ? '<button data-context="download"><span class="dashicons dashicons-download"></span>Download</button>' : ''}
      ${hasPerm('archive') ? '<button data-context="archive"><span class="dashicons dashicons-media-archive"></span>Create archive</button>' : ''}
      ${item.type === 'file' && isArchivePath(path) && hasPerm('extract') ? '<button data-context="extract"><span class="dashicons dashicons-open-folder"></span>Inspect & extract</button>' : ''}
      ${hasPerm('copy') ? '<button data-context="copy"><span class="dashicons dashicons-admin-page"></span>Copy</button>' : ''}
      ${hasPerm('move') ? '<button data-context="move"><span class="dashicons dashicons-move"></span>Move</button>' : ''}
      ${hasPerm('copy') ? '<button data-context="duplicate"><span class="dashicons dashicons-images-alt2"></span>Duplicate</button>' : ''}
      ${hasPerm('rename') ? '<button data-context="rename"><span class="dashicons dashicons-edit"></span>Rename</button>' : ''}
      ${hasPerm('delete') ? '<div class="nobash-files-context-separator"></div><button data-context="trash" class="danger"><span class="dashicons dashicons-trash"></span>Move to Trash</button>' : ''}`;
    menu.hidden = false;
    const left = Math.min(Math.max(8, x), window.innerWidth - 230);
    const top = Math.min(Math.max(8, y), window.innerHeight - 390);
    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
    qsa('[data-context]', menu).forEach(btn => btn.addEventListener('click', () => {
      menu.hidden = true;
      const action = btn.dataset.context;
      if (action === 'open') return item.type === 'directory' ? loadDir(path).catch(() => {}) : selectFile(path);
      if (action === 'edit') return location.href = `${location.pathname}?page=nobash-files-editor&file=${encodeURIComponent(path)}`;
      if (action === 'favorite') return toggleFavorite(path, true);
      selected.clear(); selected.add(path); updateSelectionUI(); bulkAction(action);
    }));
  }

  function closeContextMenu() {
    const menu = qs('#nobash-files-context-menu');
    if (menu) menu.hidden = true;
  }

  function openSearch() {
    if (!hasPerm('search')) return notice('Search is not allowed by your access profile.', 'error');
    const panel = qs('#nobash-files-search-panel');
    const input = qs('#nobash-files-search-input');
    if (!panel || !input) return;
    panel.hidden = false;
    requestAnimationFrame(() => { input.focus(); input.select(); });
  }

  function closeSearch() {
    const panel = qs('#nobash-files-search-panel');
    if (panel) panel.hidden = true;
    if (searchAbort) searchAbort.abort();
  }

  async function runSearch() {
    const input = qs('#nobash-files-search-input');
    const results = qs('#nobash-files-search-results');
    const meta = qs('#nobash-files-search-meta');
    if (!input || !results || !meta) return;
    const q = input.value.trim();
    if (q.length < 2) {
      results.innerHTML = '';
      meta.textContent = 'Type at least 2 characters';
      return;
    }
    if (searchAbort) searchAbort.abort();
    searchAbort = new AbortController();
    meta.textContent = 'Searching safely…';
    results.innerHTML = '<div class="nobash-files-search-loading"><span class="spinner is-active"></span> Searching…</div>';
    const scope = qs('#nobash-files-search-scope')?.value === 'root' ? '' : currentPath;
    const content = qs('#nobash-files-search-content')?.checked ? '1' : '0';
    try {
      const query = `search?base=${encodeURIComponent(scope)}&q=${encodeURIComponent(q)}&content=${content}`;
      const data = await api(query, {signal:searchAbort.signal});
      if (!Array.isArray(data.results)) throw new Error('Search returned incomplete data.');
      meta.textContent = `${data.results.length} result${data.results.length === 1 ? '' : 's'} · scanned ${data.visited} items${data.truncated ? ' · limited for safety' : ''}`;
      renderSearchResults(data.results || []);
    } catch (e) {
      if (e.name === 'AbortError') return;
      results.innerHTML = `<div class="nobash-files-search-empty">${esc(e.message)}</div>`;
      meta.textContent = 'Search stopped';
    }
  }

  function renderSearchResults(items) {
    const host = qs('#nobash-files-search-results');
    if (!host) return;
    host.innerHTML = items.length ? items.map(i => `<button class="nobash-files-search-result" data-search-path="${esc(i.path)}" data-search-type="${i.type}">
      <span class="nobash-files-search-result-icon dashicons ${iconFor(i)}"></span>
      <span class="nobash-files-search-result-main"><strong>${esc(i.name)}</strong><small>${esc(i.path)}</small>${i.snippet ? `<em>${esc(i.snippet)}</em>` : ''}</span>
      <span class="nobash-files-search-match">${i.match === 'content' ? 'Content' : 'Name'}</span>
      <span class="dashicons dashicons-arrow-right-alt2"></span>
    </button>`).join('') : '<div class="nobash-files-search-empty"><span class="dashicons dashicons-search"></span><strong>No matches found</strong><p>Try a broader name or search the entire WordPress site.</p></div>';
    qsa('[data-search-path]', host).forEach(btn => btn.addEventListener('click', async () => {
      const path = btn.dataset.searchPath;
      closeSearch();
      if (btn.dataset.searchType === 'directory') return loadDir(path).catch(() => {});
      const parent = parentPath(path);
      await loadDir(parent).catch(() => {});
      selectFile(path);
    }));
  }

  async function performOperation(payload, retryProtected = true) {
    try {
      return await api('operation', {method:'POST', body:JSON.stringify(payload)});
    } catch (e) {
      if (retryProtected && e.code === 'protected_confirm_required') {
        if (await confirmProtected()) return performOperation({...payload, confirm_protected:true}, false);
        throw new Error('Protected change cancelled.');
      }
      throw e;
    }
  }

  async function createFolder() {
    if (!['manager','editor'].includes(view) || !hasPerm('create')) return notice('Creating folders is not allowed by your access profile.', 'error');
    const result = await showModal({title:'New folder', message:`Create a folder inside /${currentPath || ''}`, label:'Folder name', value:'', confirmText:'Create folder'});
    if (!result.confirmed) return;
    const name = result.value.trim();
    if (!name) return notice('Enter a folder name.', 'error');
    try {
      await performOperation({action:'create_folder', path:currentPath, name});
      notice(`Folder “${name}” created.`);
      await loadDir(currentPath);
      await refreshUndoState();
    } catch (e) { if (e.message !== 'Protected change cancelled.') notice(e.message, 'error'); }
  }

  async function bulkAction(action) {
    const paths = [...selected];
    if (!paths.length) return notice('Select at least one item.', 'error');
    const permissionMap = {download:'download', archive:'archive', extract:'extract', rename:'rename', duplicate:'copy', copy:'copy', move:'move', trash:'delete'};
    if (permissionMap[action] && !hasPerm(permissionMap[action])) return notice('This action is not allowed by your access profile.', 'error');

    if (action === 'download') return submitDownload(paths);
    if (action === 'archive') return openArchiveCreate(paths);
    if (action === 'extract') {
      if (paths.length !== 1 || !isArchivePath(paths[0])) return notice('Select one ZIP, TAR, TAR.GZ or TGZ archive to extract.', 'error');
      return openArchiveExtract(paths[0]);
    }

    if (action === 'rename') {
      if (paths.length !== 1) return notice('Select one item to rename.', 'error');
      const current = basename(paths[0]);
      const answer = await showModal({title:'Rename item', message:'Enter a new name. The item stays in the same folder.', label:'New name', value:current, confirmText:'Rename'});
      if (!answer.confirmed || !answer.value.trim() || answer.value.trim() === current) return;
      try {
        await performOperation({action:'rename', path:paths[0], name:answer.value.trim()});
        notice(`Renamed to “${answer.value.trim()}”.`);
        await loadDir(currentPath); await refreshUndoState(); await loadPreferences();
      } catch (e) { if (e.message !== 'Protected change cancelled.') notice(e.message, 'error'); }
      return;
    }

    if (action === 'duplicate') {
      if (paths.length !== 1) return notice('Select one item to duplicate.', 'error');
      try {
        const result = await performOperation({action:'duplicate', path:paths[0]});
        notice(`Created duplicate “${result.name}”.`);
        await loadDir(currentPath); await refreshUndoState();
      } catch (e) { if (e.message !== 'Protected change cancelled.') notice(e.message, 'error'); }
      return;
    }

    if (action === 'copy' || action === 'move') {
      const title = action === 'copy' ? 'Copy selected items' : 'Move selected items';
      const answer = await showModal({
        title,
        message:'Enter the destination folder path relative to the WordPress root. Example: wp-content/uploads',
        label:'Destination folder',
        value:currentPath,
        confirmText:action === 'copy' ? 'Copy here' : 'Move here'
      });
      if (!answer.confirmed) return;
      try {
        await performOperation({action, paths, target:answer.value.trim().replace(/^\/+/, '')});
        notice(`${paths.length} item${paths.length === 1 ? '' : 's'} ${action === 'copy' ? 'copied' : 'moved'} safely.`);
        await loadDir(currentPath); await refreshUndoState(); await loadPreferences();
      } catch (e) { if (e.message !== 'Protected change cancelled.') notice(e.message, 'error'); }
      return;
    }

    if (action === 'trash') {
      const answer = await showModal({
        title:'Move to Trash?',
        message:`${paths.length} selected item${paths.length === 1 ? '' : 's'} will be removed from the live site and stored in a recoverable Nobash snapshot for Undo.`,
        confirmText:'Move to Trash', danger:true, input:false
      });
      if (!answer.confirmed) return;
      try {
        await performOperation({action:'trash', paths});
        notice(`${paths.length} item${paths.length === 1 ? '' : 's'} moved to recoverable Trash.`);
        await loadDir(currentPath); await refreshUndoState(); await loadPreferences();
      } catch (e) { if (e.message !== 'Protected change cancelled.') notice(e.message, 'error'); }
    }
  }


  let archiveModalResolve = null;
  let activeArchiveJob = '';
  let archivePollTimer = null;

  function archiveJobId() {
    return (`nobash-files-${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '_');
  }

  function closeArchiveModal(result = null) {
    const modal = qs('#nobash-files-archive-modal');
    if (modal) modal.hidden = true;
    setModalOpen(false);
    if (archiveModalResolve) { const resolve = archiveModalResolve; archiveModalResolve = null; resolve(result); }
  }

  function showArchiveModal(mode, data = {}) {
    const modal = qs('#nobash-files-archive-modal');
    if (!modal) return Promise.resolve(null);
    qs('#nobash-files-archive-create-fields').hidden = mode !== 'create';
    qs('#nobash-files-archive-extract-fields').hidden = mode !== 'extract';
    qs('#nobash-files-archive-modal-title').textContent = mode === 'create' ? 'Create archive' : 'Inspect & extract archive';
    qs('#nobash-files-archive-modal-message').textContent = mode === 'create' ? 'Package selected items without changing the originals.' : 'Review the archive before extraction. Unsafe paths and symbolic links are blocked automatically.';
    qs('#nobash-files-archive-modal-confirm-label').textContent = mode === 'create' ? 'Create archive' : 'Extract safely';
    qs('#nobash-files-archive-modal-confirm-icon').className = `dashicons ${mode === 'create' ? 'dashicons-media-archive' : 'dashicons-download'}`;
    const summary = qs('#nobash-files-archive-selection');
    if (summary) {
      const count = mode === 'create' ? Number(data.count || 1) : Number(data.meta?.entry_count || data.meta?.entries?.length || 0);
      summary.querySelector('strong').textContent = mode === 'create' ? `${count} item${count === 1 ? '' : 's'} selected` : `${count} entr${count === 1 ? 'y' : 'ies'} safety checked`;
    }
    if (mode === 'create') {
      qs('#nobash-files-archive-name').value = data.name || `archive-${new Date().toISOString().slice(0,10)}`;
      qs('#nobash-files-archive-destination').value = data.destination || currentPath;
      const formatSelect = qs('#nobash-files-archive-format');
      if (formatSelect) {
        qsa('option', formatSelect).forEach(opt => {
          const unavailable = opt.value === 'zip' ? NobashFiles.zipAvailable === false : NobashFiles.tarAvailable === false;
          opt.disabled = unavailable;
        });
        const first = qsa('option', formatSelect).find(opt => !opt.disabled);
        if (!first) { closeArchiveModal(null); notice('This server needs ZipArchive or PharData before archives can be created.', 'error'); return Promise.resolve(null); }
        formatSelect.value = first.value;
      }
    } else {
      qs('#nobash-files-extract-destination').value = data.destination || currentPath;
      qs('#nobash-files-extract-conflict').value = 'rename';
      const helper = qs('#nobash-files-extract-helper');
      if (helper) { helper.textContent = 'Existing files will be preserved.'; helper.classList.remove('is-warning'); }
      const preview = qs('#nobash-files-archive-preview');
      const meta = data.meta || {};
      const entries = meta.entries || [];
      preview.innerHTML = `<div class="nobash-files-archive-summary"><div><strong>${esc(meta.name || basename(data.path || 'archive'))}</strong><p>${meta.entry_count || entries.length} entries · ${fmtSize(meta.total_uncompressed || 0)} expanded</p></div><span class="nobash-files-badge ready">Safety checked</span></div><div class="nobash-files-archive-entry-list">${entries.slice(0,8).map(e => `<div><span class="dashicons ${e.directory ? 'dashicons-category' : 'dashicons-media-default'}"></span><span>${esc(e.name)}</span><small>${e.directory ? 'Folder' : fmtSize(e.size)}</small></div>`).join('')}${entries.length > 8 ? `<p class="nobash-files-muted">+ ${entries.length - 8} more entries</p>` : ''}</div>`;
    }
    modal.hidden = false;
    setModalOpen(true);
    requestAnimationFrame(() => (mode === 'create' ? qs('#nobash-files-archive-name') : qs('#nobash-files-extract-destination'))?.focus());
    return new Promise(resolve => { archiveModalResolve = resolve; });
  }

  async function beginArchiveJob(title, jobId, requestPromise) {
    activeArchiveJob = jobId;
    const panel = qs('#nobash-files-archive-job-panel');
    if (panel) panel.hidden = false;
    qs('#nobash-files-archive-job-title').textContent = title;
    qs('#nobash-files-archive-job-status').textContent = 'Preparing…';
    qs('#nobash-files-archive-job-percent').textContent = '0%';
    qs('#nobash-files-archive-job-progress').style.width = '0%';
    const poll = async () => {
      if (!activeArchiveJob || activeArchiveJob !== jobId) return;
      try {
        const state = await api('archive/job?job_id=' + encodeURIComponent(jobId));
        const progress = Number(state.progress || 0);
        qs('#nobash-files-archive-job-status').textContent = state.label || state.status || 'Working…';
        qs('#nobash-files-archive-job-percent').textContent = `${progress}%`;
        qs('#nobash-files-archive-job-progress').style.width = `${progress}%`;
        if (!['complete','failed','cancelled'].includes(state.status)) archivePollTimer = setTimeout(poll, 350);
      } catch (_) { archivePollTimer = setTimeout(poll, 700); }
    };
    poll();
    try {
      const result = await requestPromise;
      if (archivePollTimer) clearTimeout(archivePollTimer);
      qs('#nobash-files-archive-job-status').textContent = 'Complete';
      qs('#nobash-files-archive-job-percent').textContent = '100%';
      qs('#nobash-files-archive-job-progress').style.width = '100%';
      setTimeout(() => { if (panel) panel.hidden = true; }, 1100);
      activeArchiveJob = '';
      return result;
    } catch (e) {
      if (archivePollTimer) clearTimeout(archivePollTimer);
      if (panel) panel.hidden = true;
      activeArchiveJob = '';
      throw e;
    }
  }

  async function openArchiveCreate(paths, protectedConfirmed = false) {
    const result = await showArchiveModal('create', {count:paths.length, destination:currentPath});
    if (!result) return;
    const name = qs('#nobash-files-archive-name').value.trim();
    const format = qs('#nobash-files-archive-format').value;
    const destination = qs('#nobash-files-archive-destination').value.trim().replace(/^\/+/, '');
    if (!name) return notice('Enter an archive name.', 'error');
    const jobId = archiveJobId();
    try {
      await beginArchiveJob('Creating archive', jobId, api('archive/create', {method:'POST', body:JSON.stringify({paths, destination, name, format, job_id:jobId, confirm_protected:protectedConfirmed})}));
      notice('Archive created successfully.');
      if (!smartView) await loadDir(currentPath);
      await refreshUndoState();
    } catch (e) {
      if (!protectedConfirmed && e.code === 'protected_confirm_required') {
        if (await confirmProtected()) return openArchiveCreate(paths, true);
        return;
      }
      notice(e.message, 'error');
    }
  }

  async function openArchiveExtract(path, protectedConfirmed = false) {
    try {
      const meta = await api('archive/inspect?path=' + encodeURIComponent(path));
      const answer = await showArchiveModal('extract', {path, meta, destination:parentPath(path)});
      if (!answer) return;
      const destination = qs('#nobash-files-extract-destination').value.trim().replace(/^\/+/, '');
      const conflict = qs('#nobash-files-extract-conflict').value;
      if (conflict === 'overwrite') {
        const confirm = await showModal({title:'Replace existing files?', message:'Overwrite can permanently replace existing files that have the same extracted path. Use “Keep both” when unsure.', confirmText:'Use Overwrite', danger:true, input:false});
        if (!confirm.confirmed) return;
      }
      const jobId = archiveJobId();
      await beginArchiveJob('Extracting archive', jobId, api('archive/extract', {method:'POST', body:JSON.stringify({path, destination, conflict, job_id:jobId, confirm_protected:protectedConfirmed})}));
      notice('Archive extracted safely.');
      if (!smartView) await loadDir(currentPath);
      await refreshUndoState();
    } catch (e) {
      if (!protectedConfirmed && e.code === 'protected_confirm_required') {
        if (await confirmProtected()) return openArchiveExtract(path, true);
        return;
      }
      notice(e.message, 'error');
    }
  }

  function initArchiveModal() {
    qs('#nobash-files-archive-modal-cancel')?.addEventListener('click', () => closeArchiveModal(null));
    qs('#nobash-files-archive-modal-close')?.addEventListener('click', () => closeArchiveModal(null));
    qs('#nobash-files-archive-modal-confirm')?.addEventListener('click', () => closeArchiveModal({confirmed:true}));
    qs('#nobash-files-archive-modal')?.addEventListener('click', e => { if (e.target.id === 'nobash-files-archive-modal') closeArchiveModal(null); });
    qs('#nobash-files-archive-modal')?.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.preventDefault(); closeArchiveModal(null); return; }
      trapDialogTab(qs('#nobash-files-archive-modal'), e);
    });
    qs('#nobash-files-extract-conflict')?.addEventListener('change', e => {
      const helper = qs('#nobash-files-extract-helper');
      if (!helper) return;
      helper.textContent = e.target.value === 'rename' ? 'Existing files will be preserved.' : (e.target.value === 'skip' ? 'Existing files will be left unchanged.' : 'Matching files will be replaced after confirmation.');
      helper.classList.toggle('is-warning', e.target.value === 'overwrite');
    });
    qs('#nobash-files-archive-cancel')?.addEventListener('click', async () => {
      if (!activeArchiveJob) return;
      try {
        await api('archive/job', {method:'POST', body:JSON.stringify({action:'cancel', job_id:activeArchiveJob})});
        qs('#nobash-files-archive-job-status').textContent = 'Cancellation requested…';
      } catch (e) { notice(e.message, 'error'); }
    });
  }

  function submitDownload(paths) {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = NobashFiles.downloadUrl; form.style.display = 'none';
    const fields = {action:'nobash_files_download', nobash_files_download_nonce:NobashFiles.downloadNonce, paths:JSON.stringify(paths)};
    Object.entries(fields).forEach(([name, value]) => {
      const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
    });
    document.body.appendChild(form); form.submit(); setTimeout(() => form.remove(), 1000);
  }

  function normalizeUploadTasks(items, mode = 'multiple') {
    const raw = [...(items || [])];
    return raw.map(item => {
      if (item && item.file instanceof File) return item;
      const file = item;
      const browserPath = mode === 'folder' ? String(file.webkitRelativePath || file._nfmRelativePath || file.name || '') : '';
      return {file, relativePath: browserPath.replace(/^\/+/, '').replace(/\\/g, '/')};
    }).filter(task => task.file instanceof File && task.file.name);
  }

  async function uploadFiles(items, protectedConfirmed = false, mode = 'multiple') {
    if (!hasPerm('upload')) return notice('Upload is not allowed by your access profile.', 'error');
    if (mode === 'folder' && !hasPerm('create')) return notice('Folder upload requires permission to create folders.', 'error');
    const tasks = normalizeUploadTasks(items, mode);
    if (!tasks.length) return;

    const panel = qs('#nobash-files-upload-panel');
    const title = qs('#nobash-files-upload-title');
    const status = qs('#nobash-files-upload-status');
    const percent = qs('#nobash-files-upload-percent');
    const bar = qs('#nobash-files-upload-progress');
    const controller = new AbortController();
    activeUpload = {cancelled:false, controller, uploadIds:new Set()};
    if (panel) panel.hidden = false;
    if (title) title.textContent = mode === 'folder' ? 'Uploading folder' : (tasks.length > 1 ? 'Uploading files' : 'Uploading file');
    if (bar) bar.style.width = '0%';
    if (percent) percent.textContent = '0%';

    const totalBytes = tasks.reduce((sum, task) => sum + Number(task.file.size || 0), 0) || 1;
    let completedBytes = 0;

    const progress = (task, bytesForFile, label, index) => {
      const done = completedBytes + bytesForFile;
      const p = Math.min(100, Math.round(done / totalBytes * 100));
      const displayPath = task.relativePath || task.file.name;
      if (status) status.textContent = `${label || 'Uploading'} ${index + 1} of ${tasks.length} · ${displayPath}`;
      if (percent) percent.textContent = `${p}%`;
      if (bar) bar.style.width = `${p}%`;
    };

    try {
      for (let i = 0; i < tasks.length; i++) {
        if (activeUpload?.cancelled) throw new DOMException('Upload cancelled.', 'AbortError');
        const task = tasks[i];
        const file = task.file;
        progress(task, 0, 'Uploading', i);
        try {
          if (file.size <= Number(NobashFiles.chunkBytes || 2097152)) {
            const form = new FormData();
            form.append('path', currentPath);
            form.append('file', file, file.name);
            if (task.relativePath && mode === 'folder') form.append('relative_path', task.relativePath);
            if (protectedConfirmed) form.append('confirm_protected', '1');
            await api('upload', {method:'POST', body:form, signal:controller.signal});
            progress(task, file.size, 'Uploaded', i);
          } else {
            await uploadFileChunked(task, protectedConfirmed, sent => progress(task, sent, 'Uploading', i), controller.signal, mode);
          }
          completedBytes += file.size;
        } catch (e) {
          if (!protectedConfirmed && e.code === 'protected_confirm_required') {
            if (await confirmProtected()) {
              const remaining = tasks.slice(i);
              if (panel) panel.hidden = true;
              activeUpload = null;
              return uploadFiles(remaining, true, mode);
            }
            throw new Error('Protected upload cancelled.');
          }
          throw e;
        }
      }
      if (percent) percent.textContent = '100%';
      if (bar) bar.style.width = '100%';
      const noun = mode === 'folder' ? 'Folder' : `${tasks.length} file${tasks.length === 1 ? '' : 's'}`;
      if (status) status.textContent = `${noun} upload completed successfully.`;
      notice(mode === 'folder' ? 'Folder uploaded successfully.' : `${noun} uploaded successfully.`);
      await loadDir(currentPath); await refreshUndoState();
      setTimeout(() => { if (panel) panel.hidden = true; }, 1200);
    } catch (e) {
      if (panel) panel.hidden = true;
      if (e?.name === 'AbortError' || activeUpload?.cancelled) notice('Upload cancelled.', 'info');
      else if (e.message !== 'Protected upload cancelled.') notice(e.message, 'error');
    } finally {
      activeUpload = null;
    }
  }

  async function uploadFileChunked(task, protectedConfirmed, onProgress, signal, mode = 'multiple') {
    const file = task.file;
    const chunkSize = Math.max(262144, Number(NobashFiles.chunkBytes || 2097152));
    const total = Math.max(1, Math.ceil(file.size / chunkSize));
    const uploadId = (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '_');
    activeUpload?.uploadIds.add(uploadId);

    for (let index = 0; index < total; index++) {
      if (activeUpload?.cancelled) throw new DOMException('Upload cancelled.', 'AbortError');
      const start = index * chunkSize;
      const blob = file.slice(start, Math.min(file.size, start + chunkSize));
      let attempt = 0;
      while (true) {
        attempt++;
        const form = new FormData();
        form.append('path', currentPath); form.append('filename', file.name); form.append('upload_id', uploadId);
        form.append('chunk_index', String(index)); form.append('total_chunks', String(total)); form.append('chunk', blob, `${file.name}.part`);
        if (task.relativePath && mode === 'folder') form.append('relative_path', task.relativePath);
        if (protectedConfirmed) form.append('confirm_protected', '1');
        try {
          await api('upload-chunk', {method:'POST', body:form, signal});
          break;
        } catch (e) {
          if (e?.name === 'AbortError' || e.code === 'protected_confirm_required') throw e;
          if (attempt >= 3) throw e;
          await new Promise(resolve => setTimeout(resolve, 400 * attempt));
        }
      }
      onProgress(Math.min(file.size, (index + 1) * chunkSize));
    }
    activeUpload?.uploadIds.delete(uploadId);
  }

  async function cancelActiveUpload() {
    if (!activeUpload) return;
    const state = activeUpload;
    state.cancelled = true;
    try { state.controller?.abort(); } catch (_) {}
    const ids = [...state.uploadIds];
    if (ids.length) {
      try { await api('upload-cancel', {method:'POST', body:JSON.stringify({upload_ids:ids})}); } catch (_) {}
    }
    const panel = qs('#nobash-files-upload-panel');
    if (panel) panel.hidden = true;
  }

  function fileFromEntry(entry, relativePath) {
    return new Promise((resolve, reject) => entry.file(file => resolve({file, relativePath}), reject));
  }

  async function readDirectoryEntries(reader) {
    const all = [];
    while (true) {
      const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
      if (!batch.length) break;
      all.push(...batch);
    }
    return all;
  }

  async function walkDroppedEntry(entry, prefix = '') {
    if (!entry) return [];
    if (entry.isFile) return [await fileFromEntry(entry, `${prefix}${entry.name}`)];
    if (!entry.isDirectory) return [];
    const nextPrefix = `${prefix}${entry.name}/`;
    const children = await readDirectoryEntries(entry.createReader());
    const nested = [];
    for (const child of children) nested.push(...await walkDroppedEntry(child, nextPrefix));
    return nested;
  }

  async function tasksFromDrop(dataTransfer) {
    const items = [...(dataTransfer?.items || [])].filter(item => item.kind === 'file');
    const entries = items.map(item => item.webkitGetAsEntry?.()).filter(Boolean);
    if (entries.some(entry => entry.isDirectory)) {
      const tasks = [];
      for (const entry of entries) tasks.push(...await walkDroppedEntry(entry));
      return {tasks, mode:'folder'};
    }
    return {tasks:[...(dataTransfer?.files || [])], mode:'multiple'};
  }

  async function refreshUndoState() {
    const btn = qs('#nobash-files-undo');
    if (!hasPerm('undo')) { if (btn) btn.hidden = true; return; }
    const label = qs('#nobash-files-undo-label');
    if (!btn || !label) return;
    try {
      const state = await api('undo-state');
      btn.disabled = !state.can_undo;
      label.textContent = state.can_undo && state.last ? `Undo ${state.last.label}` : 'Undo';
      btn.title = state.can_undo && state.last ? state.last.label : 'Nothing to undo';
    } catch (_) {
      btn.disabled = true; label.textContent = 'Undo';
    }
  }

  async function undoLast() {
    const btn = qs('#nobash-files-undo');
    if (!btn || btn.disabled) return;
    try {
      btn.disabled = true;
      const result = await api('undo', {method:'POST', body:'{}'});
      notice(result.message || 'Last operation undone.');
      if (['manager','editor'].includes(view)) await loadDir(currentPath);
      await loadPreferences();
      await refreshUndoState();
    } catch (e) {
      notice(e.message, 'error'); await refreshUndoState();
    }
  }

  const EDITOR_DRAFT_KEY = 'nfmEditorDraftsV2';
  const EDITOR_SESSION_KEY = 'nfmEditorSessionV2';
  const EDITOR_DRAFT_MAX_BYTES = 524288;
  const EDITOR_DRAFT_TOTAL_BYTES = 3145728;
  let editorTabs = new Map();
  let activeEditorPath = '';
  let codeMirror = null;
  let editorDraftTimer = null;
  let editorFindCursor = 0;
  let editorEngineAttempted = false;
  let editorFallbackBound = false;
  let editorMinimapTimer = null;

  function editorMode(language = 'text') {
    const modes = {
      php: 'application/x-httpd-php', css: 'text/css', javascript: 'text/javascript', js: 'text/javascript',
      json: 'application/json', html: 'text/html', xml: 'application/xml', yaml: 'text/x-yaml',
      markdown: 'text/x-markdown', sql: 'text/x-sql', shell: 'text/x-sh', text: 'text/plain'
    };
    return modes[String(language).toLowerCase()] || 'text/plain';
  }

  function editorSettingsFor(language = 'text') {
    const defaults = (window.wp?.codeEditor?.defaultSettings && typeof window.wp.codeEditor.defaultSettings === 'object')
      ? window.wp.codeEditor.defaultSettings : {};
    const localized = (NobashFiles.editorSettings && typeof NobashFiles.editorSettings === 'object') ? NobashFiles.editorSettings : {};
    const base = {...defaults, ...localized};
    const lang = String(language || 'text').toLowerCase();
    const indent = ['php','javascript','js'].includes(lang) ? 4 : 2;
    base.codemirror = {
      ...(defaults.codemirror || {}), ...(localized.codemirror || {}),
      mode: editorMode(language), lineNumbers: true, lineWrapping: !!NobashFiles.editorWordWrap, indentUnit: indent, tabSize: indent,
      indentWithTabs: false, matchBrackets: true, autoCloseBrackets: true, styleActiveLine: true,
      viewportMargin: 30
    };
    return base;
  }

  function bindTextareaEditorFallback() {
    const ed = qs('#nobash-files-editor');
    if (!ed || editorFallbackBound) return;
    editorFallbackBound = true;
    ed.addEventListener('input', onEditorChanged);
    ed.addEventListener('scroll', () => { const lines=qs('#nobash-files-lines'); if(lines) lines.scrollTop = ed.scrollTop; updateEditorMinimapViewport(); });
    ed.addEventListener('click', updateCursor);
    ed.addEventListener('keyup', updateCursor);
    ed.addEventListener('keydown', e => {
      if (e.key === 'Tab') {
        e.preventDefault();
        const start = ed.selectionStart, end = ed.selectionEnd;
        ed.setRangeText('  ', start, end, 'end'); onEditorChanged();
      }
    });
  }

  function setSourceLoading(loading, message = 'Loading source…') {
    const el = qs('#nobash-files-source-loading');
    if (!el) return;
    el.hidden = !loading;
    const strong = el.querySelector('strong');
    if (strong) strong.textContent = message;
  }

  function fallbackToTextarea(source = '', reason = '') {
    const text = String(source ?? '');
    try { if (codeMirror?.toTextArea) codeMirror.toTextArea(); } catch (_) {}
    codeMirror = null;
    qs('#nobash-files-code-shell')?.classList.remove('has-codemirror');
    const ed = qs('#nobash-files-editor');
    if (ed) {
      ed.hidden = false;
      ed.style.display = '';
      ed.value = text;
    }
    qs('#nobash-files-lines')?.classList.remove('is-hidden');
    bindTextareaEditorFallback();
    updateLines(); updateCursor();
    if (reason) {
      const state = qs('#nobash-files-editor-state');
      if (state) state.textContent = 'Source loaded · basic editor mode';
    }
  }

  function initCodeEditorEngine(language = 'text') {
    const textarea = qs('#nobash-files-editor');
    if (!textarea || codeMirror) return Boolean(codeMirror);
    if (editorEngineAttempted || !window.wp?.codeEditor?.initialize) {
      bindTextareaEditorFallback();
      return false;
    }
    editorEngineAttempted = true;
    const sourceBeforeInit = textarea.value || '';
    try {
      const instance = window.wp.codeEditor.initialize(textarea, editorSettingsFor(language));
      codeMirror = instance?.codemirror || null;
      if (!codeMirror) {
        fallbackToTextarea(sourceBeforeInit, 'Enhanced editor unavailable');
        return false;
      }
      qs('#nobash-files-lines')?.classList.add('is-hidden');
      qs('#nobash-files-code-shell')?.classList.add('has-codemirror');
      codeMirror.on('change', onEditorChanged);
      codeMirror.on('cursorActivity', updateCursor);
      codeMirror.on('scroll', updateEditorMinimapViewport);
      codeMirror.setSize('100%', '100%');
      codeMirror.setOption('mode', editorMode(language));
      // WordPress CodeMirror can occasionally paint blank until its first refresh when created in a grid.
      // Seed from the already-loaded textarea, verify the document, then refresh after layout settles.
      if (codeMirror.getValue() !== sourceBeforeInit) codeMirror.setValue(sourceBeforeInit);
      if (codeMirror.getValue() !== sourceBeforeInit) {
        fallbackToTextarea(sourceBeforeInit, 'Enhanced editor source verification failed');
        return false;
      }
      requestAnimationFrame(() => codeMirror?.refresh());
      setTimeout(() => codeMirror?.refresh(), 80);
      return true;
    } catch (_) {
      fallbackToTextarea(sourceBeforeInit, 'Enhanced editor initialization failed');
      return false;
    }
  }

  function editorGetValue() {
    return codeMirror ? codeMirror.getValue() : (qs('#nobash-files-editor')?.value || '');
  }

  function editorSetValue(value, preserveCursor = false) {
    const text = String(value ?? '');
    if (codeMirror) {
      const cursor = preserveCursor ? codeMirror.getCursor() : null;
      codeMirror.setValue(text);
      if (cursor) codeMirror.setCursor(cursor);
      codeMirror.refresh();
    } else {
      const ed = qs('#nobash-files-editor');
      if (ed) ed.value = text;
    }
    updateLines(); updateCursor(); renderEditorMinimap();
  }

  function editorFocus() {
    if (codeMirror) codeMirror.focus();
    else qs('#nobash-files-editor')?.focus();
  }

  function editorSelectionRange() {
    if (codeMirror) {
      const doc = codeMirror.getDoc();
      return {start: doc.indexFromPos(doc.getCursor('from')), end: doc.indexFromPos(doc.getCursor('to'))};
    }
    const ed = qs('#nobash-files-editor');
    return {start: ed?.selectionStart || 0, end: ed?.selectionEnd || 0};
  }

  function editorSelect(start, end) {
    if (codeMirror) {
      const doc = codeMirror.getDoc();
      doc.setSelection(doc.posFromIndex(start), doc.posFromIndex(end));
      codeMirror.scrollIntoView({from: doc.posFromIndex(start), to: doc.posFromIndex(end)}, 80);
      codeMirror.focus();
      return;
    }
    const ed = qs('#nobash-files-editor');
    if (!ed) return;
    ed.focus(); ed.setSelectionRange(start, end);
  }

  function editorReplaceRange(start, end, replacement) {
    const value = editorGetValue();
    const next = value.slice(0, start) + replacement + value.slice(end);
    editorSetValue(next);
    editorSelect(start, start + replacement.length);
    onEditorChanged();
  }

  function draftStoreRead() {
    try {
      const parsed = JSON.parse(localStorage.getItem(EDITOR_DRAFT_KEY) || '{}');
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (_) { return {}; }
  }

  function draftStoreWrite(drafts) {
    try { localStorage.setItem(EDITOR_DRAFT_KEY, JSON.stringify(drafts)); return true; }
    catch (_) { return false; }
  }

  function getDraft(path) { return draftStoreRead()[path] || null; }

  function clearDraft(path) {
    const drafts = draftStoreRead();
    if (drafts[path]) { delete drafts[path]; draftStoreWrite(drafts); }
  }

  function persistDraft(tab) {
    const state = qs('#nobash-files-draft-state');
    if (!tab || !tab.dirty) {
      if (tab) clearDraft(tab.file.path);
      if (state) state.innerHTML = '<span class="nobash-files-status-dot"></span> Draft recovery ready';
      return;
    }
    const content = tab.content;
    if (new Blob([content]).size > EDITOR_DRAFT_MAX_BYTES) {
      if (state) state.innerHTML = '<span class="nobash-files-status-dot warn"></span> Draft too large for browser recovery';
      return;
    }
    let drafts = draftStoreRead();
    drafts[tab.file.path] = {content, baseChecksum: tab.file.checksum || '', updatedAt: Date.now()};
    let entries = Object.entries(drafts).sort((a,b) => (b[1]?.updatedAt || 0) - (a[1]?.updatedAt || 0)).slice(0, 8);
    let total = 0, trimmed = {};
    for (const [path, draft] of entries) {
      const bytes = new Blob([draft.content || '']).size;
      if (total + bytes > EDITOR_DRAFT_TOTAL_BYTES) continue;
      trimmed[path] = draft; total += bytes;
    }
    const ok = draftStoreWrite(trimmed);
    if (state) state.innerHTML = ok ? '<span class="nobash-files-status-dot saved"></span> Recovery draft saved' : '<span class="nobash-files-status-dot warn"></span> Draft storage unavailable';
  }

  function persistEditorSession() {
    try {
      localStorage.setItem(EDITOR_SESSION_KEY, JSON.stringify({tabs:[...editorTabs.keys()].slice(0,8), active:activeEditorPath, updatedAt:Date.now()}));
    } catch (_) {}
  }

  function syncActiveTab() {
    const tab = editorTabs.get(activeEditorPath);
    if (!tab) return;
    tab.content = editorGetValue();
    tab.dirty = tab.content !== tab.original;
    renderEditorTabs();
    clearTimeout(editorDraftTimer);
    editorDraftTimer = setTimeout(() => persistDraft(tab), 650);
  }

  function onEditorChanged() {
    if (!activeEditorPath) return;
    syncActiveTab(); updateLines(); updateCursor(); scheduleEditorMinimap();
    setValidation('neutral', 'Not validated', 'Unsaved changes will be validated before saving.');
    const state = qs('#nobash-files-editor-state');
    const tab = editorTabs.get(activeEditorPath);
    if (state) state.textContent = tab?.dirty ? 'Unsaved changes' : 'Saved';
  }

  function renderEditorTabs() {
    const host = qs('#nobash-files-editor-tabs'); if (!host) return;
    const addButton = '<button type="button" class="nobash-files-editor-tab-add" id="nobash-files-editor-tab-add" aria-label="Open another file" title="Open another file"><span class="dashicons dashicons-plus-alt2"></span></button>';
    if (!editorTabs.size) {
      host.innerHTML = '<div class="nobash-files-editor-tab is-active nobash-files-editor-tab-empty">Open a file from the tree</div>' + addButton;
    } else {
      host.innerHTML = [...editorTabs.values()].map(tab => {
        const path = tab.file.path;
        const lang = String(tab.file.language || tab.file.extension || 'txt').toUpperCase();
        return `<button type="button" class="nobash-files-editor-tab ${path === activeEditorPath ? 'is-active' : ''}" data-editor-tab="${esc(path)}" title="${esc(path)}"><span class="nobash-files-tab-filetype">${esc(lang.slice(0,4))}</span><span class="nobash-files-tab-name">${esc(tab.file.name)}</span>${tab.dirty ? '<i class="nobash-files-tab-dirty" title="Unsaved changes"></i>' : ''}<span class="nobash-files-tab-close" data-editor-close="${esc(path)}" aria-label="Close">×</span></button>`;
      }).join('') + addButton;
    }
    qsa('[data-editor-tab]', host).forEach(btn => btn.addEventListener('click', e => {
      if (e.target.closest('[data-editor-close]')) return;
      switchEditorTab(btn.dataset.editorTab);
    }));
    qsa('[data-editor-close]', host).forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); closeEditorTab(btn.dataset.editorClose); }));
    qs('#nobash-files-editor-tab-add', host)?.addEventListener('click', () => {
      qs('#nobash-files-tree')?.scrollIntoView({behavior:'smooth', block:'nearest'});
      notice('Choose another editable file from the Files panel.');
    });
  }

  async function resolveDraftForFile(data, allowPrompt = true) {
    const draft = getDraft(data.path);
    if (!draft || draft.content === data.content) return {content:data.content, recovered:false};
    if (draft.baseChecksum === data.checksum) return {content:draft.content, recovered:true};
    if (!allowPrompt) return {content:data.content, recovered:false};
    const answer = await showModal({
      title:'Unsaved recovery draft found',
      message:'A browser recovery draft exists, but the live file changed since that draft was created. Restore the draft only if you want to compare or recover those unsaved edits.',
      confirmText:'Restore draft', input:false
    });
    return answer.confirmed ? {content:draft.content, recovered:true} : {content:data.content, recovered:false};
  }

  async function loadEditorTab(path, activate = true, allowDraftPrompt = true) {
    if (editorTabs.has(path)) { if (activate) await switchEditorTab(path); return editorTabs.get(path); }
    const data = await api('file?path=' + encodeURIComponent(path));
    const recovery = await resolveDraftForFile(data, allowDraftPrompt);
    const tab = {file:data, original:data.content, content:recovery.content, dirty:recovery.content !== data.content, recovered:recovery.recovered};
    editorTabs.set(data.path, tab);
    renderEditorTabs(); persistEditorSession();
    if (activate) await switchEditorTab(data.path);
    if (recovery.recovered && activate) notice('Recovered unsaved browser draft. Review it before saving.');
    return tab;
  }

  async function openEditorFile(path) {
    setSourceLoading(true);
    try { await loadEditorTab(path, true, true); }
    catch (e) { notice(e.message, 'error'); setValidation('bad', 'Unable to load source', e.message); }
    finally { setSourceLoading(false); }
  }

  async function switchEditorTab(path) {
    if (!editorTabs.has(path)) return;
    if (activeEditorPath && activeEditorPath !== path) syncActiveTab();
    activeEditorPath = path;
    const tab = editorTabs.get(path);
    currentFile = tab.file;
    originalContent = tab.original;
    const source = String(tab.content ?? '');
    // Always populate the real textarea first. WordPress CodeMirror then adopts that exact source.
    if (!codeMirror) {
      const textarea = qs('#nobash-files-editor');
      if (textarea) textarea.value = source;
      initCodeEditorEngine(tab.file.language);
    }
    editorSetValue(source);
    if (codeMirror) {
      codeMirror.setOption('mode', editorMode(tab.file.language));
      if (codeMirror.getValue() !== source) {
        fallbackToTextarea(source, 'Enhanced editor source verification failed');
      } else {
        requestAnimationFrame(() => codeMirror?.refresh());
        setTimeout(() => codeMirror?.refresh(), 80);
      }
    }
    breadcrumbs(tab.file.path);
    const langName = String(tab.file.language || 'text').toLowerCase();
    const lang = qs('#nobash-files-language'); if (lang) lang.textContent = langName.toUpperCase();
    const indentState = qs('#nobash-files-indent-state'); if (indentState) indentState.textContent = `Spaces: ${['php','javascript','js'].includes(langName) ? 4 : 2}`;
    renderEditorTabs(); renderFileInfo(tab.file); updateCursor(); renderEditorMinimap();
    setValidation('neutral', tab.dirty ? 'Not validated' : 'Ready to validate', tab.dirty ? 'Recovered or unsaved changes are waiting.' : 'Validation runs before every safe save.');
    await loadVersions(tab.file.path);
    addRecentPath(tab.file.path);
    persistEditorSession();
    const state = qs('#nobash-files-editor-state'); if (state) state.textContent = tab.dirty ? 'Unsaved changes' : 'Saved';
    if (tab.dirty) persistDraft(tab);
    editorFocus();
  }

  async function closeEditorTab(path) {
    const tab = editorTabs.get(path); if (!tab) return;
    if (path === activeEditorPath) syncActiveTab();
    if (tab.dirty) {
      const answer = await showModal({title:`Close ${tab.file.name}?`, message:'This tab has unsaved changes. Closing it will discard the browser recovery draft for this file.', confirmText:'Discard changes', danger:true, input:false});
      if (!answer.confirmed) return;
      clearDraft(path);
    }
    const keys = [...editorTabs.keys()], idx = keys.indexOf(path);
    editorTabs.delete(path);
    if (activeEditorPath === path) {
      activeEditorPath = '';
      const next = [...editorTabs.keys()][Math.max(0, idx - 1)] || [...editorTabs.keys()][0] || '';
      if (next) await switchEditorTab(next);
      else resetEditorEmpty();
    }
    renderEditorTabs(); persistEditorSession();
  }

  function resetEditorEmpty() {
    currentFile = null; originalContent = ''; activeEditorPath = '';
    editorSetValue(''); renderEditorTabs();
    const info = qs('#nobash-files-file-info'); if (info) info.innerHTML = '<div class="nobash-files-empty"><span class="dashicons dashicons-editor-code"></span><strong>No file open</strong><p>Select a text or code file to begin.</p></div>';
    const versions = qs('#nobash-files-versions'); if (versions) versions.innerHTML = '<p class="nobash-files-muted">Open a file to view versions.</p>';
    const restore = qs('#nobash-files-editor-restore-latest'); if (restore) { restore.disabled = true; restore.dataset.versionId = ''; }
    ['#nobash-files-editor-backup','#nobash-files-editor-duplicate'].forEach(sel => { const btn=qs(sel); if(btn) btn.disabled=true; });
    renderEditorMinimap();
    setValidation('neutral', 'Not validated', 'Open a file to begin.');
  }

  function renderFileInfo(data) {
    const el = qs('#nobash-files-file-info'); if (!el) return;
    const tab = editorTabs.get(data.path);
    const lang = String(data.language || data.extension || 'text').toUpperCase();
    const owner = data.owner_name ? `${data.owner_name}${data.owner_id !== null && data.owner_id !== undefined ? ` (${data.owner_id})` : ''}` : (data.owner_id !== null && data.owner_id !== undefined ? String(data.owner_id) : '—');
    const group = data.group_name ? `${data.group_name}${data.group_id !== null && data.group_id !== undefined ? ` (${data.group_id})` : ''}` : (data.group_id !== null && data.group_id !== undefined ? String(data.group_id) : '—');
    el.innerHTML = `<div class="nobash-files-info-title-row"><div class="nobash-files-card-title">File Info</div><span class="nobash-files-code-type-badge">${esc(lang.slice(0,5))}</span></div><div class="nobash-files-file-hero nobash-files-editor-file-hero"><span class="dashicons dashicons-editor-code"></span><div><strong>${esc(data.name)}</strong><p>/${esc(data.path)}</p></div></div><dl class="nobash-files-dl nobash-files-editor-info-list"><dt>Size</dt><dd>${fmtSize(data.size)}</dd><dt>Modified</dt><dd>${fmtDate(data.modified)}</dd><dt>Permissions</dt><dd>${esc(data.permissions)}</dd><dt>Owner</dt><dd>${esc(owner)}</dd><dt>Group</dt><dd>${esc(group)}</dd><dt>Checksum</dt><dd><code class="nobash-files-checksum">${esc((data.checksum || '').slice(0,12))}…</code></dd><dt>Status</dt><dd>${data.protected ? protectedMarker : '<span class="nobash-files-inline-status">Versioned</span>'}${tab?.dirty ? ' <span class="nobash-files-inline-status is-unsaved">Unsaved</span>' : ''}</dd></dl>`;
    const backup = qs('#nobash-files-editor-backup'); if (backup) backup.disabled = !currentFile || !hasPerm('versions');
    const duplicate = qs('#nobash-files-editor-duplicate'); if (duplicate) duplicate.disabled = !currentFile || !hasPerm('copy');
  }

  async function validateCurrent() {
    if (!currentFile) return notice('Open a file first.', 'error');
    try {
      const data = await api('validate', {method:'POST', body:JSON.stringify({path:currentFile.path, content:editorGetValue()})});
      setValidation(data.ok ? 'ok' : 'bad', data.ok ? 'Syntax valid' : 'Validation failed', data.message);
      if (data.ok) notice('Validation passed.');
      return data;
    } catch (e) {
      setValidation('bad', 'Validation failed', e.message); notice(e.message, 'error'); return null;
    }
  }

  function setValidation(kind, title, text) {
    const el = qs('#nobash-files-validation-status'); if (!el) return;
    el.className = `nobash-files-validation ${kind}`;
    el.innerHTML = `<span class="dashicons ${kind === 'ok' ? 'dashicons-yes-alt' : kind === 'bad' ? 'dashicons-warning' : 'dashicons-minus'}"></span><div><strong>${esc(title)}</strong><p>${esc(text)}</p></div>`;
  }

  async function saveCurrent(confirmProtectedFlag = false, force = false) {
    const tab = editorTabs.get(activeEditorPath);
    if (!tab) return notice('Open a file first.', 'error');
    syncActiveTab();
    const content = tab.content;
    if (!tab.dirty && !force) return notice('No unsaved changes.');
    try {
      const validation = await api('validate', {method:'POST', body:JSON.stringify({path:tab.file.path, content})});
      if (!validation.ok) {
        setValidation('bad', 'Save blocked', validation.message);
        return notice(validation.message, 'error');
      }
      const data = await api('file', {method:'POST', body:JSON.stringify({
        path:tab.file.path, content, confirm_protected:confirmProtectedFlag,
        expected_checksum:tab.file.checksum || '', force
      })});
      tab.original = content; tab.content = content; tab.dirty = false; tab.recovered = false;
      tab.file = {...tab.file, ...data, content};
      currentFile = tab.file; originalContent = content;
      clearDraft(tab.file.path); renderEditorTabs(); renderFileInfo(tab.file);
      setValidation('ok', 'Syntax valid', data.validation?.message || 'Validation passed.');
      const state = qs('#nobash-files-editor-state'); if (state) state.textContent = 'Saved';
      const draftState = qs('#nobash-files-draft-state'); if (draftState) draftState.innerHTML = '<span class="nobash-files-status-dot saved"></span> Saved safely';
      await loadVersions(tab.file.path); await refreshUndoState(); persistEditorSession();
      notice(force ? 'File overwritten safely after snapshotting the newer disk version.' : 'File saved safely.');
    } catch (e) {
      if (!confirmProtectedFlag && e.code === 'protected_confirm_required') {
        if (await confirmProtected()) return saveCurrent(true, force);
        return;
      }
      if (!force && e.code === 'file_changed_on_disk') return handleExternalConflict(tab);
      setValidation('bad', 'Save blocked', e.message);
      notice(e.message, 'error');
    }
  }

  async function handleExternalConflict(tab) {
    try {
      const latest = await api('file?path=' + encodeURIComponent(tab.file.path));
      tab.external = latest;
      setValidation('bad', 'File changed outside this editor', 'Saving is blocked until you review the newer live file.');
      showDiffBetween(latest.content, editorGetValue(), 'External change conflict', 'Live file on disk', 'Your editor');
      const card = qs('#nobash-files-diff-card');
      const host = qs('#nobash-files-diff-content');
      if (host) host.insertAdjacentHTML('afterbegin', `<div class="nobash-files-conflict-actions"><div><strong>Another process changed this file.</strong><p>Compare first. Reload keeps the live change; overwrite snapshots the live file before replacing it.</p></div><button class="button" id="nobash-files-conflict-reload">Reload Latest</button><button class="button button-primary" id="nobash-files-conflict-force">Overwrite Safely</button></div>`);
      qs('#nobash-files-conflict-reload')?.addEventListener('click', () => reloadEditorFromDisk(true));
      qs('#nobash-files-conflict-force')?.addEventListener('click', () => saveCurrent(false, true));
      if (card) card.hidden = false;
      notice('Live file changed since you opened it. Save was blocked.', 'error');
    } catch (e) { notice(e.message, 'error'); }
  }

  async function reloadEditorFromDisk(discard = false) {
    const tab = editorTabs.get(activeEditorPath); if (!tab) return;
    syncActiveTab();
    if (tab.dirty && !discard) {
      const answer = await showModal({title:'Reload latest file?', message:'Your unsaved editor changes will be discarded. The browser recovery draft will also be cleared.', confirmText:'Reload latest', danger:true, input:false});
      if (!answer.confirmed) return;
    }
    try {
      const data = await api('file?path=' + encodeURIComponent(tab.file.path));
      tab.file = data; tab.original = data.content; tab.content = data.content; tab.dirty = false; tab.external = null;
      currentFile = data; originalContent = data.content; clearDraft(data.path);
      editorSetValue(data.content); renderEditorTabs(); renderFileInfo(data); await loadVersions(data.path);
      setValidation('neutral', 'Reloaded from disk', 'The latest live file is now open.');
      qs('#nobash-files-diff-card').hidden = true; notice('Latest live file reloaded.');
    } catch (e) { notice(e.message, 'error'); }
  }

  async function loadVersions(path) {
    const el = qs('#nobash-files-versions'); if (!el) return;
    const restoreLatest = qs('#nobash-files-editor-restore-latest');
    if (restoreLatest) { restoreLatest.disabled = true; restoreLatest.dataset.versionId = ''; }
    if (!hasPerm('versions')) { el.innerHTML = '<p class="nobash-files-muted">Version history is not included in your access profile.</p>'; return; }
    try {
      const versions = await api('versions?path=' + encodeURIComponent(path));
      if (restoreLatest && versions.length && hasPerm('restore')) { restoreLatest.disabled = false; restoreLatest.dataset.versionId = String(versions[0].id); }
      const labels = {manual:'Manual backup', restore:'Pre-restore snapshot', save:'Safety version'};
      el.innerHTML = versions.length ? versions.slice(0, 6).map((v, i) => `<div class="nobash-files-version-row"><span class="dashicons dashicons-clock"></span><div><strong>${esc(labels[v.action] || 'Safety version')}</strong><small>${esc(v.created_at)} · ${fmtSize(Number(v.file_size))}</small></div>${i === 0 ? '<span class="nobash-files-version-current">Latest</span>' : ''}<div class="nobash-files-version-actions"><button class="nobash-files-version-icon nobash-files-compare-version" data-id="${v.id}" title="Compare" aria-label="Compare"><span class="dashicons dashicons-image-flip-horizontal"></span></button><button class="nobash-files-version-icon nobash-files-restore-version" data-id="${v.id}" title="Restore" aria-label="Restore"><span class="dashicons dashicons-backup"></span></button></div></div>`).join('') : '<p class="nobash-files-muted">No previous versions yet.</p>';
      qsa('.nobash-files-restore-version', el).forEach(b => b.addEventListener('click', () => restoreVersion(Number(b.dataset.id))));
      qsa('.nobash-files-compare-version', el).forEach(b => b.addEventListener('click', () => compareVersion(Number(b.dataset.id))));
    } catch (e) { el.innerHTML = `<p class="nobash-files-muted">${esc(e.message)}</p>`; }
  }

  async function compareVersion(id) {
    const tab = editorTabs.get(activeEditorPath); if (!tab) return;
    try {
      const version = await api('version?id=' + encodeURIComponent(id));
      showDiffBetween(version.content, editorGetValue(), `Compare · ${version.created_at}`, 'Selected version', 'Editor now');
    } catch (e) { notice(e.message, 'error'); }
  }

  async function restoreVersion(id) {
    const answer = await showModal({title:'Restore this version?', message:'The current live file will be snapshotted first, then the selected version will become live. Unsaved editor changes will be discarded.', confirmText:'Restore version', input:false});
    if (!answer.confirmed) return;
    try {
      const data = await api('restore', {method:'POST', body:JSON.stringify({version_id:id})});
      const tab = editorTabs.get(activeEditorPath);
      if (tab) { tab.file = data; tab.original = data.content; tab.content = data.content; tab.dirty = false; tab.external = null; }
      currentFile = data; originalContent = data.content; clearDraft(data.path); editorSetValue(data.content);
      renderEditorTabs(); renderFileInfo(data); await loadVersions(data.path); notice('Version restored safely.');
    } catch (e) { notice(e.message, 'error'); }
  }

  function boundedLineDiff(oldText, newText) {
    const a = String(oldText).split('\n'), b = String(newText).split('\n');
    let prefix = 0;
    while (prefix < a.length && prefix < b.length && a[prefix] === b[prefix]) prefix++;
    let suffix = 0;
    while (suffix < a.length-prefix && suffix < b.length-prefix && a[a.length-1-suffix] === b[b.length-1-suffix]) suffix++;
    const aa = a.slice(prefix, a.length-suffix), bb = b.slice(prefix, b.length-suffix);
    const out = [];
    if (aa.length <= 260 && bb.length <= 260) {
      const dp = Array.from({length:aa.length+1}, () => new Uint16Array(bb.length+1));
      for (let i=aa.length-1;i>=0;i--) for (let j=bb.length-1;j>=0;j--) dp[i][j] = aa[i] === bb[j] ? dp[i+1][j+1]+1 : Math.max(dp[i+1][j],dp[i][j+1]);
      let i=0,j=0,oldNo=prefix+1,newNo=prefix+1;
      while (i<aa.length || j<bb.length) {
        if (i<aa.length && j<bb.length && aa[i]===bb[j]) { oldNo++; newNo++; i++; j++; continue; }
        if (j<bb.length && (i===aa.length || dp[i][j+1] >= dp[i+1][j])) { out.push({type:'add',oldNo:'',newNo:newNo++,text:bb[j++]}); }
        else { out.push({type:'remove',oldNo:oldNo++,newNo:'',text:aa[i++]}); }
      }
    } else {
      aa.forEach((line,i) => out.push({type:'remove',oldNo:prefix+i+1,newNo:'',text:line}));
      bb.forEach((line,i) => out.push({type:'add',oldNo:'',newNo:prefix+i+1,text:line}));
    }
    return out;
  }

  function showDiffBetween(oldText, newText, title = 'Unsaved changes', oldLabel = 'Saved', newLabel = 'Editor') {
    const card = qs('#nobash-files-diff-card'), content = qs('#nobash-files-diff-content'), titleEl = qs('#nobash-files-diff-title');
    if (!card || !content) return;
    if (titleEl) titleEl.textContent = title;
    card.hidden = false;
    if (oldText === newText) { content.innerHTML = '<p class="nobash-files-muted nobash-files-diff-empty">No differences.</p>'; return; }
    const rows = boundedLineDiff(oldText, newText);
    const adds = rows.filter(r => r.type === 'add').length, removes = rows.length - adds;
    let html = `<div class="nobash-files-diff-summary"><span>${esc(oldLabel)} → ${esc(newLabel)}</span><strong>+${adds} / −${removes}</strong></div>`;
    for (const row of rows.slice(0, 1200)) html += `<div class="nobash-files-diff-line ${row.type}"><span class="old-no">${row.oldNo}</span><span class="new-no">${row.newNo}</span><code>${row.type === 'add' ? '+' : '−'} ${esc(row.text)}</code></div>`;
    if (rows.length > 1200) html += '<p class="nobash-files-muted nobash-files-diff-empty">Diff preview truncated after 1,200 changed lines.</p>';
    content.innerHTML = html;
  }

  function showDiff() {
    const tab = editorTabs.get(activeEditorPath);
    if (!tab) return notice('Open a file first.', 'error');
    syncActiveTab(); showDiffBetween(tab.original, tab.content, 'Unsaved changes', 'Last saved', 'Editor now');
  }

  function updateLines() {
    const ed = qs('#nobash-files-editor'), lines = qs('#nobash-files-lines'); if (!ed || !lines || codeMirror) return;
    const count = Math.max(1, ed.value.split('\n').length);
    lines.textContent = Array.from({length:count}, (_, i) => i + 1).join('\n');
    lines.scrollTop = ed.scrollTop;
  }

  function updateCursor() {
    const out = qs('#nobash-files-cursor'); if (!out) return;
    if (codeMirror) {
      const cur = codeMirror.getCursor(); out.textContent = `Ln ${cur.line + 1}, Col ${cur.ch + 1}`; return;
    }
    const ed = qs('#nobash-files-editor'); if (!ed) return;
    const pre = ed.value.slice(0, ed.selectionStart).split('\n');
    out.textContent = `Ln ${pre.length}, Col ${pre[pre.length - 1].length + 1}`;
  }

  function minimapLineClass(line) {
    const t = String(line || '').trim();
    if (!t) return 'blank';
    if (/^(\/\*|\*|\/\/|#)/.test(t)) return 'comment';
    if (/['"]/.test(t)) return 'string';
    if (/\b(function|class|interface|trait|public|private|protected|return|if|else|foreach|for|while|switch|case|define|const|new|use|namespace)\b/.test(t)) return 'keyword';
    return 'code';
  }

  function scheduleEditorMinimap() {
    clearTimeout(editorMinimapTimer);
    editorMinimapTimer = setTimeout(renderEditorMinimap, 120);
  }

  function renderEditorMinimap() {
    const host = qs('#nobash-files-minimap-code'); if (!host) return;
    const lines = editorGetValue().split('\n');
    const maxRows = 360;
    const step = Math.max(1, Math.ceil(lines.length / maxRows));
    const rows = [];
    for (let i = 0; i < lines.length; i += step) {
      const line = lines[i] || '';
      const width = Math.max(8, Math.min(96, Math.round((line.replace(/\t/g,'    ').length / 105) * 100)));
      rows.push(`<i class="${minimapLineClass(line)}" style="width:${width}%"></i>`);
    }
    host.innerHTML = rows.join('');
    updateEditorMinimapViewport();
  }

  function updateEditorMinimapViewport() {
    const viewport = qs('#nobash-files-minimap-viewport'); if (!viewport) return;
    let top = 0, ratio = 1;
    if (codeMirror) {
      const info = codeMirror.getScrollInfo();
      ratio = Math.min(1, info.clientHeight / Math.max(info.height, 1));
      top = Math.max(0, Math.min(1 - ratio, info.top / Math.max(info.height, 1)));
    } else {
      const ed = qs('#nobash-files-editor');
      if (ed) { ratio = Math.min(1, ed.clientHeight / Math.max(ed.scrollHeight,1)); top = Math.max(0, Math.min(1-ratio, ed.scrollTop / Math.max(ed.scrollHeight,1))); }
    }
    viewport.style.height = `${Math.max(12, ratio * 100)}%`;
    viewport.style.top = `${top * 100}%`;
  }

  function toggleFind(open = null) {
    const panel = qs('#nobash-files-find-panel'); if (!panel) return;
    const show = open === null ? panel.hidden : open;
    panel.hidden = !show;
    if (show) { const input = qs('#nobash-files-find-text'); input?.focus(); input?.select(); }
    else editorFocus();
  }

  function openReplacePanel() {
    toggleFind(true);
    const input = qs('#nobash-files-replace-text');
    if (input) { input.focus(); input.select(); }
  }

  function findNext() {
    const needle = qs('#nobash-files-find-text')?.value || '';
    if (!needle) return;
    const hay = editorGetValue();
    const selection = editorSelectionRange();
    let index = hay.indexOf(needle, Math.max(selection.end, editorFindCursor));
    let wrapped = false;
    if (index < 0) { index = hay.indexOf(needle); wrapped = true; }
    const meta = qs('#nobash-files-find-meta');
    if (index < 0) { if (meta) meta.textContent = 'No matches'; return; }
    editorFindCursor = index + needle.length;
    editorSelect(index, index + needle.length);
    if (meta) meta.textContent = wrapped ? 'Wrapped to first match' : `Match at character ${index + 1}`;
  }

  function replaceOne() {
    const needle = qs('#nobash-files-find-text')?.value || '', replacement = qs('#nobash-files-replace-text')?.value || '';
    if (!needle) return;
    const sel = editorSelectionRange(), text = editorGetValue();
    if (text.slice(sel.start, sel.end) !== needle) { findNext(); return; }
    editorReplaceRange(sel.start, sel.end, replacement); editorFindCursor = sel.start + replacement.length; findNext();
  }

  function replaceAll() {
    const needle = qs('#nobash-files-find-text')?.value || '', replacement = qs('#nobash-files-replace-text')?.value || '';
    if (!needle) return;
    const text = editorGetValue();
    const count = text.split(needle).length - 1;
    if (!count) { const meta=qs('#nobash-files-find-meta'); if(meta) meta.textContent='No matches'; return; }
    editorSetValue(text.split(needle).join(replacement)); onEditorChanged();
    const meta = qs('#nobash-files-find-meta'); if (meta) meta.textContent = `Replaced ${count} occurrence${count===1?'':'s'}`;
  }

  const editorCommands = [
    {id:'save', label:'Save current file', shortcut:'⌘S', run:() => saveCurrent(false)},
    {id:'validate', label:'Validate syntax', shortcut:'', run:validateCurrent},
    {id:'find', label:'Find and replace', shortcut:'⌘F', run:() => toggleFind(true)},
    {id:'compare', label:'Compare unsaved changes', shortcut:'', run:showDiff},
    {id:'reload', label:'Reload latest file from disk', shortcut:'', run:() => reloadEditorFromDisk(false)},
    {id:'revert', label:'Revert unsaved changes', shortcut:'', run:revertUnsaved},
    {id:'close', label:'Close current tab', shortcut:'', run:() => activeEditorPath && closeEditorTab(activeEditorPath)},
    {id:'fullscreen', label:'Toggle full screen editor', shortcut:'', run:() => qs('.nobash-files-editor-panel')?.classList.toggle('is-fullscreen')}
  ];

  function renderCommandPalette(filter = '') {
    const host = qs('#nobash-files-command-results'); if (!host) return;
    const q = filter.trim().toLowerCase();
    const rows = editorCommands.filter(c => !q || c.label.toLowerCase().includes(q));
    host.innerHTML = rows.length ? rows.map(c => `<button type="button" data-command="${c.id}"><span>${esc(c.label)}</span><kbd>${esc(c.shortcut)}</kbd></button>`).join('') : '<p class="nobash-files-muted">No matching commands.</p>';
    qsa('[data-command]', host).forEach(btn => btn.addEventListener('click', () => runCommand(btn.dataset.command)));
  }

  function toggleCommandPalette(show = null) {
    const palette = qs('#nobash-files-command-palette'); if (!palette) return;
    const open = show === null ? palette.hidden : show;
    palette.hidden = !open;
    if (open) { renderCommandPalette(''); const input=qs('#nobash-files-command-input'); if(input){input.value='';input.focus();} }
    else editorFocus();
  }

  function runCommand(id) {
    const cmd = editorCommands.find(c => c.id === id); if (!cmd) return;
    toggleCommandPalette(false); cmd.run();
  }

  function revertUnsaved() {
    const tab = editorTabs.get(activeEditorPath); if (!tab) return;
    tab.content = tab.original; tab.dirty = false; tab.recovered = false; clearDraft(tab.file.path);
    editorSetValue(tab.original); renderEditorTabs(); renderFileInfo(tab.file); setValidation('neutral','Reverted','Unsaved editor changes were discarded.'); notice('Unsaved changes reverted.');
  }

  async function restoreEditorSession() {
    setSourceLoading(true, 'Restoring editor…');
    let session = null;
    try { session = JSON.parse(localStorage.getItem(EDITOR_SESSION_KEY) || 'null'); } catch (_) {}
    const queryFile = new URLSearchParams(location.search).get('file');
    const paths = [...new Set([...(Array.isArray(session?.tabs) ? session.tabs : []), ...(queryFile ? [queryFile] : [])])].slice(0,8);
    const preferredCandidate = queryFile || (session?.active && paths.includes(session.active) ? session.active : paths[0]);
    for (const path of paths) {
      try { await loadEditorTab(path, false, path === preferredCandidate); } catch (_) {}
    }
    const preferred = queryFile || (session?.active && editorTabs.has(session.active) ? session.active : [...editorTabs.keys()][0]);
    if (preferred && editorTabs.has(preferred)) await switchEditorTab(preferred);
    setSourceLoading(false);
  }

  async function initializeEditorWorkspace() {
    try {
      await restoreEditorSession();
      if (activeEditorPath) {
        const folder = parentPath(activeEditorPath);
        if (clientPathAllowed(folder)) await loadDir(folder).catch(() => {});
        return;
      }
      const candidates = [NobashFiles.workspaceRoot || '', NobashFiles.editorStartPath || '', NobashFiles.defaultWorkspace || '', 'wp-content/themes', accessHome || ''];
      const start = candidates.find(path => path !== '' && clientPathAllowed(String(path))) || (clientPathAllowed(accessHome || '') ? (accessHome || '') : '');
      const data = await loadDir(start);
      const editable = (data.items || []).filter(item => item.type === 'file' && item.editable);
      if (editable.length) {
        const priority = ['functions.php','style.css','theme.json','index.php'];
        editable.sort((a,b) => {
          const ai = priority.indexOf(String(a.name || '').toLowerCase());
          const bi = priority.indexOf(String(b.name || '').toLowerCase());
          const ar = ai < 0 ? 999 : ai, br = bi < 0 ? 999 : bi;
          return ar - br || String(a.name || '').localeCompare(String(b.name || ''));
        });
        await openEditorFile(editable[0].path);
      } else {
        resetEditorEmpty();
        const state = qs('#nobash-files-editor-state');
        if (state) state.textContent = 'Choose a source file from the left';
      }
    } catch (e) {
      setSourceLoading(false);
      setValidation('bad', 'Unable to initialize editor', e.message || 'Editor startup failed.');
      notice(e.message || 'Unable to initialize the code editor.', 'error');
    }
  }

  async function createManualBackup() {
    if (!currentFile) return notice('Open a file first.', 'error');
    if (!hasPerm('versions')) return notice('Version history is not allowed by your access profile.', 'error');
    try {
      await api('snapshot', {method:'POST', body:JSON.stringify({path:currentFile.path})});
      await loadVersions(currentFile.path);
      notice('Manual safety backup created.');
    } catch (e) { notice(e.message, 'error'); }
  }

  async function duplicateCurrentEditorFile() {
    if (!currentFile) return notice('Open a file first.', 'error');
    if (!hasPerm('copy')) return notice('Duplicating files is not allowed by your access profile.', 'error');
    try {
      const result = await performOperation({action:'duplicate', path:currentFile.path});
      notice(`Created duplicate “${result.name}”.`);
      const folder = parentPath(currentFile.path);
      if (clientPathAllowed(folder)) await loadDir(folder).catch(() => {});
    } catch (e) { notice(e.message, 'error'); }
  }

  function toggleEditorMore(open = null) {
    const menu = qs('#nobash-files-editor-more-menu'), btn = qs('#nobash-files-editor-more'); if (!menu || !btn) return;
    const show = open === null ? menu.hidden : open;
    menu.hidden = !show; btn.setAttribute('aria-expanded', show ? 'true' : 'false');
  }

  function initEditorEvents() {
    const ed = qs('#nobash-files-editor'); if (!ed) return;
    // Keep a fully functional textarea available first. The enhanced WordPress editor is created
    // only after a file has been loaded, so its first paint always starts with real source code.
    bindTextareaEditorFallback();
    qs('#nobash-files-save')?.addEventListener('click', () => saveCurrent(false));
    qs('#nobash-files-validate')?.addEventListener('click', validateCurrent);
    qs('#nobash-files-validate-again')?.addEventListener('click', validateCurrent);
    qs('#nobash-files-find')?.addEventListener('click', () => toggleFind());
    qs('#nobash-files-replace')?.addEventListener('click', openReplacePanel);
    qs('#nobash-files-find-close')?.addEventListener('click', () => toggleFind(false));
    qs('#nobash-files-find-next')?.addEventListener('click', findNext);
    qs('#nobash-files-replace-one')?.addEventListener('click', replaceOne);
    qs('#nobash-files-replace-all')?.addEventListener('click', replaceAll);
    qs('#nobash-files-find-text')?.addEventListener('input', () => { editorFindCursor=0; });
    qs('#nobash-files-find-text')?.addEventListener('keydown', e => { if(e.key==='Enter'){e.preventDefault();findNext();} if(e.key==='Escape')toggleFind(false); });
    qs('#nobash-files-command')?.addEventListener('click', () => toggleCommandPalette());
    qs('#nobash-files-command-input')?.addEventListener('input', e => renderCommandPalette(e.target.value));
    qs('#nobash-files-command-input')?.addEventListener('keydown', e => { if(e.key==='Escape')toggleCommandPalette(false); if(e.key==='Enter'){e.preventDefault();qs('#nobash-files-command-results [data-command]')?.click();} });
    qs('#nobash-files-command-palette')?.addEventListener('click', e => { if(e.target.id==='nobash-files-command-palette')toggleCommandPalette(false); });
    qs('#nobash-files-compare')?.addEventListener('click', showDiff);
    qs('#nobash-files-close-diff')?.addEventListener('click', () => { qs('#nobash-files-diff-card').hidden = true; });
    qs('#nobash-files-editor-undo')?.addEventListener('click', () => { toggleEditorMore(false); revertUnsaved(); });
    qs('#nobash-files-editor-reload')?.addEventListener('click', () => { toggleEditorMore(false); reloadEditorFromDisk(false); });
    qs('#nobash-files-editor-close-current')?.addEventListener('click', () => { toggleEditorMore(false); if (activeEditorPath) closeEditorTab(activeEditorPath); });
    qs('#nobash-files-editor-more')?.addEventListener('click', e => { e.stopPropagation(); toggleEditorMore(); });
    qs('#nobash-files-editor-tree-add')?.addEventListener('click', () => qs('#nobash-files-new-folder')?.click());
    qs('#nobash-files-editor-backup')?.addEventListener('click', createManualBackup);
    qs('#nobash-files-editor-restore-latest')?.addEventListener('click', e => { const id=Number(e.currentTarget.dataset.versionId||0); if(id) restoreVersion(id); });
    qs('#nobash-files-editor-duplicate')?.addEventListener('click', duplicateCurrentEditorFile);
    qs('#nobash-files-fullscreen')?.addEventListener('click', () => { qs('.nobash-files-editor-panel')?.classList.toggle('is-fullscreen'); setTimeout(() => { codeMirror?.refresh(); updateEditorMinimapViewport(); }, 50); });
    document.addEventListener('click', e => { if (!e.target.closest('.nobash-files-editor-more-wrap')) toggleEditorMore(false); });
    document.addEventListener('keydown', e => {
      if (view !== 'editor') return;
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') { e.preventDefault(); toggleFind(true); }
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 'p') { e.preventDefault(); toggleCommandPalette(true); }
      if (e.key === 'Escape' && qs('#nobash-files-command-palette') && !qs('#nobash-files-command-palette').hidden) toggleCommandPalette(false);
    }, true);
    window.addEventListener('beforeunload', e => {
      syncActiveTab();
      const active = editorTabs.get(activeEditorPath);
      if (active?.dirty) persistDraft(active);
      if ([...editorTabs.values()].some(tab => tab.dirty)) { e.preventDefault(); e.returnValue = ''; }
    });
    initializeEditorWorkspace();
  }


  // Move existing nodes so controls, values and event handlers stay intact.
  function simplifyPages(root = document) {
    const find = selector => root.querySelector(selector);
    const fold = (panel, title, open = false) => {
      if (!panel || panel.parentElement?.classList.contains('nobash-files-disclosure')) return;
      const details = document.createElement('details');
      details.className = 'nobash-files-disclosure';
      details.open = open;
      const summary = document.createElement('summary');
      summary.textContent = title;
      panel.before(details);
      details.append(summary, panel);
    };
    if (view === 'manager') {
      const bar = find('#nobash-files-selection-bar');
      if (bar && !bar.querySelector('.nobash-files-bulk-more')) {
        const more = document.createElement('details');
        more.className = 'nobash-files-bulk-more';
        const summary = document.createElement('summary');
        summary.textContent = 'More actions';
        const actions = document.createElement('div');
        ['archive','extract','copy','move','duplicate','rename'].forEach(action => {
          const button = bar.querySelector(`[data-bulk="${action}"]`);
          if (button) actions.append(button);
        });
        more.append(summary, actions);
        bar.insertBefore(more, bar.querySelector('[data-bulk="trash"]'));
        actions.addEventListener('click', event => { if (event.target.closest('button')) more.open = false; });
      }
    }
    if (view === 'editor') {
      fold(find('.nobash-files-editor-inspector'), 'File details, backups & recovery');
      const menu = find('#nobash-files-editor-more-menu');
      if (menu) ['validate','replace','compare','command'].forEach(name => {
        const button = find('#nobash-files-' + name);
        if (button && button.parentElement !== menu) {
          button.classList.remove('button', 'nobash-files-btn');
          menu.append(button);
        }
      });
    }
    if (view === 'settings') {
      const mainCards = root.querySelectorAll('.nobash-files-settings-main-v2 > section');
      fold(mainCards[2], 'Advanced upload settings');
      const sideCards = root.querySelectorAll('.nobash-files-settings-side-v2 > section');
      fold(sideCards[0], 'Server & workspace details');
      fold(sideCards[2], 'Privacy & plugin removal');
      fold(find('.nobash-files-settings-page-actions'), 'Export or reset settings');
    }
    if (view === 'versions') {
      fold(find('.nobash-files-history-metrics'), 'Backup & storage summary');
      fold(find('.nobash-files-history-side'), 'Backup details & undo', new URLSearchParams(location.search).has('nobash_files_version'));
    }
    if (view === 'activity') {
      fold(find('.nobash-files-activity-metrics-v2'), 'Activity counts');
      fold(find('.nobash-files-activity-side-v2'), 'About activity & privacy');
    }
    if (view === 'trash') {
      fold(find('.nobash-files-trash-stats-v2'), 'Trash summary');
      fold(find('.nobash-files-trash-side-v2'), 'How recovery works');
    }
    if (view === 'integrity') {
      fold(find('.nobash-files-integrity-side-v2'), 'Selected file details & repair tools', new URLSearchParams(location.search).has('nobash_files_integrity_file'));
      fold(find('.nobash-files-integrity-side'), 'Scan settings & history');
    }
    if (view === 'monitoring') {
      fold(find('.nobash-files-monitor-side'), 'Recent updates & scan context');
      fold(find('.nobash-files-monitor-rules'), 'Choose which alerts to send');
    }
    if (view === 'security') {
      const cards = root.querySelectorAll('.nobash-files-security-main > article');
      fold(cards[1], 'Repair WordPress files', Boolean(find('[data-core-repair-path]')));
      fold(cards[2], 'Check a plugin or theme');
      fold(cards[3], 'Check file permissions', Boolean(find('[data-permission-repair]')));
      fold(find('.nobash-files-security-safety'), 'How safe repair works');
    }
    if (view === 'agency') {
      fold(find('.nobash-files-agency-audit'), 'Recent activity & export');
      fold(find('.nobash-files-agency-apply'), 'Apply a saved template', Boolean(find('#nobash-files-agency-apply-template option[value]:not([value=""])')));
      const denied = find('#nobash-files-agency-denied');
      fold(denied?.closest('label'), 'Blocked folders (optional)', Boolean(denied?.value));
      const protectedInput = find('#nobash-files-agency-protected');
      fold(protectedInput?.closest('label'), 'Protected WordPress areas', Boolean(protectedInput?.checked));
      const labels = {browse:'Browse folders',read:'Open & preview',download:'Download',upload:'Upload',create:'Create folders',edit:'Edit text & code',rename:'Rename',copy:'Copy',move:'Move',delete:'Delete files',archive:'Create archives',extract:'Extract archives',search:'Search',versions:'View backups',restore:'Restore backups',undo:'Undo changes',trash:'Use Trash',activity:'View activity'};
      root.querySelectorAll('[data-agency-perm]').forEach(input => {
        const text = input.parentElement.querySelector('span');
        if (text) text.textContent = labels[input.dataset.agencyPerm] || input.dataset.agencyPerm;
      });
    }
    if (view === 'multisite') {
      fold(find('.nobash-files-network-metrics'), 'Network summary');
      fold(find('.nobash-files-network-shared'), 'Shared WordPress folders');
    }
    root.querySelectorAll('input[type="search"]').forEach(input => {
      if (!input.labels?.length && !input.hasAttribute('aria-label')) input.setAttribute('aria-label', input.placeholder || 'Search');
    });
    openLinkedPanel();
  }

  function openLinkedPanel() {
    if (!location.hash) return;
    let target;
    try { target = document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch (_) { return; }
    if (!target) return;
    for (let parent = target.parentElement; parent; parent = parent.parentElement) {
      if (parent.tagName === 'DETAILS') parent.open = true;
    }
  }

  async function renderAccessControl(host) {
    try {
      let data = await api('access');
      const permissionLabels = {
        browse:'Browse folders', read:'Open & preview files', download:'Download', upload:'Upload', create:'Create folders',
        edit:'Edit code/text', rename:'Rename', copy:'Copy & duplicate', move:'Move', delete:'Move to Trash / delete',
        archive:'Create archives', extract:'Extract archives', search:'Search', versions:'View versions', restore:'Restore versions',
        undo:'Undo operations', trash:'Use Trash', activity:'View activity'
      };
      const permissionGroups = [
        {title:'View & find', description:'Browse, open and download files.', keys:['browse','read','search','download']},
        {title:'Add & change', description:'Upload files and make changes.', keys:['upload','create','edit','rename','copy','move','archive','extract']},
        {title:'Delete & recover', description:'Manage deleted files, backups and activity.', keys:['delete','trash','versions','restore','undo','activity']}
      ];
      const selected = {type:'role', id:data.roles?.[0]?.id || ''};

      const policyFor = () => {
        const map = selected.type === 'role' ? data.rolePolicies : data.userPolicies;
        const stored = map?.[String(selected.id)];
        return stored ? JSON.parse(JSON.stringify(stored)) : JSON.parse(JSON.stringify(data.defaults || {}));
      };

      const subjectOptions = () => {
        const items = selected.type === 'role' ? (data.roles || []) : (data.users || []);
        return items.map(item => `<option value="${esc(item.id)}" ${String(item.id) === String(selected.id) ? 'selected' : ''}>${esc(item.name)}${selected.type === 'user' ? ` — ${esc(item.login || '')}` : ''}</option>`).join('');
      };

      const renderEditor = () => {
        const policy = policyFor();
        const perms = policy.permissions || {};
        const directExists = Boolean((selected.type === 'role' ? data.rolePolicies : data.userPolicies)?.[String(selected.id)]);
        host.innerHTML = `
          <div class="nobash-files-access-layout">
            <section class="nobash-files-access-editor">
              <div class="nobash-files-access-editor-head"><div><h3>Who needs access?</h3><p>Choose a team role or one person, then set what they can do.</p></div><span class="nobash-files-badge ${directExists ? 'ready' : ''}">${directExists ? 'Saved profile' : 'Not saved yet'}</span></div>
              <div class="nobash-files-access-subject-row">
                <label><span>Set access for</span><select id="nobash-files-access-type"><option value="role" ${selected.type === 'role' ? 'selected' : ''}>A WordPress role</option><option value="user" ${selected.type === 'user' ? 'selected' : ''}>One person</option></select></label>
                <label><span>${selected.type === 'role' ? 'Role' : 'Person'}</span><select id="nobash-files-access-subject">${subjectOptions()}</select></label>
                <label class="nobash-files-access-enabled"><span>File access</span><span class="nobash-files-toggle-line"><input id="nobash-files-access-enabled" type="checkbox" ${policy.enabled ? 'checked' : ''}><strong>Allow access</strong></span></label>
              </div>
              <p class="nobash-files-access-note">${selected.type === 'user' ? 'A saved personal profile replaces this person’s role settings.' : 'These settings apply to people with this role. Personal profiles take priority.'} Administrators always keep full access.</p>
              <div class="nobash-files-access-step"><h3>Which folders?</h3><p>They can only work inside the folders you list.</p></div>
              <div class="nobash-files-access-path-grid">
                <label><span>Allowed folders</span><textarea id="nobash-files-access-roots" rows="3" aria-describedby="nobash-files-access-roots-help" placeholder="wp-content/uploads">${esc((policy.roots || []).join('\n'))}</textarea><small id="nobash-files-access-roots-help">One folder per line, relative to your WordPress folder. Example: <code>wp-content/uploads</code></small></label>
              </div>
              <details class="nobash-files-access-advanced" ${(policy.allow_protected || policy.denied_paths?.length) ? 'open' : ''}>
                <summary>Folder restrictions & protected areas${policy.allow_protected ? ' · Protected access allowed' : ''}</summary>
                <div class="nobash-files-access-path-grid"><label><span>Blocked folders or files <small>(optional)</small></span><textarea id="nobash-files-access-denied" rows="3" placeholder="wp-content/uploads/private">${esc((policy.denied_paths || []).join('\n'))}</textarea><small>These paths stay blocked, even inside an allowed folder.</small></label></div>
                <div class="nobash-files-access-protected"><label><input id="nobash-files-access-protected" type="checkbox" ${policy.allow_protected ? 'checked' : ''}><span><strong>Allow changes in protected WordPress areas</strong><small>Includes WordPress core, configuration, plugins and themes. Keep this off for content teams. Allowed folders and actions still apply.</small></span></label></div>
              </details>
              <div class="nobash-files-access-step"><h3>What can they do?</h3><p>Check the actions you want to allow. Browsing is required when access is on.</p></div>
              <div class="nobash-files-access-permission-groups">${permissionGroups.map(group => {
                const keys = group.keys.filter(key => (data.permissions || []).includes(key));
                if (!keys.length) return '';
                return `<fieldset class="nobash-files-access-permission-group"><legend>${esc(group.title)}</legend><p>${esc(group.description)}</p><div class="nobash-files-permission-grid">${keys.map(key => `<label><input type="checkbox" data-nobash-files-permission="${esc(key)}" ${perms[key] || key === 'browse' ? 'checked' : ''} ${key === 'browse' ? 'disabled' : ''}><span><strong>${esc(permissionLabels[key] || key)}</strong>${key === 'browse' ? '<small>Required</small>' : ''}</span></label>`).join('')}</div></fieldset>`;
              }).join('')}</div>
              <div class="nobash-files-access-actions"><button class="button button-primary" id="nobash-files-access-save" type="button">Save access</button>${directExists ? '<button class="button nobash-files-danger" id="nobash-files-access-delete" type="button">Remove profile</button>' : ''}<span class="nobash-files-muted">Changes take effect after you save.</span></div>
            </section>
          </div>
          <section class="nobash-files-access-existing"><div class="nobash-files-panel-head"><strong>Configured profiles</strong><span class="nobash-files-muted">Administrators always keep full access</span></div><div class="nobash-files-access-profile-list" id="nobash-files-access-profile-list"></div></section>`;

        const renderList = () => {
          const list = qs('#nobash-files-access-profile-list'); if (!list) return;
          const roleName = id => data.roles?.find(r => String(r.id) === String(id))?.name || id;
          const userName = id => { const u=data.users?.find(r=>String(r.id)===String(id)); return u ? `${u.name} (${u.login})` : `User #${id}`; };
          const rows = [];
          Object.entries(data.rolePolicies || {}).forEach(([id,p]) => rows.push({type:'role',id,name:roleName(id),p}));
          Object.entries(data.userPolicies || {}).forEach(([id,p]) => rows.push({type:'user',id,name:userName(id),p}));
          list.innerHTML = rows.length ? rows.map(row => `<button type="button" class="nobash-files-access-profile-row" data-profile-type="${row.type}" data-profile-id="${esc(row.id)}"><span class="dashicons ${row.type === 'role' ? 'dashicons-groups' : 'dashicons-admin-users'}"></span><div><strong>${esc(row.name)}</strong><p>${row.type === 'role' ? 'Role policy' : 'Direct user override'} · ${(row.p.roots || []).map(r=>`/${esc(r)}`).join(', ') || 'No roots'}</p></div><span class="nobash-files-badge ${row.p.enabled ? 'ready' : ''}">${row.p.enabled ? 'Enabled' : 'Disabled'}</span><span class="dashicons dashicons-arrow-right-alt2"></span></button>`).join('') : '<div class="nobash-files-empty"><span class="dashicons dashicons-lock"></span><strong>No delegated access yet</strong><p>Administrators are the only users who can open the file manager.</p></div>';
          qsa('[data-profile-id]', list).forEach(btn => btn.addEventListener('click', () => {
            selected.type = btn.dataset.profileType;
            selected.id = btn.dataset.profileId;
            renderEditor();
          }));
        };
        renderList();

        qs('#nobash-files-access-type')?.addEventListener('change', e => {
          selected.type = e.target.value;
          const items = selected.type === 'role' ? (data.roles || []) : (data.users || []);
          selected.id = items[0]?.id || '';
          renderEditor();
        });
        qs('#nobash-files-access-subject')?.addEventListener('change', e => { selected.id = e.target.value; renderEditor(); });
        qs('#nobash-files-access-save')?.addEventListener('click', async () => {
          if (!selected.id) return notice('Choose a role or user first.', 'error');
          const permissions = {};
          qsa('[data-nobash-files-permission]').forEach(input => { permissions[input.dataset.nobashFilesPermission] = input.checked || input.dataset.nobashFilesPermission === 'browse'; });
          const body = {
            type:selected.type,
            id:String(selected.id),
            policy:{
              enabled:Boolean(qs('#nobash-files-access-enabled')?.checked),
              roots:(qs('#nobash-files-access-roots')?.value || '').split(/\r?\n|,/).map(v=>v.trim()).filter(Boolean),
              denied_paths:(qs('#nobash-files-access-denied')?.value || '').split(/\r?\n|,/).map(v=>v.trim()).filter(Boolean),
              allow_protected:Boolean(qs('#nobash-files-access-protected')?.checked),
              permissions
            }
          };
          try {
            await api('access', {method:'POST', body:JSON.stringify(body)});
            notice('Access profile saved.');
            data = await api('access');
            renderEditor();
          } catch (e) { notice(e.message, 'error'); }
        });
        qs('#nobash-files-access-delete')?.addEventListener('click', async () => {
          const yes = await showModal({title:'Remove access profile?', message:selected.type === 'user' ? 'This user will fall back to their role policy. If no role policy is enabled, access will be removed.' : 'Users relying on this role may lose File Manager access.', confirmText:'Remove Profile', danger:true, input:false});
          if (!yes.confirmed) return;
          try {
            await api('access', {method:'DELETE', body:JSON.stringify({type:selected.type,id:String(selected.id)})});
            notice('Access profile removed.');
            data = await api('access');
            renderEditor();
          } catch (e) { notice(e.message, 'error'); }
        });
      };
      renderEditor();
    } catch (e) { notice(e.message, 'error'); }
  }


  async function renderIntegrity(host) {
    const stateLabel = status => ({clean:'Verified',warning:'Review changes',critical:'Action recommended',incomplete:'Incomplete scan',needs_baseline:'Setup needed'}[status] || 'Not scanned');
    const stateIcon = status => ({clean:'dashicons-yes-alt',warning:'dashicons-warning',critical:'dashicons-shield',incomplete:'dashicons-clock',needs_baseline:'dashicons-admin-tools'}[status] || 'dashicons-minus');
    const count = value => Array.isArray(value) ? value.length : Number(value || 0);
    const renderPathItems = (items, empty='Nothing detected') => {
      if (!items?.length) return `<div class="nobash-files-integrity-empty"><span class="dashicons dashicons-yes"></span>${esc(empty)}</div>`;
      return `<div class="nobash-files-integrity-paths">${items.map(item => {
        const obj = typeof item === 'string' ? {path:item} : item;
        const meta = [obj.component, obj.type].filter(Boolean).join(' · ');
        return `<div class="nobash-files-integrity-path"><span class="dashicons dashicons-media-code"></span><div><strong>${esc(obj.path || '')}</strong>${meta ? `<small>${esc(meta)}</small>` : ''}</div></div>`;
      }).join('')}</div>`;
    };
    const load = async () => {
      try {
        const data = await api('integrity');
        const scan = data.last_scan || {};
        const summary = scan.summary || {status:'needs_baseline',message:'Run your first integrity scan to verify WordPress core and review file changes.',issues:0};
        const core = scan.core || {};
        const content = scan.content || {};
        const suspicious = scan.suspicious || [];
        const baseline = data.baseline || {exists:false};
        const settings = data.settings || {};
        const changes = count(content.modified)+count(content.added)+count(content.removed);
        const coreIssues = count(core.modified)+count(core.missing)+count(core.unexpected);
        host.innerHTML = `
          <section class="nobash-files-integrity-hero is-${esc(summary.status)}">
            <div class="nobash-files-integrity-hero-icon"><span class="dashicons ${stateIcon(summary.status)}"></span></div>
            <div class="nobash-files-integrity-hero-main"><span class="nobash-files-eyebrow">WordPress integrity</span><h3>${esc(stateLabel(summary.status))}</h3><p>${esc(summary.message || '')}</p>${scan.scanned_at ? `<small>Last scan: ${esc(scan.scanned_at)} · ${Number(scan.files_seen || 0).toLocaleString()} files checked · ${Math.max(0,Number(scan.duration_ms||0)/1000).toFixed(1)}s</small>` : '<small>No integrity scan has been run yet.</small>'}</div>
            <div class="nobash-files-integrity-hero-actions"><button class="button button-primary" id="nobash-files-integrity-scan" type="button"><span class="dashicons dashicons-update"></span> Check site files</button><button class="button" id="nobash-files-integrity-baseline" type="button">${baseline.exists ? 'Refresh Trusted Baseline' : 'Create Trusted Baseline'}</button></div>
          </section>
          <section class="nobash-files-integrity-metrics">
            <article><span class="nobash-files-integrity-metric-icon core dashicons dashicons-wordpress"></span><div><strong>WordPress Core</strong><b>${core.available ? (coreIssues ? `${coreIssues} issue${coreIssues===1?'':'s'}` : (scan.scanned_at ? 'Verified' : 'Not scanned')) : 'Checksums unavailable'}</b><small>${core.verified ? `${Number(core.verified).toLocaleString()} official files matched` : 'Uses official WordPress checksums'}</small></div></article>
            <article><span class="nobash-files-integrity-metric-icon changes dashicons dashicons-randomize"></span><div><strong>Plugins & Themes</strong><b>${baseline.exists ? `${changes} change${changes===1?'':'s'}` : 'No trusted baseline'}</b><small>${baseline.exists ? `${Number(content.unchanged||0).toLocaleString()} files unchanged` : 'Baseline is always created manually'}</small></div></article>
            <article><span class="nobash-files-integrity-metric-icon suspicious dashicons dashicons-shield"></span><div><strong>Suspicious Review</strong><b>${suspicious.length ? `${suspicious.length} item${suspicious.length===1?'':'s'}` : (scan.scanned_at ? 'Nothing flagged' : 'Not scanned')}</b><small>Heuristics require manual review</small></div></article>
            <article><span class="nobash-files-integrity-metric-icon ${scan.complete===false?'partial':'complete'} dashicons ${scan.complete===false?'dashicons-clock':'dashicons-yes-alt'}"></span><div><strong>Scan Coverage</strong><b>${scan.scanned_at ? (scan.complete===false ? 'Incomplete' : 'Complete') : 'Not scanned'}</b><small>${scan.complete===false ? 'Safety budget reached — never reported as clean' : `${Number(settings.max_files||50000).toLocaleString()} file safety limit`}</small></div></article>
          </section>
          ${scan.warnings?.length ? `<section class="nobash-files-integrity-warning"><span class="dashicons dashicons-warning"></span><div><strong>Scan notes</strong>${scan.warnings.map(w=>`<p>${esc(w)}</p>`).join('')}</div></section>` : ''}
          <div class="nobash-files-integrity-layout">
            <section class="nobash-files-integrity-main">
              <article class="nobash-files-integrity-section">
                <div class="nobash-files-integrity-section-head"><div><span class="dashicons dashicons-wordpress"></span><div><h4>WordPress Core</h4><p>Compared against checksums from the official WordPress.org core checksum service.</p></div></div><span class="nobash-files-badge ${core.available && !coreIssues ? 'ready' : ''}">${core.available ? `${esc(core.version||'')} · ${esc(core.locale||'')}` : 'Unavailable'}</span></div>
                ${!scan.scanned_at ? '<div class="nobash-files-integrity-empty">Run a scan to verify WordPress core.</div>' : !core.available ? `<div class="nobash-files-integrity-empty is-warning"><span class="dashicons dashicons-warning"></span>${esc(core.message || 'Official checksums could not be retrieved.')}</div>` : `
                  <div class="nobash-files-integrity-group"><div class="nobash-files-integrity-group-title"><strong>Modified core files</strong><span>${count(core.modified)}</span></div>${renderPathItems(core.modified,'No modified core files')}</div>
                  <div class="nobash-files-integrity-group"><div class="nobash-files-integrity-group-title"><strong>Missing core files</strong><span>${count(core.missing)}</span></div>${renderPathItems(core.missing,'No missing core files')}</div>
                  <div class="nobash-files-integrity-group"><div class="nobash-files-integrity-group-title"><strong>Unexpected files in core directories</strong><span>${count(core.unexpected)}</span></div>${renderPathItems(core.unexpected,'No unexpected files in wp-admin or wp-includes')}</div>`}
              </article>
              <article class="nobash-files-integrity-section">
                <div class="nobash-files-integrity-section-head"><div><span class="dashicons dashicons-admin-plugins"></span><div><h4>Plugin & Theme Changes</h4><p>Current files are compared with your explicit trusted baseline. Updates will correctly appear as changes until you refresh the baseline.</p></div></div><span class="nobash-files-badge ${baseline.exists ? 'ready' : ''}">${baseline.exists ? `${Number(baseline.file_count||0).toLocaleString()} baseline files` : 'Baseline required'}</span></div>
                ${!baseline.exists ? '<div class="nobash-files-integrity-baseline-help"><span class="dashicons dashicons-info-outline"></span><div><strong>Create a baseline only when you trust the current site.</strong><p>We never silently mark the current filesystem as clean. Run the core scan and review the site first, then create a trusted plugin/theme baseline.</p></div></div>' : `
                  <div class="nobash-files-integrity-group"><div class="nobash-files-integrity-group-title"><strong>Modified</strong><span>${count(content.modified)}</span></div>${renderPathItems(content.modified,'No modified plugin/theme files')}</div>
                  <div class="nobash-files-integrity-group"><div class="nobash-files-integrity-group-title"><strong>Added</strong><span>${count(content.added)}</span></div>${renderPathItems(content.added,'No new plugin/theme files')}</div>
                  <div class="nobash-files-integrity-group"><div class="nobash-files-integrity-group-title"><strong>Removed</strong><span>${count(content.removed)}</span></div>${renderPathItems(content.removed,'No removed plugin/theme files')}</div>`}
              </article>
              <article class="nobash-files-integrity-section">
                <div class="nobash-files-integrity-section-head"><div><span class="dashicons dashicons-shield"></span><div><h4>Files to review</h4><p>Focused safety heuristics for executable files in uploads and high-risk patterns in changed code. A flag is not a malware diagnosis.</p></div></div><span class="nobash-files-badge ${!suspicious.length && scan.scanned_at ? 'ready' : ''}">${suspicious.length} flagged</span></div>
                ${suspicious.length ? `<div class="nobash-files-integrity-findings">${suspicious.map(item=>`<div class="nobash-files-integrity-finding severity-${esc(item.severity||'medium')}"><span class="dashicons dashicons-warning"></span><div><strong>${esc(item.path||'')}</strong><p>${esc(item.reason||'Review this file.')}</p><small>Rule: ${esc(item.rule||'heuristic')} · ${esc(item.severity||'review')} priority</small></div></div>`).join('')}</div>` : `<div class="nobash-files-integrity-empty"><span class="dashicons dashicons-yes-alt"></span>${scan.scanned_at ? 'No suspicious files were flagged by the current heuristic rules.' : 'Run a scan to check for suspicious file patterns.'}</div>`}
              </article>
            </section>
            <aside class="nobash-files-integrity-side">
              <section class="nobash-files-integrity-sidebox"><h4>Trusted Baseline</h4>${baseline.exists ? `<div class="nobash-files-integrity-baseline-state"><span class="dashicons dashicons-yes-alt"></span><div><strong>Baseline active</strong><p>${Number(baseline.file_count||0).toLocaleString()} plugin/theme files<br>Created ${esc(baseline.created_at||'')}</p></div></div><button class="button" id="nobash-files-integrity-delete-baseline" type="button">Remove Baseline</button>` : '<p>No baseline exists. Create one only after you have verified that the current plugins and themes are trusted.</p>'}</section>
              <section class="nobash-files-integrity-sidebox"><h4>Scan Safety Budget</h4><label><span>Maximum files</span><select id="nobash-files-integrity-max-files">${[10000,25000,50000,75000,100000].map(v=>`<option value="${v}" ${Number(settings.max_files)===v?'selected':''}>${v.toLocaleString()}</option>`).join('')}</select></label><label><span>Maximum request time</span><select id="nobash-files-integrity-max-seconds">${[10,18,25,35,45].map(v=>`<option value="${v}" ${Math.round(Number(settings.max_seconds))===v?'selected':''}>${v} seconds</option>`).join('')}</select></label><small>Reaching either limit makes the scan explicitly incomplete. It never turns a partial scan into a green result.</small></section>
              <section class="nobash-files-integrity-sidebox"><h4>Recent Scans</h4><div class="nobash-files-integrity-history">${data.history?.length ? data.history.slice(0,8).map(row=>`<div><span class="nobash-files-history-dot is-${esc(row.status||'')}"></span><div><strong>${esc(stateLabel(row.status))}</strong><small>${esc(row.scanned_at||'')} · ${Number(row.issues||0)} issues</small></div></div>`).join('') : '<p class="nobash-files-muted">No scan history yet.</p>'}</div></section>
            </aside>
          </div>`;

      simplifyPages(host);
        qs('#nobash-files-integrity-scan')?.addEventListener('click', async e => {
          const btn=e.currentTarget; btn.disabled=true; const old=btn.innerHTML; btn.innerHTML='<span class="dashicons dashicons-update nobash-files-spin"></span> Scanning…';
          try { const r=await api('integrity',{method:'POST',body:JSON.stringify({action:'scan'})}); notice(r.scan?.complete===false?'Integrity scan finished, but the safety budget was reached. Results are incomplete.':'Integrity scan complete.', r.scan?.complete===false?'warning':'success'); await load(); } catch(err){ notice(err.message,'error'); btn.disabled=false; btn.innerHTML=old; }
        });
        qs('#nobash-files-integrity-baseline')?.addEventListener('click', async () => {
          const yes=await showModal({title:baseline.exists?'Refresh trusted baseline?':'Create trusted baseline?',message:'Only continue if you trust the current installed plugin and theme files. Future scans will report changes against this exact state.',confirmText:baseline.exists?'Refresh Baseline':'Create Baseline',danger:false,input:false});
          if(!yes.confirmed)return;
          try { await api('integrity',{method:'POST',body:JSON.stringify({action:'baseline'})}); notice('Trusted plugin/theme baseline saved.'); await load(); } catch(err){ notice(err.message,'error'); }
        });
        qs('#nobash-files-integrity-delete-baseline')?.addEventListener('click', async () => {
          const yes=await showModal({title:'Remove trusted baseline?',message:'Plugin and theme change detection will be unavailable until a new trusted baseline is created.',confirmText:'Remove Baseline',danger:true,input:false});
          if(!yes.confirmed)return;
          try { await api('integrity',{method:'DELETE'}); notice('Trusted baseline removed.'); await load(); } catch(err){ notice(err.message,'error'); }
        });
        const saveBudget = async () => {
          try { await api('integrity',{method:'POST',body:JSON.stringify({action:'settings',settings:{max_files:Number(qs('#nobash-files-integrity-max-files')?.value||50000),max_seconds:Number(qs('#nobash-files-integrity-max-seconds')?.value||18)}})}); notice('Integrity scan safety budget updated.'); } catch(err){ notice(err.message,'error'); }
        };
        qs('#nobash-files-integrity-max-files')?.addEventListener('change',saveBudget);
        qs('#nobash-files-integrity-max-seconds')?.addEventListener('change',saveBudget);
      } catch (e) { notice(e.message,'error'); host.innerHTML='<div class="nobash-files-integrity-empty is-warning">Integrity data could not be loaded.</div>'; }
    };
    await load();
  }


  async function renderMonitoring(host) {
    const severityLabel = value => ({critical:'Action recommended',warning:'Review changes',incomplete:'Scan incomplete',info:'Update review'}[value] || 'Review');
    const formatNext = ts => Number(ts || 0) ? new Date(Number(ts) * 1000).toLocaleString() : 'Not scheduled';
    const render = async () => {
      try {
        const data = await api('monitoring');
        const settings = data.settings || {};
        const state = data.state || {};
        const alerts = data.alerts || [];
        const updates = data.updates || [];
        const awareness = data.awareness || {};
        const unread = alerts.filter(a => !a.read).length;
        host.innerHTML = `
          <section class="nobash-files-monitor-hero">
            <div class="nobash-files-monitor-hero-icon"><span class="dashicons dashicons-shield-alt"></span></div>
            <div><span class="nobash-files-eyebrow">Automatic protection</span><h3>${settings.enabled ? 'Automatic checks are on' : 'Automatic checks are off'}</h3><p>${settings.enabled ? `Your site files will be checked ${settings.frequency === 'twicedaily' ? 'twice daily' : settings.frequency === 'weekly' ? 'weekly' : 'daily'} when WordPress runs scheduled tasks.` : 'Turn on automatic checks below, or check your site manually at any time.'}</p><small>Next run: ${esc(formatNext(state.next_run))}${state.last_run ? ` · Last run: ${esc(state.last_run)} (${esc(state.last_status || 'unknown')})` : ''}</small></div>
            <button class="button button-primary" id="nobash-files-monitor-scan-now" type="button"><span class="dashicons dashicons-update"></span> Scan Now</button>
          </section>
          ${state.last_error ? `<section class="nobash-files-integrity-warning"><span class="dashicons dashicons-warning"></span><div><strong>Last scheduled scan failed</strong><p>${esc(state.last_error)}</p></div></section>` : ''}
          <div class="nobash-files-monitor-grid">
            <section class="nobash-files-monitor-main">
              <article class="nobash-files-monitor-card">
                <div class="nobash-files-panel-head"><div><strong>When to check</strong><p>Checks run when your site receives visits, so the time may vary.</p></div><span class="nobash-files-badge ${settings.enabled ? 'ready' : ''}">${settings.enabled ? 'Enabled' : 'Disabled'}</span></div>
                <label class="nobash-files-setting compact"><div><strong>Check site files automatically</strong><p>Check for changes to WordPress, plugin and theme files.</p></div><input id="nobash-files-monitor-enabled" type="checkbox" ${settings.enabled ? 'checked' : ''}></label>
                <label class="nobash-files-monitor-field"><span>Frequency</span><select id="nobash-files-monitor-frequency"><option value="twicedaily" ${settings.frequency === 'twicedaily' ? 'selected' : ''}>Twice daily</option><option value="daily" ${settings.frequency === 'daily' ? 'selected' : ''}>Daily</option><option value="weekly" ${settings.frequency === 'weekly' ? 'selected' : ''}>Weekly</option></select></label>
              </article>
              <article class="nobash-files-monitor-card">
                <div class="nobash-files-panel-head"><div><strong>Email alerts</strong><p>Choose where to send alerts. Unchanged issues will not send repeated emails too often.</p></div></div>
                <label class="nobash-files-setting compact"><div><strong>Email alerts</strong><p>Send alerts about file changes to this address.</p></div><input id="nobash-files-monitor-email-enabled" type="checkbox" ${settings.email_enabled ? 'checked' : ''}></label>
                <label class="nobash-files-monitor-field"><span>Email address</span><input id="nobash-files-monitor-email" type="email" value="${esc(settings.email_to || '')}" placeholder="admin@example.com"></label>
                <div class="nobash-files-monitor-rules">
                  <label><input id="nobash-files-notify-critical" type="checkbox" ${settings.notify_critical ? 'checked' : ''}><span><strong>Critical</strong><small>Core tampering, missing core files, or high-priority suspicious findings.</small></span></label>
                  <label><input id="nobash-files-notify-warning" type="checkbox" ${settings.notify_warning ? 'checked' : ''}><span><strong>Warnings</strong><small>Unexpected core files, plugin/theme changes, and review heuristics.</small></span></label>
                  <label><input id="nobash-files-notify-incomplete" type="checkbox" ${settings.notify_incomplete ? 'checked' : ''}><span><strong>Incomplete scans</strong><small>Safety budget reached; partial results are never treated as clean.</small></span></label>
                  <label><input id="nobash-files-notify-update-related" type="checkbox" ${settings.notify_update_related ? 'checked' : ''}><span><strong>Recent-update changes</strong><small>Changes that line up with a recently recorded successful plugin/theme update.</small></span></label>
                </div>
                <label class="nobash-files-monitor-field"><span>Wait before repeating an alert</span><select id="nobash-files-monitor-throttle">${[1,6,12,24,48,72].map(v=>`<option value="${v}" ${Number(settings.throttle_hours)===v?'selected':''}>${v} hour${v===1?'':'s'}</option>`).join('')}</select></label>
                <button class="button button-primary" id="nobash-files-monitor-save" type="button">Save settings</button>
              </article>
              <article class="nobash-files-monitor-card">
                <div class="nobash-files-panel-head"><div><strong>Recent alerts</strong><p>Review these alerts before making changes. Files are never repaired or deleted automatically.</p></div>${alerts.length ? `<button class="button button-small" id="nobash-files-monitor-mark-read" type="button">Mark all read</button>` : ''}</div>
                <div class="nobash-files-monitor-alerts">${alerts.length ? alerts.map(a=>`<div class="nobash-files-monitor-alert severity-${esc(a.severity || 'warning')} ${a.read?'is-read':''}"><span class="dashicons ${a.severity==='critical'?'dashicons-shield':a.severity==='info'?'dashicons-update':'dashicons-warning'}"></span><div><strong>${esc(a.title || severityLabel(a.severity))}</strong><p>${esc(a.message || '')}</p><small>${esc(a.created_at || '')} · ${esc(a.source || 'scan')} · ${Number(a.issues||0)} issue${Number(a.issues||0)===1?'':'s'}</small></div>${!a.read?`<button class="button button-small" data-monitor-dismiss="${esc(a.id)}" type="button">Dismiss</button>`:'<span class="nobash-files-badge">Read</span>'}</div>`).join('') : '<div class="nobash-files-integrity-empty"><span class="dashicons dashicons-yes-alt"></span>No monitoring alerts yet.</div>'}</div>
              </article>
            </section>
            <aside class="nobash-files-monitor-side">
              <section class="nobash-files-monitor-sidebox"><h4>Current alert state</h4><div class="nobash-files-monitor-big-number">${unread}</div><p>Unread alert${unread===1?'':'s'}</p><a class="button" href="admin.php?page=nobash-files-integrity">View scan results</a></section>
              <section class="nobash-files-monitor-sidebox"><h4>Changes after updates</h4><p>${esc(awareness.message || 'Run an integrity scan to correlate filesystem changes with recent updates.')}</p><div class="nobash-files-monitor-awareness"><div><strong>${Number(awareness.related_count||0)}</strong><span>update-related</span></div><div><strong>${Number(awareness.unexpected_count||0)}</strong><span>not correlated</span></div></div><small>A recent update is context only. Baselines are never auto-refreshed.</small></section>
              <section class="nobash-files-monitor-sidebox"><h4>Recent WordPress Updates</h4><div class="nobash-files-monitor-updates">${updates.length ? updates.slice(0,10).map(u=>`<div><span class="dashicons ${u.type==='plugin'?'dashicons-admin-plugins':u.type==='theme'?'dashicons-admin-appearance':'dashicons-wordpress'}"></span><div><strong>${esc(u.type === 'core' ? 'WordPress Core' : (u.components || []).join(', ') || u.type)}</strong><small>${esc(u.recorded_at || '')} · ${esc(u.type || '')} update</small></div></div>`).join('') : '<p class="nobash-files-muted">No recent update events recorded yet.</p>'}</div></section>
            </aside>
          </div>`;

      simplifyPages(host);
        qs('#nobash-files-monitor-save')?.addEventListener('click', async () => {
          const payload = {
            enabled:Boolean(qs('#nobash-files-monitor-enabled')?.checked),
            frequency:qs('#nobash-files-monitor-frequency')?.value || 'daily',
            email_enabled:Boolean(qs('#nobash-files-monitor-email-enabled')?.checked),
            email_to:qs('#nobash-files-monitor-email')?.value || '',
            notify_critical:Boolean(qs('#nobash-files-notify-critical')?.checked),
            notify_warning:Boolean(qs('#nobash-files-notify-warning')?.checked),
            notify_incomplete:Boolean(qs('#nobash-files-notify-incomplete')?.checked),
            notify_update_related:Boolean(qs('#nobash-files-notify-update-related')?.checked),
            throttle_hours:Number(qs('#nobash-files-monitor-throttle')?.value || 12)
          };
          const btn=qs('#nobash-files-monitor-save'); if(btn)btn.disabled=true;
          try { await api('monitoring',{method:'POST',body:JSON.stringify({action:'settings',settings:payload})}); notice('Monitoring settings saved.'); await render(); } catch(e){ notice(e.message,'error'); if(btn)btn.disabled=false; }
        });
        qs('#nobash-files-monitor-scan-now')?.addEventListener('click', async () => {
          const btn=qs('#nobash-files-monitor-scan-now'); const old=btn?.innerHTML; if(btn){btn.disabled=true;btn.innerHTML='<span class="dashicons dashicons-update nobash-files-spin"></span> Scanning…';}
          try { const r=await api('monitoring',{method:'POST',body:JSON.stringify({action:'scan_now'})}); notice(r.scan?.complete===false?'Scan finished but the safety budget was reached. Results are incomplete.':'Integrity scan complete.',r.scan?.complete===false?'warning':'success'); await render(); } catch(e){notice(e.message,'error');if(btn){btn.disabled=false;btn.innerHTML=old;}}
        });
        qs('#nobash-files-monitor-mark-read')?.addEventListener('click', async () => { try{await api('monitoring',{method:'POST',body:JSON.stringify({action:'mark_all_read'})});notice('All alerts marked as read.');await render();}catch(e){notice(e.message,'error');} });
        qsa('[data-monitor-dismiss]',host).forEach(btn=>btn.addEventListener('click',async()=>{try{await api('monitoring',{method:'POST',body:JSON.stringify({action:'dismiss',id:btn.dataset.monitorDismiss})});await render();}catch(e){notice(e.message,'error');}}));
      } catch(e) {
        notice(e.message,'error');
        host.innerHTML='<div class="nobash-files-integrity-empty is-warning">Monitoring data could not be loaded.</div>';
      }
    };
    await render();
  }


  async function renderSecurity(host) {
    const securityIcon = severity => severity === 'high' ? 'dashicons-shield' : 'dashicons-warning';
    const load = async () => {
      try {
        const [data, integrity] = await Promise.all([api('security'), api('integrity')]);
        const scan = integrity.last_scan || {};
        const suspicious = Array.isArray(scan.suspicious) ? scan.suspicious : [];
        const coreItems = Array.isArray(data.repairable_core) ? data.repairable_core : [];
        const quarantine = Array.isArray(data.quarantine) ? data.quarantine : [];
        const perm = data.permissions || {overview:[],world_writable:{items:[]}};
        const ww = perm.world_writable || {items:[]};
        const repairs = Array.isArray(data.repairs) ? data.repairs : [];
        host.innerHTML = `
          <section class="nobash-files-security-hero">
            <div class="nobash-files-security-hero-icon"><span class="dashicons dashicons-shield-alt"></span></div>
            <div><span class="nobash-files-eyebrow">Guarded recovery</span><h3>Security & Repair</h3><p>Check site files first, then review the results below. Choose which files to isolate or repair.</p></div>
            <a class="button" href="admin.php?page=nobash-files-integrity">Check site files</a>
          </section>
          <section class="nobash-files-security-metrics">
            <article><span class="dashicons dashicons-warning"></span><div><strong>Review Findings</strong><b>${suspicious.length}</b><small>Heuristics, not malware verdicts</small></div></article>
            <article><span class="dashicons dashicons-lock"></span><div><strong>Quarantine</strong><b>${quarantine.length}</b><small>Stored privately, cannot run</small></div></article>
            <article><span class="dashicons dashicons-wordpress"></span><div><strong>Core Repair</strong><b>${coreItems.length}</b><small>Modified or missing core files</small></div></article>
            <article><span class="dashicons dashicons-admin-network"></span><div><strong>Public write access</strong><b>${ww.scanned_at?`${(ww.items||[]).length}${ww.truncated?'+':''}`:'Not scanned'}</b><small>${ww.scanned_at?`${Number(ww.files_checked||0).toLocaleString()} paths checked`:'Check who can change files'}</small></div></article>
          </section>
          <div class="nobash-files-security-grid">
            <section class="nobash-files-security-main">
              <article class="nobash-files-security-card">
                <div class="nobash-files-security-card-head"><div><span class="dashicons dashicons-shield"></span><div><h4>Files to review</h4><p>Move a file into private storage so it cannot run. Review it first: moving a needed file can affect your site.</p></div></div><span class="nobash-files-badge">${suspicious.length} flagged</span></div>
                ${suspicious.length ? `<div class="nobash-files-security-list">${suspicious.map(item=>`<div class="nobash-files-security-row severity-${esc(item.severity||'medium')}"><span class="dashicons ${securityIcon(item.severity)}"></span><div><strong>${esc(item.path||'')}</strong><p>${esc(item.reason||'Review this file manually.')}</p><small>Rule: ${esc(item.rule||'heuristic')}</small></div><button class="button nobash-files-danger" data-security-quarantine="${esc(item.path||'')}">Quarantine</button></div>`).join('')}</div>` : '<div class="nobash-files-integrity-empty"><span class="dashicons dashicons-yes-alt"></span>No suspicious files are present in the latest integrity result.</div>'}
              </article>

              <article class="nobash-files-security-card">
                <div class="nobash-files-security-card-head"><div><span class="dashicons dashicons-wordpress"></span><div><h4>Repair WordPress files</h4><p>Selected files are replaced only from the official package for the running WordPress version and must match official checksums before installation.</p></div></div><span class="nobash-files-badge ${coreItems.length?'':'ready'}">${esc(data.core?.version||'')}</span></div>
                ${coreItems.length ? `<div class="nobash-files-core-repair-list">${coreItems.map((item,i)=>`<label><input type="checkbox" data-core-repair-path value="${esc(item.path)}" ${i<10?'checked':''}><span><strong>${esc(item.path)}</strong><small>${esc(item.label||item.status||'Review')}</small></span></label>`).join('')}</div><div class="nobash-files-security-actions"><button class="button button-primary" id="nobash-files-security-repair-core">Repair Selected Core Files</button><small>Maximum ${Number(data.limits?.max_core_repair_files||25)} files per repair. A rollback snapshot is retained.</small></div>` : '<div class="nobash-files-integrity-empty"><span class="dashicons dashicons-yes-alt"></span>No modified or missing core files are available from the latest integrity scan.</div>'}
              </article>

              <article class="nobash-files-security-card">
                <div class="nobash-files-security-card-head"><div><span class="dashicons dashicons-admin-plugins"></span><div><h4>Check a plugin or theme</h4><p>Read-only comparison against the exact installed version from WordPress.org. Commercial/private packages are not guessed.</p></div></div></div>
                <div class="nobash-files-component-verify"><select id="nobash-files-component-type" aria-label="Plugin or theme"><option value="plugin">Plugin</option><option value="theme">Theme</option></select><input id="nobash-files-component-slug" type="text" aria-label="WordPress.org plugin or theme name" placeholder="Example: akismet"><button class="button button-primary" id="nobash-files-component-verify">Check files</button></div>
                <div id="nobash-files-component-result" class="nobash-files-component-result"><p class="nobash-files-muted">Enter its WordPress.org name from the plugin or theme URL, for example: akismet.</p></div>
              </article>

              <article class="nobash-files-security-card">
                <div class="nobash-files-security-card-head"><div><span class="dashicons dashicons-admin-network"></span><div><h4>File permissions</h4><p>Find files that anyone on the server can change. Repair removes public write access.</p></div></div><button class="button" id="nobash-files-security-scan-permissions">${ww.scanned_at?'Rescan Permissions':'Scan Permissions'}</button></div>
                <div class="nobash-files-permission-overview">${(perm.overview||[]).map(item=>`<div><strong>${esc(item.label||'')}</strong><code>${esc(item.mode||'')}</code><span class="nobash-files-badge ${item.world_writable?'':'ready'}">${item.world_writable?'World writable':(item.writable?'Writable':'Read only')}</span></div>`).join('')}</div>
                ${!ww.scanned_at ? '<div class="nobash-files-integrity-empty"><span class="dashicons dashicons-search"></span>Run the bounded permission scan when you want to inspect file modes. The dashboard does not crawl the whole site on every page load.</div>' : (ww.items||[]).length ? `<div class="nobash-files-security-list">${ww.items.map(item=>`<div class="nobash-files-security-row"><span class="dashicons dashicons-admin-network"></span><div><strong>${esc(item.path||'/')}</strong><p>${esc(item.type||'path')} · mode ${esc(item.mode||'')}</p></div><button class="button" data-permission-repair="${esc(item.path||'')}">Remove World Write</button></div>`).join('')}</div>` : '<div class="nobash-files-integrity-empty"><span class="dashicons dashicons-yes-alt"></span>No world-writable paths found in the bounded scan.</div>'}
                ${ww.truncated ? '<div class="nobash-files-integrity-warning"><span class="dashicons dashicons-warning"></span><div><strong>Permission scan was bounded</strong><p>More paths may exist beyond the current scan limit.</p></div></div>' : ''}
              </article>
            </section>
            <aside class="nobash-files-security-side">
              <section class="nobash-files-security-sidebox"><h4>Quarantine</h4>${quarantine.length ? `<div class="nobash-files-quarantine-list">${quarantine.map(item=>`<div class="nobash-files-quarantine-item"><strong>${esc(item.original_path||'')}</strong><small>${esc(item.quarantined_at||'')} · ${fmtSize(item.size||0)}</small><p>${esc(item.reason||'')}</p><div><button class="button button-small" data-quarantine-restore="${esc(item.id)}">Restore</button><button class="button button-small nobash-files-danger" data-quarantine-delete="${esc(item.id)}">Delete</button></div></div>`).join('')}</div>` : '<p>No quarantined files.</p>'}</section>
              <section class="nobash-files-security-sidebox"><h4>Undo a previous repair</h4>${repairs.length ? repairs.map(r=>`<div class="nobash-files-repair-item"><strong>${esc(r.id||'Repair')}</strong><small>${esc(r.created_at||'')} · ${(r.items||[]).length} file(s)</small><button class="button button-small" data-repair-rollback="${esc(r.id||'')}">Rollback</button></div>`).join('') : '<p>No retained repair snapshots.</p>'}</section>
              <section class="nobash-files-security-sidebox nobash-files-security-safety"><h4>Safety rules</h4><ul><li>No automatic malware deletion</li><li>No third-party package trust claims</li><li>No recursive chmod normalization</li><li>Core repair requires official checksum match</li><li>Rollback stops if repaired files changed again</li></ul></section>
            </aside>
          </div>`;

      simplifyPages(host);
        qsa('[data-security-quarantine]',host).forEach(btn=>btn.addEventListener('click',async()=>{
          const path=btn.dataset.securityQuarantine||'';
          const m=await showModal({title:'Quarantine this file?',message:`${path} will be copied to hardened private storage, verified, and then removed from the live filesystem. Quarantining a legitimate file can affect the site.`,confirmText:'Quarantine File',danger:true});
          if(!m.confirmed)return;
          try{await api('security',{method:'POST',body:JSON.stringify({action:'quarantine',path,reason:'Quarantined from integrity suspicious-file review'})});notice('File quarantined safely.','warning');await load();}catch(e){notice(e.message,'error');}
        }));
        qs('#nobash-files-security-repair-core',host)?.addEventListener('click',async()=>{
          const paths=qsa('[data-core-repair-path]:checked',host).map(el=>el.value);
          if(!paths.length){notice('Choose at least one core file.','warning');return;}
          const m=await showModal({title:'Repair selected WordPress core files?',message:`${paths.length} file(s) will be downloaded from the official WordPress package for this exact version, checksum-verified, backed up, and atomically replaced.`,confirmText:'Repair & Verify'});
          if(!m.confirmed)return;
          try{const r=await api('security',{method:'POST',body:JSON.stringify({action:'repair_core',paths})});notice(r.message||'Core repair completed.');await load();}catch(e){notice(e.message,'error');}
        });
        qs('#nobash-files-security-scan-permissions',host)?.addEventListener('click',async()=>{
          try{await api('security',{method:'POST',body:JSON.stringify({action:'scan_permissions'})});notice('Permission scan completed.');await load();}catch(e){notice(e.message,'error');}
        });
        qsa('[data-permission-repair]',host).forEach(btn=>btn.addEventListener('click',async()=>{
          const path=btn.dataset.permissionRepair||'';
          const m=await showModal({title:'Remove world-write permission?',message:`Nobash will only remove the public/world write bit from ${path||'/'} and will not change ownership or recursively normalize child permissions.`,confirmText:'Repair Permission'});
          if(!m.confirmed)return;
          try{await api('security',{method:'POST',body:JSON.stringify({action:'repair_permission',path})});notice('Permission repaired.');await load();}catch(e){notice(e.message,'error');}
        }));
        qsa('[data-quarantine-restore]',host).forEach(btn=>btn.addEventListener('click',async()=>{
          const m=await showModal({title:'Restore quarantined file?',message:'The quarantine blob will be SHA-256 verified before restore. If the original path is occupied, Nobash will keep both files.',confirmText:'Restore File'});
          if(!m.confirmed)return;
          try{const r=await api('security',{method:'POST',body:JSON.stringify({action:'restore_quarantine',id:btn.dataset.quarantineRestore,keep_both:true})});notice(`Restored to ${r.restored_path||'the site'}.`);await load();}catch(e){notice(e.message,'error');}
        }));
        qsa('[data-quarantine-delete]',host).forEach(btn=>btn.addEventListener('click',async()=>{
          const m=await showModal({title:'Delete quarantine item permanently?',message:'The private recovery blob will be permanently removed. This cannot be undone.',confirmText:'Delete Permanently',danger:true});
          if(!m.confirmed)return;
          try{await api('security',{method:'POST',body:JSON.stringify({action:'delete_quarantine',id:btn.dataset.quarantineDelete})});notice('Quarantine item deleted.');await load();}catch(e){notice(e.message,'error');}
        }));
        qsa('[data-repair-rollback]',host).forEach(btn=>btn.addEventListener('click',async()=>{
          const m=await showModal({title:'Rollback core repair?',message:'Rollback will only proceed if the repaired files have not changed again since repair.',confirmText:'Rollback Repair',danger:true});
          if(!m.confirmed)return;
          try{await api('security',{method:'POST',body:JSON.stringify({action:'rollback_core',repair_id:btn.dataset.repairRollback})});notice('Core repair rolled back.','warning');await load();}catch(e){notice(e.message,'error');}
        }));
        qs('#nobash-files-component-verify',host)?.addEventListener('click',async()=>{
          const type=qs('#nobash-files-component-type',host)?.value||'plugin';
          const slug=(qs('#nobash-files-component-slug',host)?.value||'').trim();
          if(!slug){notice('Enter an installed WordPress.org component slug.','warning');return;}
          const out=qs('#nobash-files-component-result',host);out.innerHTML='<p class="nobash-files-muted">Downloading and verifying the exact package…</p>';
          try{const r=await api('security',{method:'POST',body:JSON.stringify({action:'verify_component',type,slug})});const issues=(r.modified||[]).length+(r.missing||[]).length+(r.unexpected||[]).length;out.innerHTML=`<div class="nobash-files-component-summary ${r.clean?'is-clean':'is-review'}"><strong>${esc(r.slug)} ${esc(r.version)}</strong><span class="nobash-files-badge ${r.clean?'ready':''}">${r.clean?'Verified':'Review '+issues+' issue(s)'}</span></div>${['modified','missing','unexpected'].map(k=>(r[k]||[]).length?`<div class="nobash-files-component-group"><b>${k[0].toUpperCase()+k.slice(1)} (${r[k].length})</b><p>${r[k].slice(0,30).map(x=>esc(x)).join('<br>')}</p></div>`:'').join('')}<small>${Number(r.verified||0).toLocaleString()} package files matched exactly.</small>`;}catch(e){out.innerHTML=`<div class="nobash-files-integrity-empty is-warning"><span class="dashicons dashicons-warning"></span>${esc(e.message)}</div>`;}
        });
      } catch(e) {
        notice(e.message,'error');
        host.innerHTML='<div class="nobash-files-integrity-empty is-warning"><span class="dashicons dashicons-warning"></span><strong>Security dashboard could not be loaded.</strong><p>Review the server error log and reload.</p></div>';
      }
    };
    await load();
  }

  async function renderMultisite(host) {
    try {
      const data = await api('multisite');
      const sites = data.sites || [];
      const summary = data.summary || {};
      const statusBadge = status => {
        const label = ({clean:'Verified',warning:'Review',critical:'Critical',incomplete:'Incomplete',unknown:'Not scanned',needs_baseline:'Setup needed'})[status] || 'Not scanned';
        return `<span class="nobash-files-badge ${status === 'clean' ? 'ready' : status === 'critical' ? 'is-critical' : ''}">${esc(label)}</span>`;
      };
      host.innerHTML = `
        <section class="nobash-files-network-hero">
          <div><span class="nobash-files-eyebrow">WordPress Multisite</span><h3>${esc(data.network_name || 'Network')}</h3><p>Site media workspaces stay separated by their real uploads directories. WordPress core, plugins and themes remain shared network files.</p></div>
          <div class="nobash-files-network-hero-stat"><strong>${Number(data.total_sites || 0).toLocaleString()}</strong><span>Sites</span></div>
        </section>
        <section class="nobash-files-network-metrics">
          <article><span class="dashicons dashicons-yes-alt"></span><div><strong>${Number(summary.verified || 0)}</strong><small>Verified</small></div></article>
          <article><span class="dashicons dashicons-warning"></span><div><strong>${Number(summary.review || 0)}</strong><small>Need review</small></div></article>
          <article><span class="dashicons dashicons-clock"></span><div><strong>${Number(summary.unknown || 0)}</strong><small>Not scanned/setup</small></div></article>
          <article><span class="dashicons dashicons-networking"></span><div><strong>${Number(data.listed_sites || 0)}</strong><small>Loaded in overview</small></div></article>
        </section>
        ${data.truncated ? `<div class="nobash-files-integrity-warning"><span class="dashicons dashicons-info"></span><div><strong>Large network</strong><p>This overview is bounded to ${Number(data.site_limit || 250)} sites per load. No site outside this view is being reported as healthy or unhealthy.</p></div></div>` : ''}
        <section class="nobash-files-network-shared"><div class="nobash-files-panel-head"><strong>Shared WordPress folders</strong><span class="nobash-files-muted">Changes here can affect multiple sites</span></div><div class="nobash-files-network-shared-grid">${(data.shared_paths || []).map(p=>`<article><span class="dashicons ${p.label === 'Plugins' ? 'dashicons-admin-plugins' : p.label === 'Themes' ? 'dashicons-admin-appearance' : 'dashicons-wordpress'}"></span><div><strong>${esc(p.label)}</strong><small>/${esc(p.path || '')}</small></div><span class="nobash-files-badge">Shared</span></article>`).join('')}</div></section>
        <section class="nobash-files-network-sites">
          <div class="nobash-files-panel-head"><div><strong>Network sites</strong><p>Choose a site to open its media files or review its security.</p></div><input id="nobash-files-network-filter" class="regular-text" type="search" placeholder="Filter sites…"></div>
          <div class="nobash-files-network-table-wrap"><table class="nobash-files-table nobash-files-network-table"><thead><tr><th>Site</th><th>Media folder</th><th>Integrity</th><th>Monitoring</th><th>Alerts</th><th>Actions</th></tr></thead><tbody id="nobash-files-network-site-rows">${sites.map(site=>`<tr data-network-site-row data-search="${esc((site.name+' '+site.url).toLowerCase())}"><td><strong>${esc(site.name || `Site #${site.id}`)}</strong><small>${esc(site.url || '')}</small></td><td><code>/${esc(site.uploads?.path || 'External/custom uploads')}</code><small>${site.uploads?.writable ? 'Writable' : 'Check write access'}</small></td><td>${statusBadge(site.integrity?.status)}<small>${esc(site.integrity?.scanned_at || 'No scan yet')}</small></td><td><span class="nobash-files-badge ${site.monitoring?.enabled ? 'ready' : ''}">${site.monitoring?.enabled ? esc(site.monitoring.schedule || 'Enabled') : 'Off'}</span></td><td><strong>${Number(site.alerts?.open || 0)}</strong><small>${Number(site.alerts?.critical || 0)} critical</small></td><td><div class="nobash-files-network-actions">${site.admin_url ? `<a class="button button-primary" href="${esc(site.admin_url)}">Open Files</a>` : '<span class="nobash-files-badge">External uploads</span>'}<a class="button" href="${esc(site.integrity_url || '#')}">Integrity</a><a class="button" href="${esc(site.security_url || '#')}">Security</a></div></td></tr>`).join('')}</tbody></table></div>
        </section>`;
      simplifyPages(host);
      qs('#nobash-files-network-filter', host)?.addEventListener('input', e => {
        const term = String(e.target.value || '').toLowerCase().trim();
        qsa('[data-network-site-row]', host).forEach(row => { row.hidden = term && !String(row.dataset.search || '').includes(term); });
      });
    } catch (e) {
      notice(e.message, 'error');
      host.innerHTML = '<div class="nobash-files-integrity-empty is-warning"><span class="dashicons dashicons-warning"></span><strong>Network overview unavailable</strong><p>Multisite must be enabled and you must be a Network Administrator.</p></div>';
    }
  }

  async function renderAgency(host) {
    let data;
    try { data = await api('agency'); } catch (e) { notice(e.message,'error'); return; }
    const perms = data.permissions || [];
    let selectedTemplate = data.templates?.[0]?.id || '';
    let subjects = {roles:[], users:[]};
    const siteId = () => Number(qs('#nobash-files-agency-site',host)?.value || 0);
    const loadSubjects = async () => {
      try {
        const id = siteId();
        subjects = await api(`agency?action=subjects&site_id=${id}`);
        const type = qs('#nobash-files-agency-target-type',host)?.value || 'role';
        const select = qs('#nobash-files-agency-subject',host);
        if (!select) return;
        const list = type === 'role' ? subjects.roles : subjects.users;
        select.innerHTML = (list || []).map(x=>`<option value="${esc(x.id)}">${esc(x.name)}${x.login ? ` (${esc(x.login)})` : ''}</option>`).join('') || '<option value="">No eligible subjects</option>';
      } catch(e) { notice(e.message,'error'); }
    };
    const templateById = id => (data.templates || []).find(t => String(t.id) === String(id));
    const render = () => {
      const t = templateById(selectedTemplate) || null;
      const p = t?.policy || {enabled:true,roots:['{site_uploads}'],denied_paths:[],allow_protected:false,permissions:{browse:true,read:true,download:true,upload:true,create:true}};
      const siteOptions = data.is_multisite ? (data.sites || []).map(s=>`<option value="${s.id}">${esc(s.name || `Site #${s.id}`)}</option>`).join('') : '<option value="0">Current site</option>';
      host.innerHTML = `
        <section class="nobash-files-agency-hero"><div><span class="nobash-files-eyebrow">Agency controls</span><h3>Access templates</h3><p>Save folder and action settings to reuse for your team. A template grants access only after you apply it.</p></div><button class="button" id="nobash-files-agency-new" type="button"><span class="dashicons dashicons-plus-alt2"></span> New Template</button></section>
        <div class="nobash-files-agency-layout">
          <aside class="nobash-files-agency-template-list"><div class="nobash-files-panel-head"><strong>Saved templates</strong><span>${(data.templates||[]).length}</span></div>${(data.templates||[]).length ? (data.templates||[]).map(x=>`<button type="button" class="nobash-files-agency-template ${String(x.id)===String(selectedTemplate)?'is-active':''}" data-agency-template="${esc(x.id)}"><span class="dashicons dashicons-shield"></span><div><strong>${esc(x.name)}</strong><small>${esc(x.description || 'Reusable access policy')}</small></div></button>`).join('') : '<div class="nobash-files-empty"><span class="dashicons dashicons-shield"></span><strong>No templates yet</strong><p>Create a reusable policy for clients or team members.</p></div>'}</aside>
          <section class="nobash-files-agency-editor">
            <div class="nobash-files-panel-head"><div><strong>${t ? 'Edit template' : 'Create template'}</strong><p>Use <code>{site_uploads}</code> for the site’s media folder.</p></div></div>
            <div class="nobash-files-form-grid two"><label><span>Template name</span><input id="nobash-files-agency-name" type="text" value="${esc(t?.name || '')}" placeholder="Client Upload Manager"></label><label><span>Description</span><input id="nobash-files-agency-description" type="text" value="${esc(t?.description || '')}" placeholder="Upload/download access without code editing"></label></div>
            <div class="nobash-files-form-grid two"><label><span>Allowed folders</span><textarea id="nobash-files-agency-roots" rows="4">${esc((p.roots || []).join('\n'))}</textarea><small>One relative path per line. Multisite templates may use {site_uploads}.</small></label><label><span>Blocked folders or files</span><textarea id="nobash-files-agency-denied" rows="4">${esc((p.denied_paths || []).join('\n'))}</textarea><small>These paths stay blocked, even inside an allowed folder.</small></label></div>
            <label class="nobash-files-setting compact"><div><strong>Protected WordPress files</strong><p>Allow this profile to modify protected WordPress locations.</p></div><input id="nobash-files-agency-protected" type="checkbox" ${p.allow_protected?'checked':''}></label>
            <div class="nobash-files-agency-permissions"><strong>Allowed actions</strong><div class="nobash-files-permission-grid">${perms.map(k=>`<label><input type="checkbox" data-agency-perm="${esc(k)}" ${p.permissions?.[k]?'checked':''} ${k==='browse'?'disabled':''}><span>${esc(k.replaceAll('_',' '))}</span></label>`).join('')}</div></div>
            <div class="nobash-files-form-actions"><button class="button button-primary" id="nobash-files-agency-save" type="button">Save Template</button>${t ? '<button class="button nobash-files-danger" id="nobash-files-agency-delete" type="button">Delete Template</button>' : ''}</div>
          </section>
        </div>
        <section class="nobash-files-agency-apply"><div class="nobash-files-panel-head"><div><strong>Apply template</strong><p>Choose exactly where this policy should be used.</p></div></div><div class="nobash-files-agency-apply-row"><label><span>Template</span><select id="nobash-files-agency-apply-template">${(data.templates||[]).map(x=>`<option value="${esc(x.id)}" ${String(x.id)===String(selectedTemplate)?'selected':''}>${esc(x.name)}</option>`).join('') || '<option value="">Save a template first</option>'}</select></label><label><span>Site</span><select id="nobash-files-agency-site">${siteOptions}</select></label><label><span>Apply to</span><select id="nobash-files-agency-target-type"><option value="role">Role</option><option value="user">User</option></select></label><label><span>Role / User</span><select id="nobash-files-agency-subject"><option>Loading…</option></select></label><button class="button button-primary" id="nobash-files-agency-apply" type="button" ${data.templates?.length ? '' : 'disabled'}>Apply access</button></div></section>
        <section class="nobash-files-agency-audit"><div class="nobash-files-panel-head"><div><strong>Audit trail</strong><p>Latest file-manager activity${data.is_multisite?' across the network':''}. Export is capped to 5,000 rows per request.</p></div><div class="nobash-files-audit-controls">${data.is_multisite?`<select id="nobash-files-agency-audit-site"><option value="0">All network sites</option>${(data.sites||[]).map(s=>`<option value="${s.id}">${esc(s.name||`Site #${s.id}`)}</option>`).join('')}</select>`:''}<a class="button" id="nobash-files-audit-export" href="${esc(NobashFiles.auditExportUrl || '#')}"><span class="dashicons dashicons-download"></span> Export CSV</a></div></div><div id="nobash-files-agency-audit-rows"><p class="nobash-files-muted">Loading activity…</p></div></section>`;
      simplifyPages(host);
      qsa('[data-agency-template]',host).forEach(btn=>btn.addEventListener('click',()=>{selectedTemplate=btn.dataset.agencyTemplate||'';render();loadSubjects();loadAudit();}));
      qs('#nobash-files-agency-new',host)?.addEventListener('click',()=>{selectedTemplate='';render();loadSubjects();loadAudit();});
      qs('#nobash-files-agency-target-type',host)?.addEventListener('change',loadSubjects);
      qs('#nobash-files-agency-site',host)?.addEventListener('change',loadSubjects);
      qs('#nobash-files-agency-audit-site',host)?.addEventListener('change',loadAudit);
      qs('#nobash-files-agency-save',host)?.addEventListener('click',async()=>{
        const permissions={}; qsa('[data-agency-perm]',host).forEach(x=>permissions[x.dataset.agencyPerm]=x.checked || x.dataset.agencyPerm==='browse');
        const body={action:'save_template',template:{id:selectedTemplate,name:qs('#nobash-files-agency-name',host)?.value||'',description:qs('#nobash-files-agency-description',host)?.value||'',policy:{enabled:true,roots:(qs('#nobash-files-agency-roots',host)?.value||'').split(/\r?\n|,/).map(v=>v.trim()).filter(Boolean),denied_paths:(qs('#nobash-files-agency-denied',host)?.value||'').split(/\r?\n|,/).map(v=>v.trim()).filter(Boolean),allow_protected:Boolean(qs('#nobash-files-agency-protected',host)?.checked),permissions}}};
        try{const r=await api('agency',{method:'POST',body:JSON.stringify(body)});data=r.agency;selectedTemplate=r.template.id;notice('Access template saved.');render();await loadSubjects();await loadAudit();}catch(e){notice(e.message,'error');}
      });
      qs('#nobash-files-agency-delete',host)?.addEventListener('click',async()=>{const m=await showModal({title:'Delete policy template?',message:'Existing role/user policies already applied from this template are not removed.',confirmText:'Delete Template',danger:true});if(!m.confirmed)return;try{data=await api('agency',{method:'DELETE',body:JSON.stringify({action:'delete_template',template_id:selectedTemplate})});selectedTemplate=data.templates?.[0]?.id||'';notice('Template deleted.');render();await loadSubjects();await loadAudit();}catch(e){notice(e.message,'error');}});
      qs('#nobash-files-agency-apply',host)?.addEventListener('click',async()=>{const templateId=qs('#nobash-files-agency-apply-template',host)?.value||'';const type=qs('#nobash-files-agency-target-type',host)?.value||'role';const subject=qs('#nobash-files-agency-subject',host)?.value||'';if(!templateId||!subject)return notice('Choose a template and role/user.','warning');const m=await showModal({title:'Apply this access template?',message:'This will replace the selected role/user access policy on the chosen site with this template.',confirmText:'Apply access'});if(!m.confirmed)return;try{await api('agency',{method:'POST',body:JSON.stringify({action:'apply_template',template_id:templateId,type,subject_id:subject,site_id:siteId()})});notice('Access template applied.');await loadAudit();}catch(e){notice(e.message,'error');}});
      loadSubjects(); loadAudit();
    };
    const loadAudit = async () => {
      const box=qs('#nobash-files-agency-audit-rows',host); if(!box)return;
      try{const id=Number(qs('#nobash-files-agency-audit-site',host)?.value || 0);const r=await api(`agency?action=audit&limit=100&site_id=${id}`);const rows=r.rows||[];box.innerHTML=rows.length?`<div class="nobash-files-audit-list">${rows.map(row=>`<div class="nobash-files-audit-row"><span class="dashicons dashicons-update"></span><div><strong>${esc(String(row.action||'').replaceAll('_',' '))}</strong><p>${esc(row.path||'')}</p><small>${data.is_multisite?`${esc(row.site_name)} · `:''}${esc(row.user||`User #${row.user_id||0}`)}</small></div><time>${esc(row.created_at||'')}</time></div>`).join('')}</div>`:'<div class="nobash-files-empty"><span class="dashicons dashicons-list-view"></span><strong>No activity yet</strong><p>File operations and policy changes will appear here.</p></div>';const exportLink=qs('#nobash-files-audit-export',host);if(exportLink&&NobashFiles.auditExportUrl){const u=new URL(NobashFiles.auditExportUrl,window.location.href);if(id)u.searchParams.set('site_id',String(id));else u.searchParams.delete('site_id');exportLink.href=u.toString();}}catch(e){box.innerHTML=`<p class="nobash-files-muted">${esc(e.message)}</p>`;}
    };
    render();
  }

  function initSimple() {
    const host = qs('#nobash-files-simple-content'); if (!host) return;
    if (view === 'access') { renderAccessControl(host); return; }
    if (view === 'integrity') { renderIntegrity(host); return; }
    if (view === 'security') { renderSecurity(host); return; }
    if (view === 'monitoring') { renderMonitoring(host); return; }
    if (view === 'multisite') { renderMultisite(host); return; }
    if (view === 'agency') { renderAgency(host); return; }
    if (view === 'safe' || view === 'settings') {
      api('settings').then(s => {
        const versionOptions = (Number(s.version_limit_max || 5) <= 5 ? [5] : [5,10,20,30,50,100].filter(v => v <= Number(s.version_limit_max || 100)));
        const workspaceOptions = (s.workspace_choices || [{value:'',label:'WordPress root',description:'Start with the complete WordPress filesystem.'}]).map(w => `<option value="${esc(w.value)}" ${String(s.default_workspace || '') === String(w.value || '') ? 'selected' : ''}>${esc(w.label)} — /${esc(w.value || '')}</option>`).join('');
        const safetyControls = `<div class="nobash-files-setting-stack">
          <label class="nobash-files-setting nobash-files-setting-primary"><div><span class="nobash-files-setting-icon"><span class="dashicons dashicons-shield-alt"></span></span><div><strong>Safe Mode</strong><p>Create recoverable versions before code edits and require confirmation for protected WordPress filesystem areas.</p></div></div><input type="checkbox" id="nobash-files-safe-toggle" ${s.safe_mode ? 'checked' : ''}></label>
          <label class="nobash-files-setting"><div><strong>Maximum editor file size</strong><p>Keep very large files out of the browser editor to reduce memory pressure and accidental corruption.</p></div><select id="nobash-files-max-edit"><option value="524288" ${s.max_edit_bytes === 524288 ? 'selected' : ''}>512 KB</option><option value="1048576" ${s.max_edit_bytes === 1048576 ? 'selected' : ''}>1 MB — recommended</option><option value="2097152" ${s.max_edit_bytes === 2097152 ? 'selected' : ''}>2 MB</option><option value="5242880" ${s.max_edit_bytes === 5242880 ? 'selected' : ''}>5 MB</option></select></label>
          <label class="nobash-files-setting"><div><strong>Versions kept per file</strong><p>Older safety snapshots are pruned automatically so version storage stays bounded.</p></div><div class="nobash-files-setting-control"><select id="nobash-files-version-limit">${versionOptions.map(v => `<option value="${v}" ${Number(s.version_limit) === v ? 'selected' : ''}>${v} versions</option>`).join('')}</select><small>All retention options are included.</small></div></label>
        </div>`;
        if (view === 'safe') {
          host.innerHTML = `<section class="nobash-files-settings-hero"><div class="nobash-files-settings-hero-icon"><span class="dashicons dashicons-shield-alt"></span></div><div><span class="nobash-files-eyebrow">Safety center</span><h3>Protection that stays out of the way.</h3><p>Safe Mode adds recovery and validation around risky changes while normal browsing, upload and download remain familiar.</p></div><span class="nobash-files-badge ${s.safe_mode ? 'ready' : 'protected'}">${s.safe_mode ? 'Protection on' : 'Protection off'}</span></section>
            <section class="nobash-files-settings-section"><div class="nobash-files-settings-section-head"><div><h3>Safe editing</h3><p>These controls affect live file edits and recovery history.</p></div><a class="button" href="admin.php?page=nobash-files-settings">All Settings</a></div>${safetyControls}</section>
            <section class="nobash-files-safety-explain"><article><span class="dashicons dashicons-backup"></span><strong>Before edit</strong><p>A previous version is stored where supported.</p></article><article><span class="dashicons dashicons-yes-alt"></span><strong>Before save</strong><p>PHP/JSON validation and disk-change checks run first.</p></article><article><span class="dashicons dashicons-undo"></span><strong>After change</strong><p>Use version restore or supported operation Undo when needed.</p></article></section>`;
        } else {
          host.innerHTML = `<section class="nobash-files-settings-hero"><div class="nobash-files-settings-hero-icon"><span class="dashicons dashicons-admin-generic"></span></div><div><span class="nobash-files-eyebrow">Preferences</span><h3>Simple defaults, stronger safety.</h3><p>Configure where Explorer starts, editor protection, upload chunking and version retention.</p></div><span class="nobash-files-edition-pill ">FREE</span></section>
            <div class="nobash-files-settings-layout">
              <div>
                <section class="nobash-files-settings-section"><div class="nobash-files-settings-section-head"><div><h3>Explorer</h3><p>Choose the opening location and large-directory memory budget.</p></div></div><label class="nobash-files-setting"><div><strong>Starting workspace</strong><p>Only the opening location changes. This does not expand filesystem permissions.</p></div><select id="nobash-files-default-workspace">${workspaceOptions}</select></label><label class="nobash-files-setting"><div><strong>Items loaded per directory page</strong><p>Large folders are paged instead of loading every filename into PHP memory at once.</p></div><select id="nobash-files-directory-page-size"><option value="100" ${Number(s.directory_page_size)===100?'selected':''}>100 — lowest memory</option><option value="250" ${Number(s.directory_page_size)===250?'selected':''}>250 — recommended</option><option value="500" ${Number(s.directory_page_size)===500?'selected':''}>500 — faster browsing</option></select></label></section>
                <section class="nobash-files-settings-section"><div class="nobash-files-settings-section-head"><div><h3>Safety & editor</h3><p>Protection defaults for code changes.</p></div></div>${safetyControls}</section>
                <section class="nobash-files-settings-section"><div class="nobash-files-settings-section-head"><div><h3>Transfers & preview</h3><p>Local file handling defaults.</p></div></div><label class="nobash-files-setting"><div><strong>Upload chunk size</strong><p>Large uploads use sequential chunks with retry and final atomic placement. Nobash automatically lowers the effective request size when hosting limits are tighter.</p>${Number(s.chunk_bytes_effective||s.chunk_bytes)!==Number(s.chunk_bytes)?`<small class="nobash-files-runtime-note">Host-safe effective size: ${fmtSize(Number(s.chunk_bytes_effective||s.chunk_bytes))}</small>`:''}</div><select id="nobash-files-chunk-bytes"><option value="1048576" ${s.chunk_bytes===1048576?'selected':''}>1 MB</option><option value="2097152" ${s.chunk_bytes===2097152?'selected':''}>2 MB — recommended</option><option value="4194304" ${s.chunk_bytes===4194304?'selected':''}>4 MB</option><option value="8388608" ${s.chunk_bytes===8388608?'selected':''}>8 MB</option></select></label><div class="nobash-files-setting"><div><strong>Secure preview</strong><p>Supported media streams through authenticated admin endpoints. SVG and HTML are never rendered inline.</p></div><span class="nobash-files-badge ready">Always on</span></div></section><section class="nobash-files-settings-section"><div class="nobash-files-settings-section-head"><div><h3>Data retention</h3><p>Choose what happens to Nobash-specific records if Nobash Files is deleted from WordPress.</p></div></div><label class="nobash-files-setting"><div><strong>When the plugin is deleted</strong><p>Keeping data preserves version history, activity and recovery metadata for a later reinstall. Remove permanently deletes Nobash tables, settings, user preferences and known private recovery storage.</p></div><select id="nobash-files-uninstall-policy"><option value="keep" ${String(s.uninstall_policy||'keep')==='keep'?'selected':''}>Keep Nobash data — safest</option><option value="remove" ${String(s.uninstall_policy||'keep')==='remove'?'selected':''}>Remove Nobash data permanently</option></select></label></section>
              </div>
              <aside class="nobash-files-settings-side">
                <section class="nobash-files-settings-side-card"><span class="dashicons dashicons-welcome-learn-more"></span><h4>Setup Guide</h4><p>Revisit the beginner-friendly setup at any time. Existing files are not changed.</p><a class="button" href="${esc(NobashFiles.setupUrl || '#')}">Open Setup Guide</a></section>
                <section class="nobash-files-settings-side-card"><span class="dashicons dashicons-yes-alt"></span><h4>All features included</h4><p>Monitoring, access controls and security tools are available in this free plugin.</p></section>
              </aside>
            </div>`;
        }
        qs('#nobash-files-safe-toggle')?.addEventListener('change', async e => { await api('settings', {method:'POST', body:JSON.stringify({safe_mode:e.target.checked})}); NobashFiles.safeMode = e.target.checked; notice('Safe Mode setting updated.'); });
        qs('#nobash-files-max-edit')?.addEventListener('change', async e => { await api('settings', {method:'POST', body:JSON.stringify({max_edit_bytes:Number(e.target.value)})}); notice('Editor limit updated.'); });
        qs('#nobash-files-version-limit')?.addEventListener('change', async e => { await api('settings', {method:'POST', body:JSON.stringify({version_limit:Number(e.target.value)})}); notice('Version retention updated.'); });
        qs('#nobash-files-default-workspace')?.addEventListener('change', async e => { await api('settings', {method:'POST', body:JSON.stringify({default_workspace:e.target.value})}); NobashFiles.defaultWorkspace = e.target.value; notice('Starting workspace updated.'); });
        qs('#nobash-files-chunk-bytes')?.addEventListener('change', async e => { const v=Number(e.target.value); const r=await api('settings', {method:'POST', body:JSON.stringify({chunk_bytes:v})}); NobashFiles.configuredChunkBytes=v; NobashFiles.chunkBytes=Number(r.chunk_bytes_effective||r.chunk_bytes||v); notice(NobashFiles.chunkBytes===v?'Upload chunk size updated.':`Upload preference saved; this host is currently capped at ${fmtSize(NobashFiles.chunkBytes)} per chunk.`); });
        qs('#nobash-files-directory-page-size')?.addEventListener('change', async e => { const v=Number(e.target.value); await api('settings', {method:'POST', body:JSON.stringify({directory_page_size:v})}); directoryPageSize=v; NobashFiles.directoryPageSize=v; notice('Directory page size updated.'); });
        qs('#nobash-files-uninstall-policy')?.addEventListener('change', async e => { const v=e.target.value; if (v==='remove') { const yes=await showModal({title:'Remove data when plugin is deleted?',message:'If you later delete Nobash Files from the Plugins screen, Nobash tables, settings, user preferences and known private recovery storage will be removed permanently. Your normal WordPress files are never part of this cleanup.',confirmText:'Use Remove Policy',danger:true,input:false}); if(!yes.confirmed){e.target.value='keep';return;} } await api('settings',{method:'POST',body:JSON.stringify({uninstall_policy:v})}); notice('Uninstall data policy updated.'); });
      }).catch(e => notice(e.message, 'error'));
    } else if (view === 'activity') {
      api('activity').then(rows => {
        host.innerHTML = `<div class="nobash-files-activity-list">${rows.length ? rows.map(r => `<div class="nobash-files-activity-row"><span class="dashicons dashicons-update"></span><div><strong>${esc(r.action.replaceAll('_', ' '))}</strong><p>${esc(r.relative_path)}</p></div><time>${esc(r.created_at)}</time></div>`).join('') : '<p class="nobash-files-muted">No activity recorded yet.</p>'}</div>`;
      }).catch(e => notice(e.message, 'error'));
    } else if (view === 'trash') {
      const renderTrash = async () => {
        try {
          const data = await api('trash');
          const rows = data.items || [];
          host.innerHTML = `<div class="nobash-files-trash-toolbar"><div><strong>${rows.length} recovery entr${rows.length === 1 ? 'y' : 'ies'}</strong><p>Trash stores private recovery snapshots, not publicly accessible files.</p></div>${rows.length ? '<button class="button nobash-files-danger" id="nobash-files-empty-trash" type="button">Empty Trash</button>' : ''}</div><div class="nobash-files-trash-list">${rows.length ? rows.map(row => `<article class="nobash-files-trash-row"><div class="nobash-files-trash-icon"><span class="dashicons dashicons-trash"></span></div><div class="nobash-files-trash-main"><strong>${esc(row.label)}</strong><p>${(row.items || []).map(i => esc(i.path)).join('<br>')}</p><small>${esc(row.created_at)} · ${row.item_count} item${row.item_count === 1 ? '' : 's'}</small></div><div class="nobash-files-trash-actions"><button class="button button-primary" data-trash-restore="${row.id}" type="button">Restore</button><button class="button nobash-files-danger" data-trash-purge="${row.id}" type="button">Delete Permanently</button></div></article>`).join('') : '<div class="nobash-files-empty nobash-files-trash-empty"><span class="dashicons dashicons-trash"></span><strong>Trash is empty</strong><p>Removed files will appear here while a recovery snapshot exists.</p></div>'}</div>`;
          qsa('[data-trash-restore]', host).forEach(btn => btn.addEventListener('click', async () => {
            try { await api('trash', {method:'POST', body:JSON.stringify({action:'restore', operation_id:Number(btn.dataset.trashRestore)})}); notice('Restored from Trash.'); await renderTrash(); await refreshUndoState(); } catch (e) { notice(e.message,'error'); }
          }));
          qsa('[data-trash-purge]', host).forEach(btn => btn.addEventListener('click', async () => {
            const yes = await showModal({title:'Delete permanently?', message:'This removes the private recovery snapshot. This action cannot be undone.', confirmText:'Delete Permanently', danger:true, input:false});
            if (!yes.confirmed) return;
            try { await api('trash', {method:'POST', body:JSON.stringify({action:'purge', operation_id:Number(btn.dataset.trashPurge)})}); notice('Recovery snapshot permanently deleted.'); await renderTrash(); await refreshUndoState(); } catch (e) { notice(e.message,'error'); }
          }));
          qs('#nobash-files-empty-trash')?.addEventListener('click', async () => {
            const yes = await showModal({title:'Empty Trash?', message:'All available Trash recovery snapshots will be permanently deleted. This cannot be undone.', confirmText:'Empty Trash', danger:true, input:false});
            if (!yes.confirmed) return;
            try { const r = await api('trash', {method:'POST', body:JSON.stringify({action:'empty'})}); notice(`${r.purged || 0} Trash entr${r.purged === 1 ? 'y' : 'ies'} permanently deleted.`); await renderTrash(); await refreshUndoState(); } catch (e) { notice(e.message,'error'); }
          });
        } catch (e) { notice(e.message,'error'); }
      };
      renderTrash();
    } else if (view === 'versions') {
      Promise.all([api('undo-state'), api('activity')]).then(([undo]) => {
        const ops = undo.recent || [];
        host.innerHTML = `<div class="nobash-files-history-summary"><div><strong>Safety versions</strong><p>Per-file code snapshots are available from the Code Editor.</p><a class="button button-primary" href="admin.php?page=nobash-files-editor">Open Code Editor</a></div><div><strong>Operation Undo</strong><p>${undo.can_undo && undo.last ? `Ready to undo: ${esc(undo.last.label)}` : 'No file operation is currently waiting to be undone.'}</p></div></div><h3 class="nobash-files-subtitle">Recent file operations</h3><div class="nobash-files-activity-list">${ops.length ? ops.map(op => `<div class="nobash-files-activity-row"><span class="dashicons ${op.undone_at ? 'dashicons-yes' : 'dashicons-undo'}"></span><div><strong>${esc(op.label)}</strong><p>${esc(op.relative_path)}</p></div><time>${op.undone_at ? 'Undone' : esc(op.created_at)}</time></div>`).join('') : '<p class="nobash-files-muted">No undoable file operations recorded yet.</p>'}</div>`;
      }).catch(e => notice(e.message, 'error'));
    }
  }

  function initManagerEvents() {
    qs('#nobash-files-select-all')?.addEventListener('change', e => {
      selected.clear();
      if (e.target.checked) currentItems.forEach(i => selected.add(i.path));
      renderFiles(); updateSelectionUI();
    });
    qsa('[data-bulk]').forEach(btn => btn.addEventListener('click', () => bulkAction(btn.dataset.bulk)));
    qs('#nobash-files-list-view')?.addEventListener('click', () => setExplorerView('list'));
    qs('#nobash-files-grid-view')?.addEventListener('click', () => setExplorerView('grid'));
    const sort = qs('#nobash-files-sort');
    if (sort) {
      sort.value = sortMode;
      sort.addEventListener('change', () => {
        sortMode = sort.value;
        writePreference('nfmSortMode', sortMode);
        renderFiles();
      });
    }
    qs('#nobash-files-favorite-folder')?.addEventListener('click', () => toggleFavorite(currentPath, false));
    qs('#nobash-files-show-recent')?.addEventListener('click', () => showSmart('recent'));
    qs('#nobash-files-nav-back')?.addEventListener('click', () => {
      if (navIndex <= 0) return;
      navIndex -= 1;
      loadDir(navHistory[navIndex] || '', false, 'skip').catch(() => { navIndex += 1; updateNavigationButtons(); });
    });
    qs('#nobash-files-nav-forward')?.addEventListener('click', () => {
      if (navIndex >= navHistory.length - 1) return;
      navIndex += 1;
      loadDir(navHistory[navIndex] || '', false, 'skip').catch(() => { navIndex -= 1; updateNavigationButtons(); });
    });
    qs('#nobash-files-toolbar-refresh')?.addEventListener('click', () => loadDir(currentPath, false, 'skip').catch(() => {}));
    qs('#nobash-files-tree-new-folder')?.addEventListener('click', createFolder);
    const more = qs('#nobash-files-manager-more'), moreMenu = qs('#nobash-files-manager-more-menu');
    more?.addEventListener('click', e => { e.stopPropagation(); const open = !moreMenu.hidden; moreMenu.hidden = open; more.setAttribute('aria-expanded', open ? 'false' : 'true'); });
    qsa('[data-manager-more]', moreMenu || document).forEach(btn => btn.addEventListener('click', () => {
      moreMenu.hidden = true; more?.setAttribute('aria-expanded','false');
      if (btn.dataset.managerMore === 'favorite') toggleFavorite(currentPath, false);
      if (btn.dataset.managerMore === 'recent') showSmart('recent');
      if (btn.dataset.managerMore === 'new-folder') createFolder();
    }));
  }

  function initSearchEvents() {
    qs('#nobash-files-search-placeholder')?.addEventListener('click', openSearch);
    qs('#nobash-files-search-close')?.addEventListener('click', closeSearch);
    qs('#nobash-files-search-input')?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(runSearch, 320);
    });
    qs('#nobash-files-search-scope')?.addEventListener('change', runSearch);
    qs('#nobash-files-search-content')?.addEventListener('change', runSearch);
  }


  function applyAccessUI() {
    const bulkPermissions = {download:'download', archive:'archive', extract:'extract', copy:'copy', move:'move', duplicate:'copy', rename:'rename', trash:'delete'};
    qsa('[data-bulk]').forEach(btn => {
      const perm = bulkPermissions[btn.dataset.bulk];
      if (perm && !hasPerm(perm)) btn.hidden = true;
    });
    const search = qs('#nobash-files-search-placeholder');
    if (search && !hasPerm('search')) { search.disabled = true; search.title = 'Search is not allowed by your access profile.'; }
    const undo = qs('#nobash-files-undo');
    if (undo && !hasPerm('undo')) { undo.disabled = true; undo.hidden = true; }
    if (view === 'editor' && !hasPerm('edit')) {
      ['#nobash-files-save','#nobash-files-editor-undo'].forEach(sel => { const el=qs(sel); if(el){el.disabled=true;el.title='Editing is not allowed by your access profile.';} });
    }
    if (view === 'editor' && !hasPerm('versions')) {
      const versions = qs('#nobash-files-versions'); if (versions) versions.innerHTML = '<p class="nobash-files-muted">Version history is not included in your access profile.</p>';
    }
  }

  function initGlobalEvents() {
    const fileView = ['manager','editor'].includes(view);
    qsa('.nobash-files-quick[data-path]').forEach(b => {
      if (NobashFiles.workspaceRoot && !pathWithin(String(b.dataset.path || ''), String(NobashFiles.workspaceRoot || ''))) { b.hidden = true; return; }
      if (!clientPathAllowed(b.dataset.path || '')) { b.hidden = true; return; }
      b.addEventListener('click', () => loadDir(b.dataset.path || accessHome).catch(() => {}));
    });
    qsa('.nobash-files-quick[data-smart]').forEach(b => b.addEventListener('click', () => showSmart(b.dataset.smart)));
    qsa('[data-access-root]').forEach(b => b.addEventListener('click', () => loadDir(b.dataset.accessRoot || accessHome).catch(() => {})));
    qs('#nobash-files-refresh')?.addEventListener('click', () => loadDir(currentPath).catch(() => {}));
    const loadMore = e => { e.currentTarget.disabled = true; loadDir(currentPath, true).catch(() => {}).finally(() => { e.currentTarget.disabled = !directoryHasMore; }); };
    qs('#nobash-files-load-more')?.addEventListener('click', loadMore);
    qs('#nobash-files-tree-load-more')?.addEventListener('click', loadMore);
    if (!fileView) {
      const upload = qs('#nobash-files-upload'), folder = qs('#nobash-files-new-folder');
      if (upload) upload.disabled = true;
      if (folder) folder.disabled = true;
    }
    const uploadButton = qs('#nobash-files-upload');
    const uploadPicker = qs('#nobash-files-upload-picker');
    uploadButton?.addEventListener('click', e => {
      if (!fileView || uploadButton.disabled) return;
      e.stopPropagation();
      const open = uploadPicker?.hidden !== false;
      if (uploadPicker) uploadPicker.hidden = !open;
      uploadButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    qsa('[data-upload-mode]', uploadPicker || document).forEach(btn => btn.addEventListener('click', e => {
      e.stopPropagation();
      if (uploadPicker) uploadPicker.hidden = true;
      uploadButton?.setAttribute('aria-expanded','false');
      const mode = btn.dataset.uploadMode;
      if (mode === 'folder' && !hasPerm('create')) return notice('Folder upload requires permission to create folders.', 'error');
      const input = qs(mode === 'single' ? '#nobash-files-upload-single-input' : mode === 'folder' ? '#nobash-files-upload-folder-input' : '#nobash-files-upload-multi-input');
      input?.click();
    }));
    [['#nobash-files-upload-single-input','single'],['#nobash-files-upload-multi-input','multiple'],['#nobash-files-upload-folder-input','folder']].forEach(([selector, mode]) => {
      qs(selector)?.addEventListener('change', e => {
        const files = [...(e.target.files || [])];
        e.target.value = '';
        if (fileView) uploadFiles(files, false, mode);
      });
    });
    qs('#nobash-files-upload-cancel')?.addEventListener('click', cancelActiveUpload);

    const dropOverlay = qs('#nobash-files-drop-overlay');
    const dragTarget = qs('.nobash-files-files-panel') || root;
    if (fileView && dragTarget) {
      const hasFiles = e => [...(e.dataTransfer?.types || [])].includes('Files');
      dragTarget.addEventListener('dragenter', e => { if (!hasFiles(e) || !hasPerm('upload') || smartView) return; e.preventDefault(); uploadDragDepth++; if (dropOverlay) dropOverlay.hidden = false; });
      dragTarget.addEventListener('dragover', e => { if (!hasFiles(e) || !hasPerm('upload') || smartView) return; e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'; });
      dragTarget.addEventListener('dragleave', e => { if (!hasFiles(e)) return; uploadDragDepth = Math.max(0, uploadDragDepth - 1); if (!uploadDragDepth && dropOverlay) dropOverlay.hidden = true; });
      dragTarget.addEventListener('drop', async e => {
        if (!hasFiles(e) || !hasPerm('upload') || smartView) return;
        e.preventDefault(); uploadDragDepth = 0; if (dropOverlay) dropOverlay.hidden = true;
        try { const dropped = await tasksFromDrop(e.dataTransfer); await uploadFiles(dropped.tasks, false, dropped.mode); }
        catch (err) { notice(err.message || 'Unable to read the dropped files.', 'error'); }
      });
    }
    qs('#nobash-files-new-folder')?.addEventListener('click', createFolder);
    qs('#nobash-files-undo')?.addEventListener('click', undoLast);
    const headMore = qs('#nobash-files-header-more'), headMenu = qs('#nobash-files-header-more-menu');
    headMore?.addEventListener('click', e => { e.stopPropagation(); const open = !headMenu.hidden; headMenu.hidden = open; headMore.setAttribute('aria-expanded', open ? 'false' : 'true'); });
    initSearchEvents();

    document.addEventListener('click', e => {
      if (!e.target.closest('#nobash-files-context-menu,[data-menu-path]')) closeContextMenu();
      if (headMenu && !e.target.closest('.nobash-files-header-overflow')) { headMenu.hidden = true; headMore?.setAttribute('aria-expanded','false'); }
      const uploadPicker = qs('#nobash-files-upload-picker');
      if (uploadPicker && !e.target.closest('.nobash-files-upload-picker-wrap')) { uploadPicker.hidden = true; qs('#nobash-files-upload')?.setAttribute('aria-expanded','false'); }
      const managerMenu = qs('#nobash-files-manager-more-menu');
      if (managerMenu && !e.target.closest('.nobash-files-dropdown-wrap')) { managerMenu.hidden = true; qs('#nobash-files-manager-more')?.setAttribute('aria-expanded','false'); }
    });
    document.addEventListener('keydown', e => {
      const typing = ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName);
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault(); openSearch(); return;
      }
      if (e.key === 'Escape') {
        closeContextMenu();
        if (qs('#nobash-files-search-panel') && !qs('#nobash-files-search-panel').hidden) closeSearch();
        if (qs('#nobash-files-modal') && !qs('#nobash-files-modal').hidden) closeModal(false);
        if (qs('#nobash-files-archive-modal') && !qs('#nobash-files-archive-modal').hidden) closeArchiveModal(null);
        return;
      }
      if (view === 'editor' && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault(); if (hasPerm('edit')) saveCurrent(false); return;
      }
      if (view !== 'manager' || typing) return;
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
        e.preventDefault(); undoLast(); return;
      }
      if (selected.size === 1 && e.key === 'F2') {
        e.preventDefault(); bulkAction('rename'); return;
      }
      if (selected.size === 1 && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
        e.preventDefault(); bulkAction('duplicate'); return;
      }
      if (selected.size && (e.key === 'Delete' || e.key === 'Backspace')) {
        e.preventDefault(); bulkAction('trash');
      }
    });
  }


  function initServerTrashConfirmations() {
    if (view !== 'trash') return;
    const emptyForm = document.querySelector('[data-nobash-files-trash-empty-form]');
    if (emptyForm) {
      emptyForm.addEventListener('submit', async e => {
        if (emptyForm.dataset.confirmed === '1') return;
        e.preventDefault();
        const answer = await showModal({title:'Empty Trash?',message:'All available private Trash recovery snapshots will be permanently deleted. This action cannot be undone.',confirmText:'Empty Trash',danger:true,input:false});
        if (!answer.confirmed) return;
        emptyForm.dataset.confirmed = '1'; emptyForm.submit();
      });
    }
    const tableForm = document.querySelector('[data-nobash-files-trash-table-form]');
    if (!tableForm) return;
    tableForm.addEventListener('click', async e => {
      const button = e.target.closest('[data-nobash-files-trash-delete-row]');
      if (!button || tableForm.dataset.confirmed === '1') return;
      e.preventDefault();
      const answer = await showModal({title:'Delete recovery permanently?',message:'This removes the selected private recovery snapshot. The original item cannot be restored from Nobash Trash after this.',confirmText:'Delete Permanently',danger:true,input:false});
      if (!answer.confirmed) return;
      let hidden = tableForm.querySelector('input[name="row_action"][type="hidden"]');
      if (!hidden) { hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='row_action'; tableForm.appendChild(hidden); }
      hidden.value = button.value; tableForm.dataset.confirmed='1'; tableForm.submit();
    });
    tableForm.addEventListener('submit', async e => {
      if (tableForm.dataset.confirmed === '1') return;
      const bulk = tableForm.querySelector('select[name="bulk_action"]')?.value || '';
      if (bulk !== 'delete') return;
      e.preventDefault();
      const selected = tableForm.querySelectorAll('input[name="op_ids[]"]:checked').length;
      if (!selected) { notice('Select at least one Trash item.','warning'); return; }
      const answer = await showModal({title:'Delete selected recovery items?',message:`${selected} selected Trash entr${selected===1?'y':'ies'} will be permanently deleted. This cannot be undone.`,confirmText:'Delete Permanently',danger:true,input:false});
      if (!answer.confirmed) return;
      tableForm.dataset.confirmed='1'; tableForm.submit();
    });
  }

  simplifyPages();
  window.addEventListener('hashchange', openLinkedPanel);
  initModal();
  initArchiveModal();
  initGlobalEvents();
  initServerTrashConfirmations();
  applyAccessUI();
  loadPreferences();
  if (view === 'manager') {
    initManagerEvents();
    loadDir(NobashFiles.initialPath || NobashFiles.workspaceRoot || accessHome || NobashFiles.defaultWorkspace || '').catch(() => {});
  }
  if (view === 'editor') initEditorEvents();
  if (['safe','settings','activity','versions','trash','access','integrity','security','monitoring','multisite','agency'].includes(view)) initSimple();
  refreshUndoState();
})();
