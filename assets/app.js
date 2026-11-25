(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const csrf = document.querySelector('meta[name=csrf]')?.content || '';

  // ---------- utilitaires ----------
  function toast(msg, err) {
    const t = $('#toast'); if (!t) return alert(msg);
    t.textContent = msg; t.className = 'toast' + (err ? ' err' : ''); t.hidden = false;
    clearTimeout(t._h); t._h = setTimeout(() => (t.hidden = true), 3500);
  }
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); }
    catch { const i = document.createElement('textarea'); i.value = text; document.body.appendChild(i); i.select(); document.execCommand('copy'); i.remove(); }
    toast('Lien copié dans le presse-papiers ✔');
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copy]'); if (b) { e.preventDefault(); copy(b.dataset.copy); }
    const c = e.target.closest('[data-confirm]'); if (c && !confirm(c.dataset.confirm)) e.preventDefault();
  });
  window.Yabiso = { copy, toast };

  const fm = $('#fm'); if (!fm) return;

  // ---------- gestionnaire de fichiers ----------
  const API = fm.dataset.api, DL = fm.dataset.dl, AS = fm.dataset.as, ROOT = fm.dataset.root;
  let cwd = '', items = [], selected = new Set(), maxUpload = 0, trashOn = true, lastList = null, searchQ = '';
  let sort = { key: 'name', dir: 'asc' };
  try { sort = JSON.parse(localStorage.getItem('ys_sort')) || sort; } catch {}

  const human = b => { if (b == null) return '—'; const u = ['o','Ko','Mo','Go','To']; let i = 0; b = +b; while (b >= 1024 && i < 4) { b /= 1024; i++; } return (i ? b.toFixed(b < 10 ? 2 : 1).replace('.', ',') : b) + ' ' + u[i]; };
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const join = (a, b) => (a ? a + '/' : '') + b;
  const fmtDate = t => new Date(t * 1000).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });

  async function api(action, data, method) {
    const params = new URLSearchParams({ action }); if (AS) params.set('as', AS);
    let opts = { method: method || 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' };
    if (opts.method === 'GET') { for (const k in data || {}) params.set(k, data[k]); }
    else {
      const fd = new FormData(); fd.append('csrf', csrf);
      for (const k in data || {}) Array.isArray(data[k]) ? data[k].forEach(v => fd.append(k + '[]', v)) : fd.append(k, data[k]);
      opts.body = fd;
    }
    const r = await fetch(API + '?' + params, opts);
    let j; try { j = await r.json(); } catch { throw new Error('Réponse invalide du serveur (' + r.status + ').'); }
    if (!j.ok) throw new Error(j.error || 'Erreur');
    return j;
  }
  const dlUrl = (p, dl) => DL + '?' + new URLSearchParams(Object.assign({ path: p }, dl ? { dl: 1 } : {}, AS ? { as: AS } : {}));

  const pathOf = it => it.path !== undefined ? it.path : join(cwd, it.name);

  function sortItems() {
    const k = sort.key, m = sort.dir === 'asc' ? 1 : -1;
    items.sort((a, b) => {
      if (a.dir !== b.dir) return a.dir ? -1 : 1;
      if (k === 'name') return m * a.name.localeCompare(b.name, 'fr', { numeric: true, sensitivity: 'base' });
      return m * (((a[k] || 0) - (b[k] || 0)) || a.name.localeCompare(b.name, 'fr', { numeric: true }));
    });
    document.querySelectorAll('th.sortable').forEach(th => (th.dataset.dir = th.dataset.sort === k ? sort.dir : ''));
  }

  async function load(path = cwd) {
    try {
      const j = await api('list', { path }, 'GET');
      searchQ = ''; $('#searchClear').hidden = true;
      cwd = j.path; items = j.items; maxUpload = j.max_upload; trashOn = j.trash_enabled; selected.clear(); lastList = j;
      history.replaceState(null, '', '#/' + cwd.split('/').map(encodeURIComponent).join('/'));
      const tb = $('#trashBtn'); if (tb) tb.textContent = '🗑 Corbeille' + (j.trash_count ? ` (${j.trash_count})` : '');
      sortItems(); render(j);
    } catch (e) { toast(e.message, true); if (path) { cwd = ''; load(''); } }
  }

  async function search(q) {
    try {
      const j = await api('search', { q }, 'GET');
      searchQ = q; items = j.results; selected.clear(); $('#searchClear').hidden = false;
      sortItems(); render(lastList);
    } catch (e) { toast(e.message, true); }
  }
  $('#searchForm').addEventListener('submit', e => { e.preventDefault(); const q = $('#searchInput').value.trim(); q ? search(q) : load(); });
  $('#searchClear').addEventListener('click', () => { $('#searchInput').value = ''; load(); });
  document.querySelectorAll('th.sortable').forEach(th => th.addEventListener('click', () => {
    sort = { key: th.dataset.sort, dir: sort.key === th.dataset.sort && sort.dir === 'asc' ? 'desc' : 'asc' };
    try { localStorage.setItem('ys_sort', JSON.stringify(sort)); } catch {}
    sortItems(); render(lastList);
  }));

  function render(j) {
    const pct = j.quota ? Math.min(100, j.used / j.quota * 100) : 100;
    $('#quota').innerHTML = `<div class="quota"><div class="bar ${pct >= 90 ? 'danger' : pct >= 70 ? 'warn' : ''}"><span style="width:${pct.toFixed(1)}%"></span></div><small>${human(j.used)} utilisés sur ${human(j.quota)} (${pct.toFixed(1).replace('.', ',')} %) · max ${human(j.max_upload)} par fichier</small></div>`;
    const parts = cwd ? cwd.split('/') : [];
    let acc = '', html = `<a href="#/" data-nav="">🏠 ${esc(ROOT)}</a>`;
    if (searchQ) html += `<span>›</span><strong>🔎 ${items.length}${items.length >= 200 ? '+' : ''} résultat(s) pour « ${esc(searchQ)} »</strong>`;
    else for (const p of parts) { acc = join(acc, p); html += `<span>/</span><a href="#" data-nav="${esc(acc)}">${esc(p)}</a>`; }
    $('#crumbs').innerHTML = html;

    const rows = [];
    if (cwd && !searchQ) rows.push(`<tr class="up"><td></td><td class="name" colspan="4"><span class="file-icon" data-ext="dir"></span><a href="#" data-nav="${esc(parts.slice(0, -1).join('/'))}">.. (dossier parent)</a></td></tr>`);
    if (!items.length) rows.push(searchQ ? `<tr><td colspan="5" class="center muted empty">Aucun fichier ni dossier ne correspond à « ${esc(searchQ)} ».</td></tr>`
      : `<tr><td colspan="5" class="center muted empty">Ce dossier est vide.<br>Glissez-déposez des fichiers ici ou utilisez les boutons « Envoyer ».</td></tr>`);
    items.forEach((it, i) => {
      const p = pathOf(it);
      const where = searchQ ? `<br><small class="muted break">/${esc(p.split('/').slice(0, -1).join('/'))}</small>` : '';
      rows.push(`<tr data-i="${i}">
        <td class="chk"><input type="checkbox" data-sel="${i}"></td>
        <td class="name"><span class="file-icon" data-ext="${it.dir ? 'dir' : esc(it.ext)}"></span>
          ${it.dir ? `<a href="#" data-nav="${esc(p)}">${esc(it.name)}</a>` : `<a href="${esc(dlUrl(p))}" target="_blank" rel="noopener">${esc(it.name)}</a>`}
          ${it.shared ? '<span class="tag" title="Un lien public existe">🔗 partagé</span>' : ''}${where}</td>
        <td class="hide-sm">${it.dir ? '—' : human(it.size)}</td>
        <td class="hide-sm">${fmtDate(it.mtime)}</td>
        <td class="right acts">
          <button class="btn sm" data-a="share" title="Obtenir le lien">🔗 Lien</button>
          <a class="btn sm" href="${esc(dlUrl(p, 1))}" title="${it.dir ? 'Télécharger en ZIP' : 'Télécharger'}">⬇</a>
          <details class="menu"><summary class="btn sm" title="Plus d’actions">⋯</summary><div>
            ${it.editable ? '<button data-a="edit">✏️ Modifier</button>' : ''}
            <button data-a="rename">✎ Renommer</button>
            <button data-a="duplicate">⧉ Dupliquer</button>
            <button data-a="move">↪ Déplacer</button>
            <button data-a="copy">📋 Copier vers…</button>
            ${searchQ ? '<button data-a="reveal">📂 Ouvrir le dossier</button>' : ''}
            <button data-a="delete" class="danger">🗑 ${trashOn ? 'Mettre à la corbeille' : 'Supprimer'}</button>
          </div></details>
        </td></tr>`);
    });
    $('#list').innerHTML = rows.join('');
    $('#chkall').checked = false; updateSel();
  }

  function updateSel() {
    $('#selbar').hidden = !selected.size;
    $('#selcount').textContent = selected.size + ' élément(s) sélectionné(s)';
  }

  // ---------- boîtes de dialogue ----------
  const dlg = $('#dlg');
  function dialog({ title, body, buttons }) {
    return new Promise(res => {
      $('#dlgTitle').textContent = title; $('#dlgBody').innerHTML = body || '';
      $('#dlgActions').innerHTML = buttons.map(b => `<button value="${b.value}" class="btn ${b.cls || ''}">${esc(b.label)}</button>`).join('');
      dlg.onclose = () => res(dlg.returnValue);
      dlg.returnValue = 'cancel'; dlg.showModal();
      const inp = $('#dlgBody input, #dlgBody select'); if (inp) { inp.focus(); if (inp.select) { const v = inp.value, dot = v.lastIndexOf('.'); inp.setSelectionRange(0, dot > 0 && inp.dataset.file ? dot : v.length); } }
    });
  }
  async function ask(title, label, value = '', isFile = false) {
    const r = await dialog({ title, body: `<label class="form">${esc(label)}<input id="dlgInput" value="${esc(value)}" ${isFile ? 'data-file="1"' : ''} required></label>`,
      buttons: [{ value: 'cancel', label: 'Annuler' }, { value: 'ok', label: 'Valider', cls: 'primary' }] });
    return r === 'ok' ? $('#dlgInput').value.trim() : null;
  }
  async function confirmDlg(title, msg, okLabel = 'Supprimer') {
    return (await dialog({ title, body: `<p>${msg}</p>`, buttons: [{ value: 'cancel', label: 'Annuler' }, { value: 'ok', label: okLabel, cls: 'danger' }] })) === 'ok';
  }
  async function deletePaths(ps, label) {
    const msg = trashOn ? `Mettre ${label} à la corbeille ? Vous pourrez le restaurer pendant quelques jours.` : `Supprimer définitivement ${label} ?`;
    if (!(await confirmDlg(trashOn ? 'Mettre à la corbeille' : 'Supprimer', msg, trashOn ? 'Mettre à la corbeille' : 'Supprimer'))) return;
    await api('delete', { paths: ps });
    toast(trashOn ? 'Déplacé dans la corbeille.' : 'Supprimé.');
    searchQ ? search(searchQ) : load();
  }
  dlg.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.id === 'dlgInput') { e.preventDefault(); dlg.close('ok'); } });

  async function shareDlg(path, isDir, j) {
    try {
      j = j || await api('share', { path });
      const o = j.options;
      const row = (lab, val, help) => `<div class="linkbox"><label>${lab}</label><div class="copyrow"><input readonly value="${esc(val)}" onclick="this.select()"><button type="button" class="btn" data-copy="${esc(val)}">Copier</button></div>${help ? `<small class="muted">${help}</small>` : ''}</div>`;
      const note = j.sandboxed ? ' Le HTML est servi en mode isolé (sécurité) : scripts autorisés, mais pas de cookies/localStorage.' : '';
      const keyNote = j.key ? ' Contient la clé d’accès : fonctionne sans saisir le mot de passe.' : '';
      let body = '';
      if (j.blocked) body += `<div class="alert warn">⚠ ${esc(j.blocked)}</div>`;
      body += row('Lien de partage (page de téléchargement)', j.url, 'À envoyer à vos testeurs / clients.' + (o.has_password ? ' Le mot de passe sera demandé.' : ''));
      if (j.is_dir) {
        if (j.site) body += row('🌐 Lien web du dossier (ouvre index.html)', j.site, 'Pour une WebView ou un site : les fichiers CSS/JS/images du dossier sont chargés automatiquement.' + note + keyNote);
        body += row('Adresse de base des fichiers (lecture directe)', j.files_base, 'Ajoutez le chemin du fichier à la fin, ex. : ' + esc(j.files_base) + 'data/config.json' + (j.key ? ' (ajoutez aussi ?k=' + esc(j.key) + ')' : ''));
        body += `<small class="muted">Toute personne ayant ces liens peut voir et télécharger le contenu du dossier (y compris en ZIP).</small>`;
      } else {
        if (j.view) body += row('👁 Lien de lecture directe (affichage)', j.view, 'S’ouvre directement dans le navigateur, une WebView ou via fetch()/HTTP (JSON, HTML, images…).' + (/\.(html?|svg)$/i.test(j.name) ? note : '') + keyNote);
        body += row('⬇ Lien de téléchargement direct', j.direct, 'Force le téléchargement (idéal pour un APK).' + keyNote);
      }
      body += `<details class="share-opts" ${j.blocked || o.has_password || o.expires_at || o.max_downloads !== '' || o.disabled ? 'open' : ''}><summary>⚙️ Options du lien</summary>
        <div class="status-line"><span class="tag">⬇ ${o.downloads} téléchargement(s)</span><span class="tag">👁 ${o.views} lecture(s)</span>${o.has_password ? '<span class="tag">🔒 protégé</span>' : ''}</div>
        <div class="grid">
          <label>Note (privée)<input id="soNote" value="${esc(o.note)}" placeholder="ex. Build envoyé au client"></label>
          <label>Expire le<input type="datetime-local" id="soExp" value="${esc(o.expires_at)}"></label>
          <label>Téléchargements max<input type="number" min="1" id="soMax" value="${esc(o.max_downloads)}" placeholder="illimité"></label>
          <label>${o.has_password ? 'Nouveau mot de passe' : 'Mot de passe'}<input type="text" id="soPw" placeholder="${o.has_password ? 'inchangé' : 'aucun'}" autocomplete="off"></label>
        </div>
        <div class="checks">
          ${o.has_password ? '<label><input type="checkbox" id="soNoPw"> Retirer le mot de passe</label>' : ''}
          ${o.max_downloads !== '' ? '<label><input type="checkbox" id="soReset"> Remettre le compteur de téléchargements à zéro</label>' : ''}
          <label><input type="checkbox" id="soDis" ${o.disabled ? 'checked' : ''}> Désactiver temporairement ce lien</label>
        </div>
        <div class="dlg-actions"><button type="button" class="btn" id="soExpQuick" data-days="7">Expire dans 7 jours</button><button type="button" class="btn primary" id="soSave">Enregistrer les options</button></div>
      </details>`;
      const pending = dialog({ title: '🔗 Lien de « ' + j.name + ' »', body,
        buttons: [{ value: 'unshare', label: 'Supprimer le lien', cls: 'danger' }, { value: 'ok', label: 'Fermer', cls: 'primary' }] });
      $('#soExpQuick').onclick = () => { const d = new Date(Date.now() + 7 * 864e5); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); $('#soExp').value = d.toISOString().slice(0, 16); };
      $('#soSave').onclick = async () => {
        try {
          const nj = await api('share_options', { path, note: $('#soNote').value, expires_at: $('#soExp').value, max_downloads: $('#soMax').value,
            password: $('#soPw').value, remove_password: $('#soNoPw')?.checked ? 1 : 0, reset_counter: $('#soReset')?.checked ? 1 : 0, disabled: $('#soDis').checked ? 1 : 0 });
          toast('Options du lien enregistrées ✔');
          // Rouvre le dialogue avec les nouvelles valeurs une fois l'ancien fermé.
          dlg.onclose = null;
          dlg.addEventListener('close', () => shareDlg(path, isDir, nj), { once: true });
          dlg.close('reopen');
        } catch (x) { toast(x.message, true); }
      };
      const r = await pending;
      if (r === 'unshare' && await confirmDlg('Supprimer le lien', 'Le lien ne fonctionnera plus pour personne. Continuer ?')) { await api('unshare', { path }); toast('Lien supprimé.'); }
      searchQ ? search(searchQ) : load();
    } catch (e) { toast(e.message, true); }
  }

  async function moveDlg(paths, copy) {
    try {
      const j = await api('tree', {}, 'GET');
      const opts = j.dirs.filter(d => !paths.some(p => d === p || d.startsWith(p + '/')))
        .map(d => `<option value="${esc(d)}" ${d === cwd && !copy ? 'disabled' : ''} ${d === cwd && copy ? 'selected' : ''}>${esc('/' + d)}${d === '' ? ' (racine)' : ''}</option>`).join('');
      const verb = copy ? 'Copier' : 'Déplacer';
      const r = await dialog({ title: verb + ' ' + paths.length + ' élément(s)', body: `<label class="form">Destination<select id="dlgSel">${opts}</select></label>`,
        buttons: [{ value: 'cancel', label: 'Annuler' }, { value: 'ok', label: verb, cls: 'primary' }] });
      if (r !== 'ok') return;
      await api(copy ? 'copy' : 'move', { paths, dest: $('#dlgSel').value });
      toast(copy ? 'Copie effectuée ✔' : 'Déplacement effectué.'); searchQ ? search(searchQ) : load();
    } catch (e) { toast(e.message, true); }
  }

  // ---------- éditeur ----------
  const ed = $('#editor'); let edPath = null, edCreate = false;
  async function openEditor(path, create) {
    try {
      const content = create ? '' : (await api('read', { path }, 'GET')).content;
      edPath = path; edCreate = create;
      $('#edTitle').textContent = '✏️ ' + path; $('#edText').value = content; ed.showModal(); $('#edText').focus();
    } catch (e) { toast(e.message, true); }
  }
  $('#edSave').addEventListener('click', async e => {
    e.preventDefault();
    try { await api('save', { path: edPath, content: $('#edText').value, create: edCreate ? 1 : 0 }); edCreate = false; toast('Fichier enregistré ✔'); load(); }
    catch (err) { toast(err.message, true); }
  });
  $('#edText').addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); $('#edSave').click(); }
    if (e.key === 'Tab') { e.preventDefault(); const t = e.target, s = t.selectionStart; t.setRangeText('  ', s, t.selectionEnd, 'end'); }
  });

  // ---------- actions ----------
  fm.addEventListener('click', async e => {
    const nav = e.target.closest('[data-nav]');
    if (nav) { e.preventDefault(); return load(nav.dataset.nav); }
    const tb = e.target.closest('[data-act]');
    if (tb) {
      const a = tb.dataset.act;
      if (a === 'mkdir') { const n = await ask('Nouveau dossier', 'Nom du dossier'); if (n) try { await api('mkdir', { path: cwd, name: n }); load(); } catch (x) { toast(x.message, true); } }
      if (a === 'newfile') { const n = await ask('Nouveau fichier texte', 'Nom du fichier (ex. config.json)', 'nouveau.txt', true); if (n) openEditor(join(cwd, n), true); }
      if (a === 'sharecur') shareDlg(cwd, true);
      if (a === 'clearsel') { selected.clear(); document.querySelectorAll('[data-sel]').forEach(c => (c.checked = false)); $('#chkall').checked = false; updateSel(); }
      if (a === 'delsel') { const ps = [...selected].map(i => pathOf(items[i])); try { await deletePaths(ps, ps.length + ' élément(s)'); } catch (x) { toast(x.message, true); } }
      if (a === 'movesel') moveDlg([...selected].map(i => pathOf(items[i])));
      if (a === 'copysel') moveDlg([...selected].map(i => pathOf(items[i])), true);
      return;
    }
    const btn = e.target.closest('[data-a]'); if (!btn) return;
    const tr = btn.closest('tr'); const it = items[+tr.dataset.i]; const p = pathOf(it);
    const parent = p.split('/').slice(0, -1).join('/');
    btn.closest('details')?.removeAttribute('open');
    try {
      switch (btn.dataset.a) {
        case 'share': return shareDlg(p, it.dir);
        case 'edit': return openEditor(p, false);
        case 'move': return moveDlg([p]);
        case 'copy': return moveDlg([p], true);
        case 'duplicate': { const r = await api('copy', { paths: [p], dest: parent }); toast('Copie créée : ' + r.names[0]); searchQ ? search(searchQ) : load(); return; }
        case 'reveal': $('#searchInput').value = ''; return load(parent);
        case 'rename': { const n = await ask('Renommer', 'Nouveau nom', it.name, !it.dir); if (n && n !== it.name) { await api('rename', { path: p, name: n }); toast('Renommé ✔'); searchQ ? search(searchQ) : load(); } return; }
        case 'delete': return deletePaths([p], `« ${esc(it.name)} »${it.dir ? ' et tout son contenu' : ''}`);
      }
    } catch (x) { toast(x.message, true); }
  });
  fm.addEventListener('change', e => {
    if (e.target.dataset.sel !== undefined) { const i = +e.target.dataset.sel; e.target.checked ? selected.add(i) : selected.delete(i); updateSel(); }
    if (e.target.id === 'chkall') { document.querySelectorAll('[data-sel]').forEach(c => { c.checked = e.target.checked; const i = +c.dataset.sel; c.checked ? selected.add(i) : selected.delete(i); }); updateSel(); }
  });
  document.addEventListener('click', e => { document.querySelectorAll('details.menu[open]').forEach(d => { if (!d.contains(e.target)) d.removeAttribute('open'); }); });

  // ---------- envoi ----------
  const queue = []; let running = false;
  function enqueue(file, relpath) {
    if (maxUpload && file.size > maxUpload) { toast(`« ${file.name} » dépasse la taille maximale (${human(maxUpload)}).`, true); return; }
    const el = document.createElement('div'); el.className = 'up-item';
    el.innerHTML = `<span class="up-name">${esc(relpath || file.name)}</span><span class="up-bar"><span></span></span><span class="up-st">En attente</span>`;
    $('#uploads').hidden = false; $('#uploads').appendChild(el);
    queue.push({ file, relpath, el, dir: cwd });
    if (!running) runQueue();
  }
  async function runQueue() {
    running = true;
    while (queue.length) {
      const job = queue.shift();
      await new Promise(done => {
        const fd = new FormData(); fd.append('csrf', csrf); fd.append('path', job.dir); fd.append('file', job.file);
        if (job.relpath) fd.append('relpath', job.relpath);
        const x = new XMLHttpRequest();
        x.open('POST', API + '?action=upload' + (AS ? '&as=' + AS : ''));
        x.setRequestHeader('X-CSRF-Token', csrf);
        x.upload.onprogress = ev => { if (ev.lengthComputable) { const p = ev.loaded / ev.total * 100; job.el.querySelector('.up-bar span').style.width = p + '%'; job.el.querySelector('.up-st').textContent = Math.round(p) + ' %'; } };
        x.onload = () => { let j = {}; try { j = JSON.parse(x.responseText); } catch {}
          if (j.ok) { job.el.classList.add('done'); job.el.querySelector('.up-st').textContent = '✔'; }
          else { job.el.classList.add('fail'); job.el.querySelector('.up-st').textContent = '✖ ' + (j.error || ('Erreur ' + x.status)); }
          done(); };
        x.onerror = () => { job.el.classList.add('fail'); job.el.querySelector('.up-st').textContent = '✖ Réseau'; done(); };
        x.send(fd);
      });
    }
    running = false; load();
    setTimeout(() => { if (!running) { document.querySelectorAll('.up-item.done').forEach(e => e.remove()); if (!$('#uploads').children.length) $('#uploads').hidden = true; } }, 4000);
  }
  $('#upFiles').addEventListener('change', e => { [...e.target.files].forEach(f => enqueue(f)); e.target.value = ''; });
  $('#upDir').addEventListener('change', e => { [...e.target.files].forEach(f => enqueue(f, f.webkitRelativePath || f.name)); e.target.value = ''; });

  const dz = $('#dropzone');
  ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); if (ev === 'dragleave' && dz.contains(e.relatedTarget)) return; dz.classList.remove('over'); }));
  dz.addEventListener('drop', e => {
    const entries = [...(e.dataTransfer.items || [])].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
    if (!entries.length) { [...e.dataTransfer.files].forEach(f => enqueue(f)); return; }
    const walk = (entry, prefix) => {
      if (entry.isFile) entry.file(f => enqueue(f, prefix + f.name));
      else if (entry.isDirectory) {
        const r = entry.createReader();
        const readAll = () => r.readEntries(list => { if (!list.length) return; list.forEach(en => walk(en, prefix + entry.name + '/')); readAll(); });
        readAll();
      }
    };
    entries.forEach(en => walk(en, ''));
  });

  window.addEventListener('hashchange', () => { const p = decodeURIComponent(location.hash.replace(/^#\/?/, '')); if (p !== cwd) load(p); });
  load(decodeURIComponent(location.hash.replace(/^#\/?/, '')));
})();

// ---------- Administration : filtre des membres ----------
(() => {
  const f = document.getElementById('memberFilter'), r = document.getElementById('memberRole');
  if (!f) return;
  const apply = () => {
    const q = f.value.trim().toLowerCase(), role = r.value;
    document.querySelectorAll('table.users tbody tr').forEach(tr => {
      const okQ = !q || tr.dataset.search.includes(q);
      const okR = !role || (role === 'inactive' ? tr.dataset.active === '0' : tr.dataset.role === role);
      tr.hidden = !(okQ && okR);
    });
  };
  f.addEventListener('input', apply); r.addEventListener('change', apply);
})();
