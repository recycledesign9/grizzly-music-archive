/* ============================================================
   instant-mix.js
   Pagina Instant Mix: criteri, conteggio live, generazione,
   riproduzione nel player esistente e salvataggio come playlist.

   Usa solo l'API pubblica già esistente:
   - Player.load(), Player.enqueue(), Player.currentTrackId() (app.js)
   - PlaylistPlayer.clearContext() (playlist-player.js)
   - #sp-context / #sp-context-name nel player sticky (footer.php)
   - POST playlists/add-tracks con playlist_id=new (PlaylistController)

   La pagina dichiara gli endpoint in data-attribute su #instant-mix.
   init() è idempotente: si aggancia una volta per ogni pagina caricata,
   sia al primo caricamento sia dopo la navigazione SPA.
   ============================================================ */

var InstantMix = (function () {
  'use strict';

  var root = null;
  var state = null;
  var CONTEXT_PREFIX = 'Instant Mix · ';

  // Ultimo mix generato, conservato nel browser: tornando sulla pagina
  // (anche dopo averla lasciata o dopo un riavvio) il mix ricompare con
  // le stesse tracce, comprese quelle tolte a mano, e si può ancora
  // ascoltare o salvare come playlist. Un nuovo "Genera mix" lo sostituisce.
  var STORE_KEY = 'grz.instantMix.last';
  var STORE_VERSION = 1;

  function storeLast() {
    if (!state || !state.mix) return;
    var payload = {
      v: STORE_VERSION,
      savedAt: state.generatedAt || Date.now(),
      mix: {
        criteria: state.mix.criteria,
        label: state.mix.label,
        stats: state.mix.stats,
        alternatives: state.mix.alternatives || []
      },
      tracks: state.tracks,
      savedAs: state.savedAs || null
    };
    try { localStorage.setItem(STORE_KEY, JSON.stringify(payload)); } catch (e) { /* storage non disponibile */ }
  }

  function readLast() {
    var raw = null;
    try { raw = localStorage.getItem(STORE_KEY); } catch (e) { return null; }
    if (!raw) return null;
    try {
      var data = JSON.parse(raw);
      if (!data || data.v !== STORE_VERSION || !data.mix || !Array.isArray(data.tracks)) return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function sameCriteria(a, b) {
    if (!a || !b) return false;
    return (a.g || []).join(',') === (b.g || []).join(',') &&
      (a.from || null) === (b.from || null) &&
      (a.to || null) === (b.to || null) &&
      a.n === b.n && a.order === b.order;
  }

  function whenLabel(ts) {
    var d = new Date(ts);
    var now = new Date();
    var hh = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    var dayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    if (ts >= dayStart) return 'oggi alle ' + hh;
    if (ts >= dayStart - 86400000) return 'ieri alle ' + hh;
    return d.toLocaleDateString('it-IT', { day: 'numeric', month: 'long' }) + ' alle ' + hh;
  }

  // ── Utility ─────────────────────────────────────────────────
  function q(sel, ctx) { return (ctx || root).querySelector(sel); }
  function qa(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }

  function icon(name) {
    var i = el('i', 'bi ' + name);
    i.setAttribute('aria-hidden', 'true');
    return i;
  }

  function newSeed() {
    var bytes = new Uint8Array(3);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    return Array.prototype.map.call(bytes, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
  }

  function fmtTrack(sec) {
    if (sec === null || sec === undefined) return '';
    sec = Math.floor(sec);
    return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
  }

  function fmtTotal(sec) {
    var min = Math.round(sec / 60);
    if (min < 60) return min + ' min';
    var h = Math.floor(min / 60);
    var m = min % 60;
    return h + ' h' + (m ? ' ' + m + ' min' : '');
  }

  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  // ── Criteri ─────────────────────────────────────────────────
  function readCriteria() {
    var g = qa('[data-mix-genres] input[name="g"]:checked').map(function (i) { return parseInt(i.value, 10); });
    var from = q('input[name="from"]').value.trim();
    var to = q('input[name="to"]').value.trim();
    var nEl = q('input[name="n"]:checked');
    var oEl = q('input[name="order"]:checked');
    return {
      g: g,
      from: from === '' ? null : parseInt(from, 10),
      to: to === '' ? null : parseInt(to, 10),
      n: nEl ? parseInt(nEl.value, 10) : 20,
      order: oEl ? oEl.value : 'random'
    };
  }

  function toQuery(c, seed) {
    var p = new URLSearchParams();
    if (c.g && c.g.length) p.set('g', c.g.join(','));
    if (c.from !== null && !isNaN(c.from)) p.set('from', String(c.from));
    if (c.to !== null && !isNaN(c.to)) p.set('to', String(c.to));
    p.set('n', String(c.n));
    p.set('order', c.order);
    if (seed) p.set('seed', seed);
    return p.toString();
  }

  function withQuery(url, query) {
    return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
  }

  // Applica criteri ai controlli (usato dalle alternative proposte)
  function applyCriteria(c) {
    var ids = (c.g || []).map(String);
    var genreBox = q('[data-mix-genres]');
    var selectedChips = [];
    qa('[data-mix-genres] input[name="g"]').forEach(function (i) {
      i.checked = ids.indexOf(i.value) !== -1;
      if (i.checked) selectedChips.push(i.closest('.grz-mix-chip'));
    });
    // Generi selezionati in testa, come nella pagina generata dal server
    if (genreBox) {
      selectedChips.reverse().forEach(function (chip) { genreBox.insertBefore(chip, genreBox.firstChild); });
      genreBox.scrollTop = 0;
    }
    q('input[name="from"]').value = c.from !== null && c.from !== undefined ? c.from : '';
    q('input[name="to"]').value = c.to !== null && c.to !== undefined ? c.to : '';
    if (c.n) {
      var nInput = q('input[name="n"][value="' + parseInt(c.n, 10) + '"]');
      if (nInput) nInput.checked = true;
    }
    if (c.order) {
      var oInput = q('input[name="order"][value="' + (c.order === 'chrono' ? 'chrono' : 'random') + '"]');
      if (oInput) oInput.checked = true;
    }
    syncControls();
  }

  function syncControls() {
    var c = readCriteria();

    qa('[data-mix-decades] [data-from]').forEach(function (b) {
      var on = c.from === parseInt(b.dataset.from, 10) && c.to === parseInt(b.dataset.to, 10);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.classList.toggle('is-on', on);
    });

    qa('[data-mix-genres] .grz-mix-chip').forEach(function (chip) {
      var input = chip.querySelector('input');
      chip.classList.toggle('is-on', !!(input && input.checked));
    });

    var clearG = q('[data-mix-clear-genres]');
    if (clearG) clearG.hidden = c.g.length === 0;
    var clearY = q('[data-mix-clear-years]');
    if (clearY) clearY.hidden = c.from === null && c.to === null;
  }

  // L'URL segue i criteri, così la pagina si può ricaricare o salvare
  // tra i preferiti. Il seed entra solo quando c'è un mix generato.
  function updateUrl(seed, push) {
    var url = withQuery(root.dataset.pageUrl, toQuery(readCriteria(), seed));
    try {
      if (push) history.pushState({ url: url }, '', url);
      else history.replaceState({ url: url }, '', url);
    } catch (e) { /* history non disponibile */ }
  }

  // ── Conteggio live ──────────────────────────────────────────
  function scheduleCount() {
    if (state.countTimer) clearTimeout(state.countTimer);
    state.countTimer = setTimeout(refreshCount, 250);
  }

  function refreshCount() {
    var box = q('[data-mix-count]');
    if (!box) return;
    var c = readCriteria();
    var reqId = ++state.countReq;

    fetch(withQuery(root.dataset.countUrl, toQuery(c, null)), {
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (reqId !== state.countReq || !document.body.contains(box)) return;
        if (!data || !data.ok) { box.textContent = ''; return; }

        box.textContent = '';
        box.classList.toggle('is-empty', data.playable === 0);
        box.classList.toggle('is-short', data.playable > 0 && data.playable < c.n);

        var strong = el('strong', null, String(data.playable));
        box.appendChild(strong);
        box.appendChild(document.createTextNode(
          data.playable === 1 ? ' traccia ascoltabile corrisponde' : ' tracce ascoltabili corrispondono'
        ));

        var noAudio = data.catalog - data.playable;
        if (noAudio > 0) {
          box.appendChild(el('span', 'grz-mix-count__extra',
            (noAudio === 1 ? 'un\'altra' : 'altre ' + noAudio) +
            ' a catalogo, senza audio o sotto i 45 secondi'));
        }
      })
      .catch(function () {
        if (reqId === state.countReq) box.textContent = '';
      });
  }

  // ── Generazione ─────────────────────────────────────────────
  function generate(seed, fromInit) {
    if (state.busy) return;
    state.busy = true;

    var btn = q('[data-mix-generate]');
    var original = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Genero…';
    }

    var c = readCriteria();
    var resultBox = q('[data-mix-result]');

    fetch(withQuery(root.dataset.generateUrl, toQuery(c, seed)), {
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!document.body.contains(resultBox)) return;
        if (!data || !data.ok) throw new Error((data && data.error) || 'Errore');

        state.mix = data;
        state.tracks = data.tracks.slice();
        state.seed = data.criteria.seed;
        state.generatedAt = Date.now();
        state.savedAs = null;
        state.restored = false;
        updateUrl(state.seed, !fromInit);
        render();
        storeLast();
      })
      .catch(function (err) {
        console.warn('[InstantMix]', err.message);
        if (!document.body.contains(resultBox)) return;
        resultBox.textContent = '';
        var box = el('div', 'grz-mix-notice grz-mix-notice--error');
        box.appendChild(icon('bi-exclamation-triangle'));
        box.appendChild(el('span', null, 'Non è stato possibile generare il mix. Riprova tra poco.'));
        resultBox.appendChild(box);
      })
      .then(function () {
        state.busy = false;
        if (btn && document.body.contains(btn)) {
          btn.disabled = false;
          btn.innerHTML = original;
        }
      });
  }

  // ── Render del risultato ────────────────────────────────────
  function render() {
    var box = q('[data-mix-result]');
    var mix = state.mix;
    var tracks = state.tracks;
    box.textContent = '';

    if (!tracks.length) {
      box.appendChild(renderEmpty(mix));
      return;
    }

    // Testata
    var head = el('div', 'grz-mix-res__head');
    var titles = el('div', 'grz-mix-res__titles');
    titles.appendChild(el('p', 'grz-mix-res__kicker', 'Instant Mix'));
    titles.appendChild(el('h2', 'grz-mix-res__title', mix.label));
    titles.appendChild(el('p', 'grz-mix-res__meta', metaLine(tracks)));
    head.appendChild(titles);

    var actions = el('div', 'grz-mix-res__actions');
    actions.appendChild(actionBtn('btn btn-warning fw-semibold', 'bi-play-fill', 'Ascolta', function () { playFrom(0); }));
    actions.appendChild(actionBtn('btn btn-outline-secondary', 'bi-plus-lg', 'Coda', enqueueAll, 'Aggiungi il mix in fondo alla coda'));
    actions.appendChild(actionBtn('btn btn-outline-secondary', 'bi-arrow-repeat', 'Rigenera', function () { generate(newSeed(), false); }, 'Nuova selezione con gli stessi criteri'));
    if (state.savedAs) {
      // Già salvato: il pulsante porta alla playlist, niente doppioni
      var open = el('a', 'btn btn-outline-secondary btn-sm');
      open.href = root.dataset.playlistUrl + state.savedAs.id;
      open.title = 'Il mix è salvato nella playlist «' + state.savedAs.name + '»';
      open.appendChild(icon('bi-check2 me-1'));
      open.appendChild(document.createTextNode('Salvato · Apri playlist'));
      actions.appendChild(open);
    } else {
      actions.appendChild(actionBtn('btn btn-outline-secondary', 'bi-collection-play', 'Salva playlist', openSave));
    }
    var closeBtn = actionBtn('btn btn-outline-secondary grz-mix-close', 'bi-x-lg', 'Chiudi mix', closeMix,
      'Toglie il mix dalla pagina. Se lo stai ascoltando, la riproduzione continua.');
    actions.appendChild(closeBtn);
    head.appendChild(actions);
    box.appendChild(head);

    // Avvisi
    var notices = el('div', 'grz-mix-notices');
    notices.setAttribute('data-mix-notices', '');
    if (state.restored) {
      notices.appendChild(notice('bi-clock-history',
        'Ultimo mix generato ' + whenLabel(state.generatedAt) +
        '. Resta qui finché non ne generi uno nuovo.'));
    }
    var s = mix.stats;
    if (s.selected < s.requested) {
      notices.appendChild(notice('bi-info-circle',
        'Trovate ' + plural(s.selected, 'traccia ascoltabile', 'tracce ascoltabili') +
        ' su ' + s.requested + ' richieste.', mix.alternatives));
    }
    if (s.relaxed > 0) {
      notices.appendChild(notice('bi-shuffle',
        'Per arrivare al numero richiesto sono stati ammessi più brani dello stesso artista o dello stesso disco.'));
    }
    if (s.missing > 0) {
      notices.appendChild(notice('bi-hdd',
        plural(s.missing, 'file audio non raggiungibile è stato escluso', 'file audio non raggiungibili sono stati esclusi') +
        ' (ad esempio un disco esterno scollegato).'));
    }
    box.appendChild(notices);

    // Elenco
    var list = el('ol', 'grz-mix-list');
    tracks.forEach(function (t, i) { list.appendChild(renderRow(t, i)); });
    box.appendChild(list);

    syncPlaying();
  }

  function metaLine(tracks) {
    var artists = {};
    var albums = {};
    var total = 0;
    tracks.forEach(function (t) {
      artists[t.artist_id] = true;
      albums[t.albumId] = true;
      total += t.duration || 0;
    });
    var parts = [plural(tracks.length, 'traccia', 'tracce')];
    if (total > 0) parts.push(fmtTotal(total));
    parts.push(plural(Object.keys(artists).length, 'artista', 'artisti'));
    parts.push(plural(Object.keys(albums).length, 'disco', 'dischi'));
    return parts.join(' · ');
  }

  function actionBtn(cls, iconName, label, onClick, title) {
    var b = el('button', cls + ' btn-sm');
    b.type = 'button';
    b.appendChild(icon(iconName + ' me-1'));
    b.appendChild(document.createTextNode(label));
    if (title) b.title = title;
    b.addEventListener('click', onClick);
    return b;
  }

  function notice(iconName, text, alternatives) {
    var n = el('div', 'grz-mix-notice');
    n.appendChild(icon(iconName));
    var body = el('div', 'grz-mix-notice__body');
    body.appendChild(el('p', null, text));
    if (alternatives && alternatives.length) {
      body.appendChild(renderAlternatives(alternatives));
    }
    n.appendChild(body);
    return n;
  }

  function renderAlternatives(alts) {
    var wrap = el('div', 'grz-mix-alts');
    wrap.appendChild(el('span', 'grz-mix-alts__label', 'Prova con:'));
    alts.forEach(function (a) {
      var b = el('button', 'grz-mix-alt');
      b.type = 'button';
      b.appendChild(el('span', null, a.label));
      b.appendChild(el('span', 'grz-mix-alt__count', String(a.playable)));
      b.title = plural(a.playable, 'traccia ascoltabile', 'tracce ascoltabili');
      b.addEventListener('click', function () {
        applyCriteria(a.criteria);
        refreshCount();
        generate(newSeed(), false);
      });
      wrap.appendChild(b);
    });
    return wrap;
  }

  function renderEmpty(mix) {
    var box = el('div', 'grz-mix-empty');
    box.appendChild(icon('bi-search'));
    box.appendChild(el('h2', 'grz-mix-empty__title', 'Nessuna traccia ascoltabile'));

    var s = mix.stats;
    var text;
    if (s.playable > 0) {
      // Tracce con audio nel DB, ma nessun file raggiungibile adesso
      text = 'Ci sono ' + plural(s.playable, 'traccia', 'tracce') + ' con audio per ' + mix.label +
        ', ma i file non sono raggiungibili in questo momento (ad esempio un disco esterno scollegato).';
    } else {
      text = 'Con questi criteri (' + mix.label + ') non ci sono tracce con file audio.';
      if (s.catalog > 0) {
        text += ' A catalogo ce ne sono ' + s.catalog + ', ma senza audio o più brevi di 45 secondi.';
      }
    }
    box.appendChild(el('p', null, text));

    if (mix.alternatives && mix.alternatives.length) {
      box.appendChild(renderAlternatives(mix.alternatives));
    }
    return box;
  }

  function renderRow(t, i) {
    var li = el('li', 'grz-mix-row');
    li.dataset.trackId = t.id;

    var play = el('button', 'grz-mix-row__play');
    play.type = 'button';
    play.title = 'Ascolta il mix da qui';
    play.setAttribute('aria-label', 'Ascolta il mix da ' + t.title);
    play.appendChild(el('span', 'grz-mix-row__num', String(i + 1)));
    play.appendChild(icon('bi-play-fill grz-mix-row__icon'));
    play.addEventListener('click', function () {
      // Traccia già in ascolto da questo mix: play/pausa, come nella scheda disco
      if (isCurrentFromMix(t.id) && typeof Player.togglePlay === 'function') {
        Player.togglePlay();
        return;
      }
      playFrom(i);
    });
    li.appendChild(play);

    var img = el('img', 'grz-mix-row__cover');
    img.alt = '';
    img.loading = 'lazy';
    img.src = t.cover;
    img.onerror = function () { this.onerror = null; this.style.visibility = 'hidden'; };
    li.appendChild(img);

    var text = el('div', 'grz-mix-row__text');
    text.appendChild(el('span', 'grz-mix-row__title', t.title));
    var sub = el('span', 'grz-mix-row__sub');
    var base = root.dataset.pageUrl.replace(/route=mix.*$/, '');
    var aArtist = el('a', 'grz-mix-row__link', t.artist);
    aArtist.href = base + 'route=artists/profile/' + t.artist_id;
    var aAlbum = el('a', 'grz-mix-row__link', t.album);
    aAlbum.href = base + 'route=albums/detail/' + t.albumId;
    sub.appendChild(aArtist);
    sub.appendChild(document.createTextNode(' · '));
    sub.appendChild(aAlbum);
    if (t.year) sub.appendChild(el('span', 'grz-mix-row__year', ' ' + t.year));
    text.appendChild(sub);
    li.appendChild(text);

    li.appendChild(el('span', 'grz-mix-row__dur', fmtTrack(t.duration)));

    var rm = el('button', 'grz-mix-row__remove');
    rm.type = 'button';
    rm.title = 'Togli dal mix';
    rm.setAttribute('aria-label', 'Togli ' + t.title + ' dal mix');
    rm.appendChild(icon('bi-x-lg'));
    rm.addEventListener('click', function () {
      state.tracks = state.tracks.filter(function (x) { return x.id !== t.id; });
      // Il mix non coincide più con la playlist salvata
      state.savedAs = null;
      render();
      storeLast();
    });
    li.appendChild(rm);

    return li;
  }

  // ── Player ──────────────────────────────────────────────────
  function playerTracks() {
    // Posizione = posizione nel mix, come nelle playlist
    return state.tracks.map(function (t, i) {
      return Object.assign({}, t, { position: i + 1 });
    });
  }

  function setContext(label) {
    var ctxEl = document.getElementById('sp-context');
    var ctxName = document.getElementById('sp-context-name');
    if (ctxEl && ctxName) {
      ctxName.textContent = CONTEXT_PREFIX + label;
      ctxEl.style.display = '';
    }
  }

  function playFrom(index) {
    if (!state.tracks.length || typeof Player === 'undefined') return;

    // Toglie il contesto di un'eventuale playlist precedente, poi scrive
    // quello del mix: Player.load() con id null non lo tocca.
    if (window.PlaylistPlayer && typeof window.PlaylistPlayer.clearContext === 'function') {
      window.PlaylistPlayer.clearContext();
    }
    setContext(state.mix.label);

    var tracks = playerTracks();
    Player.load({
      id: null,
      playlistId: null,
      cover: tracks[0].cover || '',
      artist: '',
      tracks: tracks
    }, index);
  }

  function enqueueAll() {
    if (!state.tracks.length || typeof Player === 'undefined' || typeof Player.enqueue !== 'function') return;
    var added = 0;
    var dup = 0;
    playerTracks().forEach(function (t) {
      var r = Player.enqueue(t);
      if (r === 'playing' || r === 'queued') added++;
      else if (r === 'duplicate') dup++;
    });
    var msg = plural(added, 'traccia aggiunta', 'tracce aggiunte') + ' alla coda';
    if (dup > 0) msg += ', ' + plural(dup, 'era già presente', 'erano già presenti');
    flash(msg + '.');
  }

  function mixContextActive() {
    var ctxEl = document.getElementById('sp-context');
    var ctxName = document.getElementById('sp-context-name');
    return !!(ctxEl && ctxName && ctxEl.style.display !== 'none' &&
      ctxName.textContent.indexOf(CONTEXT_PREFIX) === 0);
  }

  function isCurrentFromMix(trackId) {
    return typeof Player !== 'undefined' && typeof Player.currentTrackId === 'function' &&
      Player.currentTrackId() === trackId && mixContextActive();
  }

  // Evidenzia nella lista la traccia in ascolto
  function syncPlaying() {
    if (!root || !document.body.contains(root)) return;
    var currentId = (typeof Player !== 'undefined' && typeof Player.currentTrackId === 'function')
      ? Player.currentTrackId() : null;
    var audio = document.getElementById('global-audio');
    var paused = !audio || audio.paused;

    var fromMix = mixContextActive();

    qa('.grz-mix-row').forEach(function (li) {
      var on = fromMix && currentId !== null && parseInt(li.dataset.trackId, 10) === currentId;
      li.classList.toggle('is-playing', on);
      li.classList.toggle('is-paused', on && paused);
      var ic = li.querySelector('.grz-mix-row__icon');
      if (ic) {
        var showPause = on && !paused;
        ic.classList.toggle('bi-pause-fill', showPause);
        ic.classList.toggle('bi-play-fill', !showPause);
      }
    });
  }

  // ── Salvataggio come playlist ───────────────────────────────
  function openSave() {
    var modalEl = document.getElementById('instantMixSaveModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    var input = q('[data-mix-save-name]', modalEl);
    var err = q('[data-mix-save-error]', modalEl);
    input.value = state.mix.label;
    err.hidden = true;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalEl.addEventListener('shown.bs.modal', function () { input.focus(); input.select(); }, { once: true });
    modal.show();
  }

  function confirmSave() {
    var modalEl = document.getElementById('instantMixSaveModal');
    var input = q('[data-mix-save-name]', modalEl);
    var err = q('[data-mix-save-error]', modalEl);
    var btn = q('[data-mix-save-confirm]', modalEl);
    var name = input.value.trim();

    if (name === '') {
      err.textContent = 'Scrivi un nome per la playlist.';
      err.hidden = false;
      return;
    }

    var fd = new FormData();
    fd.append('playlist_id', 'new');
    fd.append('playlist_name', name);
    state.tracks.forEach(function (t) { fd.append('track_ids[]', String(t.id)); });

    btn.disabled = true;
    fetch(root.dataset.saveUrl, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.success) throw new Error((data && data.error) || 'Salvataggio non riuscito.');
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        state.savedAs = { id: data.playlist_id, name: name };
        storeLast();
        render();
        flash('Playlist salvata: ', name, root.dataset.playlistUrl + data.playlist_id);
      })
      .catch(function (e) {
        err.textContent = e.message || 'Salvataggio non riuscito.';
        err.hidden = false;
      })
      .then(function () { btn.disabled = false; });
  }

  // Messaggio breve sopra l'elenco (coda, salvataggio)
  function flash(text, linkText, linkHref) {
    var box = q('[data-mix-notices]');
    if (!box) return;
    var old = q('.grz-mix-notice--flash', box);
    if (old) old.remove();
    var n = el('div', 'grz-mix-notice grz-mix-notice--flash');
    n.appendChild(icon('bi-check2-circle'));
    var p = el('p', null, text);
    if (linkText && linkHref) {
      var a = el('a', null, linkText);
      a.href = linkHref;
      p.appendChild(a);
    }
    var body = el('div', 'grz-mix-notice__body');
    body.appendChild(p);
    n.appendChild(body);
    box.insertBefore(n, box.firstChild);
  }

  // ── Chiudi mix ──────────────────────────────────────────────
  // Toglie il mix dalla pagina e dal browser. Per qualche secondo resta
  // possibile annullare; la coda del player non viene toccata.
  var UNDO_MS = 8000;

  function closeMix() {
    if (!state.mix) return;
    state.closed = {
      mix: state.mix,
      tracks: state.tracks,
      seed: state.seed,
      generatedAt: state.generatedAt,
      savedAs: state.savedAs
    };
    state.mix = null;
    state.tracks = [];
    state.seed = null;
    state.savedAs = null;
    try { localStorage.removeItem(STORE_KEY); } catch (e) { /* storage non disponibile */ }
    updateUrl(null, false);

    var box = q('[data-mix-result]');
    box.innerHTML = state.placeholderHtml;

    var n = el('div', 'grz-mix-notice grz-mix-notice--undo');
    n.appendChild(icon('bi-x-circle'));
    var body = el('div', 'grz-mix-notice__body');
    var p = el('p', null, 'Mix chiuso. ');
    var undo = el('button', 'grz-mix-link', 'Annulla');
    undo.type = 'button';
    undo.addEventListener('click', undoClose);
    p.appendChild(undo);
    body.appendChild(p);
    n.appendChild(body);
    box.insertBefore(n, box.firstChild);

    if (state.undoTimer) clearTimeout(state.undoTimer);
    state.undoTimer = setTimeout(function () {
      state.closed = null;
      if (n.parentNode) n.remove();
    }, UNDO_MS);
  }

  function undoClose() {
    var c = state.closed;
    if (!c) return;
    if (state.undoTimer) clearTimeout(state.undoTimer);
    state.closed = null;
    state.mix = c.mix;
    state.tracks = c.tracks;
    state.seed = c.seed;
    state.generatedAt = c.generatedAt;
    state.savedAs = c.savedAs;
    state.restored = false;
    updateUrl(state.seed, false);
    render();
    storeLast();
  }

  // ── Init ────────────────────────────────────────────────────
  function init() {
    var found = document.getElementById('instant-mix');
    if (!found || found.dataset.mixBound === '1') return;
    root = found;
    root.dataset.mixBound = '1';
    state = { mix: null, tracks: [], seed: null, busy: false, countTimer: null, countReq: 0, closed: null };
    state.placeholderHtml = q('[data-mix-result]').innerHTML;

    // Dissolvenza in basso quando ci sono altri generi da scorrere
    var genreBox = q('[data-mix-genres]');
    function syncGenreFade() {
      if (!genreBox) return;
      var max = genreBox.scrollHeight - genreBox.clientHeight;
      genreBox.classList.toggle('has-more', max > 2 && genreBox.scrollTop < max - 2);
    }
    if (genreBox) genreBox.addEventListener('scroll', syncGenreFade, { passive: true });

    // Filtro testuale dei generi
    var search = q('[data-mix-genre-search]');
    if (search) {
      search.addEventListener('input', function () {
        var term = search.value.trim().toLowerCase();
        qa('[data-mix-genres] .grz-mix-chip').forEach(function (chip) {
          var match = term === '' || (chip.dataset.name || '').indexOf(term) !== -1;
          var checked = chip.querySelector('input').checked;
          chip.hidden = !(match || checked);
        });
        syncGenreFade();
      });
    }

    root.addEventListener('change', function (e) {
      if (!e.target.matches('input[name="g"], input[name="n"], input[name="order"], input[name="from"], input[name="to"]')) return;
      syncControls();
      updateUrl(null, false);
      scheduleCount();
    });

    qa('input[name="from"], input[name="to"]').forEach(function (inp) {
      inp.addEventListener('input', function () { syncControls(); scheduleCount(); });
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); generate(newSeed(), false); }
      });
    });

    qa('[data-mix-decades] [data-from]').forEach(function (b) {
      b.addEventListener('click', function () {
        var from = q('input[name="from"]');
        var to = q('input[name="to"]');
        if (b.getAttribute('aria-pressed') === 'true') {
          from.value = '';
          to.value = '';
        } else {
          from.value = b.dataset.from;
          to.value = b.dataset.to;
        }
        syncControls();
        updateUrl(null, false);
        scheduleCount();
      });
    });

    var clearG = q('[data-mix-clear-genres]');
    if (clearG) {
      clearG.addEventListener('click', function () {
        qa('[data-mix-genres] input[name="g"]').forEach(function (i) { i.checked = false; });
        if (search) { search.value = ''; search.dispatchEvent(new Event('input')); }
        syncControls();
        updateUrl(null, false);
        scheduleCount();
      });
    }

    var clearY = q('[data-mix-clear-years]');
    if (clearY) {
      clearY.addEventListener('click', function () {
        q('input[name="from"]').value = '';
        q('input[name="to"]').value = '';
        syncControls();
        updateUrl(null, false);
        scheduleCount();
      });
    }

    q('[data-mix-generate]').addEventListener('click', function () { generate(newSeed(), false); });

    var saveBtn = document.querySelector('#instantMixSaveModal [data-mix-save-confirm]');
    if (saveBtn) saveBtn.addEventListener('click', confirmSave);
    var saveInput = document.querySelector('#instantMixSaveModal [data-mix-save-name]');
    if (saveInput) {
      saveInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); confirmSave(); }
      });
    }

    syncControls();
    syncGenreFade();
    refreshCount();

    // Ripristino:
    // - URL con seed uguale all'ultimo mix salvato nel browser: si mostra
    //   quello, con le eventuali tracce tolte a mano (Indietro, ricarica);
    // - URL con un altro seed: si rigenera quel mix (preferiti, link);
    // - URL senza seed (voce di menu): si ripropone l'ultimo mix, con i
    //   suoi criteri nei controlli.
    var last = readLast();
    var urlSeed = root.dataset.seed;
    if (last && (!urlSeed || (urlSeed === last.mix.criteria.seed && sameCriteria(readCriteria(), last.mix.criteria)))) {
      if (!urlSeed) applyCriteria(last.mix.criteria);
      state.mix = last.mix;
      state.tracks = last.tracks;
      state.seed = last.mix.criteria.seed;
      state.generatedAt = last.savedAt;
      state.savedAs = last.savedAs || null;
      state.restored = true;
      updateUrl(state.seed, false);
      refreshCount();
      render();
    } else if (urlSeed) {
      generate(urlSeed, true);
    }
  }

  // Ascolti globali, registrati una sola volta: il player sta fuori da
  // <main> e sopravvive alla navigazione SPA.
  document.addEventListener('player:changed', syncPlaying);
  (function bindAudio() {
    var audio = document.getElementById('global-audio');
    if (!audio) return;
    audio.addEventListener('play', syncPlaying);
    audio.addEventListener('pause', syncPlaying);
  })();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return { init: init };
})();

window.InstantMix = InstantMix;
