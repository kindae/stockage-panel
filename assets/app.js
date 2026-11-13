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
  let cwd = '', items = [], selected = new Set(), maxUpload = 0;

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

  async function load(path = cwd) {
    try {
      const j = await api('list', { path }, 'GET');
      cwd = j.path; items = j.items; maxUpload = j.max_upload; selected.clear();
      history.replaceState(null, '', '#/' + cwd.split('/').map(encodeURIComponent).join('/'));
      render(j);
    } catch (e) { toast(e.message, true); if (path) { cwd = ''; load(''); } }
  }

  function render(j) {
    const pct = j.quota ? Math.min(100, j.used / j.quota * 100) : 100;
    $('#quota').innerHTML = `<div class="quota"><div class="bar ${pct >= 90 ? 'danger' : pct >= 70 ? 'warn' : ''}"><span style="width:${pct.toFixed(1)}%"></span></div><small>${human(j.used)} utilisés sur ${human(j.quota)} (${pct.toFixed(1).replace('.', ',')} %) · max ${human(j.max_upload)} par fichier</small></div>`;
    const parts = cwd ? cwd.split('/') : [];
    let acc = '', html = `<a href="#/" data-nav="">🏠 ${esc(ROOT)}</a>`;
    for (const p of parts) { acc = join(acc, p); html += `<span>/</span><a href="#" data-nav="${esc(acc)}">${esc(p)}</a>`; }
    $('#crumbs').innerHTML = html;

    const rows = [];
    if (cwd) rows.push(`<tr class="up"><td></td><td class="name" colspan="4"><span class="file-icon" data-ext="dir"></span><a href="#" data-nav="${esc(parts.slice(0, -1).join('/'))}">.. (dossier parent)</a></td></tr>`);
    if (!items.length) rows.push(`<tr><td colspan="5" class="center muted empty">Ce dossier est vide.<br>Glissez-déposez des fichiers ici ou utilisez les boutons « Envoyer ».</td></tr>`);
    items.forEach((it, i) => {
      const p = join(cwd, it.name);
      rows.push(`<tr data-i="${i}">
        <td class="chk"><input type="checkbox" data-sel="${i}"></td>
        <td class="name"><span class="file-icon" data-ext="${it.dir ? 'dir' : esc(it.ext)}"></span>
          ${it.dir ? `<a href="#" data-nav="${esc(p)}">${esc(it.name)}</a>` : `<a href="${esc(dlUrl(p))}" target="_blank" rel="noopener">${esc(it.name)}</a>`}
          ${it.shared ? '<span class="tag" title="Un lien public existe">🔗 partagé</span>' : ''}</td>
        <td class="hide-sm">${it.dir ? '—' : human(it.size)}</td>
        <td class="hide-sm">${fmtDate(it.mtime)}</td>
        <td class="right acts">
          <button class="btn sm" data-a="share" title="Obtenir le lien">🔗 Lien</button>
          <a class="btn sm" href="${esc(dlUrl(p, 1))}" title="${it.dir ? 'Télécharger en ZIP' : 'Télécharger'}">⬇</a>
          <details class="menu"><summary class="btn sm" title="Plus d’actions">⋯</summary><div>
            ${it.editable ? '<button data-a="edit">✏️ Modifier</button>' : ''}
            <button data-a="rename">✎ Renommer</button>
            <button data-a="move">↪ Déplacer</button>
            <button data-a="delete" class="danger">🗑 Supprimer</button>
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
  async function confirmDlg(title, msg) {
    return (await dialog({ title, body: `<p>${msg}</p>`, buttons: [{ value: 'cancel', label: 'Annuler' }, { value: 'ok', label: 'Supprimer', cls: 'danger' }] })) === 'ok';
  }
  dlg.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.id === 'dlgInput') { e.preventDefault(); dlg.close('ok'); } });

  async function shareDlg(path, isDir) {
    try {
      const j = await api('share', { path });
      const row = (lab, val, help) => `<div class="linkbox"><label>${lab}</label><div class="copyrow"><input readonly value="${esc(val)}" onclick="this.select()"><button type="button" class="btn" data-copy="${esc(val)}">Copier</button></div>${help ? `<small class="muted">${help}</small>` : ''}</div>`;
      const body = row('Lien de partage (page de téléchargement)', j.url, 'À envoyer à vos testeurs / clients.')
        + (j.is_dir ? `<small class="muted">Toute personne ayant ce lien peut voir et télécharger le contenu du dossier (y compris en ZIP).</small>`
                    : row('Lien direct (téléchargement immédiat)', j.direct, 'Idéal pour un APK, un JSON ou à utiliser dans votre application.'));
      const r = await dialog({ title: '🔗 Lien de « ' + j.name + ' »', body,
        buttons: [{ value: 'unshare', label: 'Désactiver le lien', cls: 'danger' }, { value: 'ok', label: 'Fermer', cls: 'primary' }] });
      if (r === 'unshare') { await api('unshare', { path }); toast('Lien désactivé.'); }
      load();
    } catch (e) { toast(e.message, true); }
  }

  async function moveDlg(paths) {
    try {
      const j = await api('tree', {}, 'GET');
      const opts = j.dirs.filter(d => !paths.some(p => d === p || d.startsWith(p + '/')))
        .map(d => `<option value="${esc(d)}" ${d === cwd ? 'disabled' : ''}>${esc('/' + d)}${d === '' ? ' (racine)' : ''}</option>`).join('');
      const r = await dialog({ title: 'Déplacer ' + paths.length + ' élément(s)', body: `<label class="form">Destination<select id="dlgSel">${opts}</select></label>`,
        buttons: [{ value: 'cancel', label: 'Annuler' }, { value: 'ok', label: 'Déplacer', cls: 'primary' }] });
      if (r !== 'ok') return;
      await api('move', { paths, dest: $('#dlgSel').value }); toast('Déplacement effectué.'); load();
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
      if (a === 'delsel') { const ps = [...selected].map(i => join(cwd, items[i].name)); if (await confirmDlg('Supprimer', `Supprimer définitivement ${ps.length} élément(s) ?`)) try { await api('delete', { paths: ps }); toast('Supprimé.'); load(); } catch (x) { toast(x.message, true); } }
      if (a === 'movesel') moveDlg([...selected].map(i => join(cwd, items[i].name)));
      return;
    }
    const btn = e.target.closest('[data-a]'); if (!btn) return;
    const tr = btn.closest('tr'); const it = items[+tr.dataset.i]; const p = join(cwd, it.name);
    btn.closest('details')?.removeAttribute('open');
    try {
      switch (btn.dataset.a) {
        case 'share': return shareDlg(p, it.dir);
        case 'edit': return openEditor(p, false);
        case 'move': return moveDlg([p]);
        case 'rename': { const n = await ask('Renommer', 'Nouveau nom', it.name, !it.dir); if (n && n !== it.name) { await api('rename', { path: p, name: n }); toast('Renommé ✔'); load(); } return; }
        case 'delete': if (await confirmDlg('Supprimer', `Supprimer définitivement « ${esc(it.name)} »${it.dir ? ' et tout son contenu' : ''} ?`)) { await api('delete', { paths: [p] }); toast('Supprimé.'); load(); } return;
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
