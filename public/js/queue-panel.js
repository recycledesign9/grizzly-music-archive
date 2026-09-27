/* ============================================================
   queue-panel.js
   Pannello coda di riproduzione del player nativo (sticky player).

   Costruito interamente sopra l'API pubblica di Player (app.js):
   getPlaylist(), setPlaylist(), currentIndex(), playTrack().
   Non tocca gli internals del Player.

   Funzioni:
   - mostra la traccia in ascolto in testa, poi "Successivi" (riordinabili)
     e "Già ascoltati" (richiudibile, chiuso di default)
   - rimuove tracce successive (la traccia corrente non è rimovibile)
   - riordina via drag-and-drop (SortableJS, già inclusa nel progetto)
   - click su una riga riproducibile → salta a quella traccia

   Dipende da: Player (app.js), Sortable (CDN in footer.php),
   evento CustomEvent 'player:changed' emesso da app.js.
   Il pannello è appeso a document.body, quindi sopravvive
   alla navigazione SPA (come #yt-lightbox).
   ============================================================ */

(function () {
  'use strict';

  let panel = null;
  let listEl = null;
  let countEl = null;
  let nowEl = null;
  let nextHead = null;
  let nextEmpty = null;
  let historyBox = null;
  let historyHead = null;
  let historyList = null;
  let sortable = null;
  let isPanelOpen = false;
  let isDragging = false;
  let snapshot = [];  // copia della playlist al momento dell'ultimo render

  // ── Coda fissata ────────────────────────────────────────────
  // Preferenza dell'utente, salvata in localStorage. Ha effetto solo
  // su schermi larghi (DOCK_QUERY): lì il pannello diventa una colonna
  // agganciata a destra, il contenuto si restringe (body.qp-docked) e
  // il pannello non si chiude con i clic fuori né con la navigazione.
  // Su schermi stretti la preferenza resta salvata ma il pannello si
  // comporta come sovrapposto.
  const PIN_KEY = 'grz.queuePinned';
  const DOCK_QUERY = window.matchMedia ? window.matchMedia('(min-width: 1200px)') : null;
  let isPinned = false;
  let lastQueueLength = 0;

  function readPinned() {
    try { return localStorage.getItem(PIN_KEY) === '1'; } catch (e) { return false; }
  }
  function writePinned(v) {
    try { localStorage.setItem(PIN_KEY, v ? '1' : '0'); } catch (e) { /* storage non disponibile */ }
  }
  function canDock() {
    return !!(DOCK_QUERY && DOCK_QUERY.matches);
  }
  function isDocked() {
    return isPinned && isPanelOpen && canDock();
  }

  // Altezza della navbar: il pannello agganciato parte sotto di essa
  function syncNavHeight() {
    const nav = document.querySelector('nav.navbar');
    if (nav) document.documentElement.style.setProperty('--grz-nav-h', nav.offsetHeight + 'px');
  }

  // Applica lo stato visivo: classi su pannello e body, pulsante fissa
  let wasDocked = false;

  function applyDock() {
    const docked = isDocked();
    // Lo spazio della pagina cambia senza che cambi la finestra: un
    // evento resize fa ricalcolare i layout che dipendono dal JS
    // (es. righe visibili della griglia in Home).
    if (docked !== wasDocked) {
      wasDocked = docked;
      window.requestAnimationFrame(function () {
        window.dispatchEvent(new Event('resize'));
      });
    }
    if (panel) panel.classList.toggle('is-docked', docked);
    document.body.classList.toggle('qp-docked', docked);
    const pin = document.getElementById('qp-pin');
    if (pin) {
      pin.setAttribute('aria-pressed', isPinned ? 'true' : 'false');
      pin.classList.toggle('is-active', isPinned);
      const label = isPinned ? 'Sblocca la coda' : 'Fissa la coda a lato della pagina';
      pin.title = label;
      pin.setAttribute('aria-label', label);
      // Si cambia solo la classe dell'icona esistente: riscrivere
      // innerHTML toglierebbe dal documento l'elemento appena cliccato,
      // e il gestore "clic fuori dal pannello" lo leggerebbe come un
      // clic esterno, chiudendo il pannello allo sblocco.
      const pinIcon = pin.querySelector('i');
      if (pinIcon) {
        pinIcon.classList.toggle('bi-pin-angle-fill', isPinned);
        pinIcon.classList.toggle('bi-pin-angle', !isPinned);
      }
    }
    if (docked) syncNavHeight();
  }

  function togglePin() {
    isPinned = !isPinned;
    writePinned(isPinned);
    applyDock();
  }

  // ── Costruzione pannello ────────────────────────────────────
  // Tre zone: traccia in ascolto (fissa), "Successivi" (riordinabile
  // via drag) e "Già ascoltati" (richiudibile, chiuso di default).
  function createPanel() {
    const el = document.createElement('div');
    el.id = 'queue-panel';
    el.className = 'qp-panel';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Coda di riproduzione');
    el.innerHTML = [
      '<div class="qp-header">',
        '<span class="qp-title">Coda di riproduzione</span>',
        '<span class="qp-count" id="qp-count"></span>',
        '<button type="button" class="qp-pin" id="qp-pin" aria-pressed="false" title="Fissa la coda a lato della pagina" aria-label="Fissa la coda a lato della pagina">',
          '<i class="bi bi-pin-angle" aria-hidden="true"></i>',
        '</button>',
        '<button type="button" class="qp-close" id="qp-close" title="Chiudi" aria-label="Chiudi la coda">',
          '<i class="bi bi-x-lg" aria-hidden="true"></i>',
        '</button>',
      '</div>',
      '<div class="qp-scroll">',
        '<div class="qp-now" id="qp-now"></div>',
        '<div class="qp-section-head" id="qp-next-head"></div>',
        '<ul class="qp-list" id="qp-list"></ul>',
        '<div class="qp-empty" id="qp-next-empty" hidden>Nessuna traccia dopo questa.</div>',
        '<details class="qp-history" id="qp-history" hidden>',
          '<summary class="qp-section-head" id="qp-history-head"></summary>',
          '<ul class="qp-list qp-list--history" id="qp-history-list"></ul>',
        '</details>',
      '</div>'
    ].join('');
    document.body.appendChild(el);
    return el;
  }

  // Nome del contesto (playlist) mostrato nella barra del player
  function contextName() {
    const ctx = document.getElementById('sp-context');
    const name = document.getElementById('sp-context-name');
    if (!ctx || !name || ctx.style.display === 'none') return '';
    return name.textContent.trim();
  }

  function coverImg(src, cls) {
    const img = document.createElement('img');
    img.className = cls;
    img.alt = '';
    img.loading = 'lazy';
    if (src) img.src = src;
    img.onerror = function () { this.onerror = null; this.removeAttribute('src'); };
    return img;
  }

  // Riga di coda (successivi o già ascoltati)
  function buildRow(t, i, sortableRow) {
    const li = document.createElement('li');
    li.className = 'qp-item' + (!t.src ? ' qp-unavailable' : '');
    li.dataset.qIdx = i;
    if (t.id) li.dataset.trackId = t.id;
    if (!t.src) li.title = 'File audio non disponibile';

    if (sortableRow) {
      const handle = document.createElement('span');
      handle.className = 'qp-handle';
      handle.title = 'Trascina per riordinare';
      handle.innerHTML = '<i class="bi bi-grip-vertical" aria-hidden="true"></i>';
      li.appendChild(handle);
    }

    li.appendChild(coverImg(t.cover, 'qp-cover'));

    const meta = document.createElement('span');
    meta.className = 'qp-meta';
    const title = document.createElement('span');
    title.className = 'qp-item-title';
    title.textContent = t.title || '—';
    meta.appendChild(title);
    if (t.artist) {
      const artist = document.createElement('span');
      artist.className = 'qp-item-artist';
      artist.textContent = t.artist;
      meta.appendChild(artist);
    }
    li.appendChild(meta);

    const rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'qp-remove';
    rm.title = 'Rimuovi dalla coda';
    rm.setAttribute('aria-label', 'Rimuovi ' + (t.title || 'traccia') + ' dalla coda');
    rm.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
    rm.addEventListener('click', function (e) {
      e.stopPropagation();
      removeAt(i);
    });
    li.appendChild(rm);

    // Click sulla riga → salta alla traccia (solo se riproducibile)
    if (t.src && t.id) {
      li.tabIndex = 0;
      li.setAttribute('role', 'button');
      li.setAttribute('aria-label', 'Riproduci ' + (t.title || 'traccia'));
      const go = function () { Player.playTrack(t.id); };
      li.addEventListener('click', function (e) {
        if (e.target.closest('.qp-handle') || e.target.closest('.qp-remove')) return;
        go();
      });
      li.addEventListener('keydown', function (e) {
        if (e.target !== li) return;
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
      });
    }
    return li;
  }

  // ── Render ──────────────────────────────────────────────────
  function render() {
    if (!panel || isDragging) return;
    if (typeof Player === 'undefined' || typeof Player.getPlaylist !== 'function') return;

    snapshot = Player.getPlaylist();
    const cur = Player.currentIndex();

    // Player resettato / coda vuota → chiudi il pannello
    if (!snapshot.length) {
      listEl.innerHTML = '';
      historyList.innerHTML = '';
      nowEl.innerHTML = '';
      if (isPanelOpen) close();
      return;
    }

    const playable = snapshot.filter(function (t) { return t && t.src; }).length;
    countEl.textContent = playable + (playable === 1 ? ' traccia' : ' tracce');

    const audio = document.getElementById('global-audio');
    const isPaused = !audio || audio.paused;

    // ── In riproduzione
    // Se la traccia è la stessa del render precedente (es. play/pausa)
    // la sezione non viene ricostruita: si aggiornano solo etichetta e
    // contesto. L'icona resta lo stesso elemento e app.js (setPlaying)
    // ne congela o riprende l'animazione con la classe "paused", come
    // nel player. Ricostruirla creerebbe un'icona nata in pausa, con le
    // barre ferme nella fase di ritardo dell'animazione (forma storpia).
    const t = snapshot[cur];
    if (t && nowEl.dataset.trackId === String(t.id) && nowEl.querySelector('.qp-now-label')) {
      const nowLabel = nowEl.querySelector('.qp-now-label');
      const stateText = nowLabel.querySelector('.qp-now-state');
      if (stateText) stateText.textContent = isPaused ? 'In pausa' : 'In riproduzione';
      const icon = nowLabel.querySelector('.track-playing-icon');
      if (icon) icon.classList.toggle('paused', isPaused);
      const ctxNow = contextName();
      let fromEl = nowLabel.querySelector('.qp-now-from');
      if (ctxNow) {
        if (!fromEl) {
          fromEl = document.createElement('span');
          fromEl.className = 'qp-now-from';
          nowLabel.appendChild(fromEl);
        }
        fromEl.textContent = 'da ' + ctxNow;
      } else if (fromEl) {
        fromEl.remove();
      }
    } else {
    nowEl.innerHTML = '';
    nowEl.dataset.trackId = t ? String(t.id) : '';
    if (t) {
      const label = document.createElement('div');
      label.className = 'qp-now-label';
      label.innerHTML = '<span class="track-playing-icon' + (isPaused ? ' paused' : '') + '" style="display:inline-flex">'
        + '<span class="bar"></span><span class="bar"></span><span class="bar"></span></span>';
      const labelText = document.createElement('span');
      labelText.className = 'qp-now-state';
      labelText.textContent = isPaused ? 'In pausa' : 'In riproduzione';
      label.appendChild(labelText);
      const ctx = contextName();
      if (ctx) {
        const from = document.createElement('span');
        from.className = 'qp-now-from';
        from.textContent = 'da ' + ctx;
        label.appendChild(from);
      }
      nowEl.appendChild(label);

      const row = document.createElement('div');
      row.className = 'qp-now-row';
      row.appendChild(coverImg(t.cover, 'qp-now-cover'));
      const meta = document.createElement('div');
      meta.className = 'qp-meta';
      const title = document.createElement('span');
      title.className = 'qp-now-title';
      title.textContent = t.title || '—';
      meta.appendChild(title);
      if (t.artist) {
        const artist = document.createElement('span');
        artist.className = 'qp-item-artist';
        artist.textContent = t.artist;
        meta.appendChild(artist);
      }
      row.appendChild(meta);
      nowEl.appendChild(row);
    }
    }

    // ── Successivi (riordinabili) e già ascoltati
    const start = cur >= 0 ? cur + 1 : 0;
    listEl.innerHTML = '';
    historyList.innerHTML = '';

    let nextCount = 0;
    for (let i = start; i < snapshot.length; i++) {
      if (!snapshot[i]) continue;
      listEl.appendChild(buildRow(snapshot[i], i, true));
      nextCount++;
    }
    nextHead.textContent = 'Successivi' + (nextCount ? ' · ' + nextCount : '');
    nextEmpty.hidden = nextCount > 0;

    let histCount = 0;
    for (let i = 0; i < cur; i++) {
      if (!snapshot[i]) continue;
      historyList.appendChild(buildRow(snapshot[i], i, false));
      histCount++;
    }
    historyBox.hidden = histCount === 0;
    historyHead.textContent = 'Già ascoltati · ' + histCount;

    initSortable();
  }

  // ── Rimozione traccia ───────────────────────────────────────
  function removeAt(index) {
    if (index < 0 || index >= snapshot.length) return;

    const newList = snapshot.filter(function (_, i) { return i !== index; });
    // setPlaylist preserva il cursore sulla traccia corrente (per id)
    // ed emette 'player:changed' → il pannello si ri-renderizza da solo.
    Player.setPlaylist(newList);
  }

  // ── Riordino drag-and-drop ──────────────────────────────────
  function initSortable() {
    if (typeof Sortable === 'undefined') return;
    if (sortable) { sortable.destroy(); sortable = null; }

    sortable = Sortable.create(listEl, {
      handle: '.qp-handle',
      animation: 150,
      ghostClass: 'qp-ghost',
      onStart: function () { isDragging = true; },
      onEnd: function () {
        isDragging = false;
        // Ricostruisce l'array dall'ordine del DOM usando gli indici
        // dello snapshot (robusto anche per tracce senza id).
        // Solo "Successivi" è riordinabile: già ascoltati e traccia
        // corrente restano nella loro posizione in testa alla coda.
        const cur = Player.currentIndex();
        const reordered = snapshot.slice(0, cur >= 0 ? cur + 1 : 0);
        listEl.querySelectorAll('.qp-item').forEach(function (li) {
          const idx = parseInt(li.dataset.qIdx, 10);
          if (!isNaN(idx) && snapshot[idx]) reordered.push(snapshot[idx]);
        });
        if (reordered.length === snapshot.length) {
          Player.setPlaylist(reordered);
        } else {
          // Incoerenza inattesa: ri-renderizza dallo stato reale del Player
          render();
        }
      }
    });
  }

  // ── Apertura / chiusura ─────────────────────────────────────
  function open() {
    isPanelOpen = true;
    panel.classList.add('is-open');
    render();
    const btn = document.getElementById('sp-queue');
    if (btn) btn.setAttribute('aria-expanded', 'true');
    applyDock();
  }

  function close() {
    isPanelOpen = false;
    panel.classList.remove('is-open');
    const btn = document.getElementById('sp-queue');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    applyDock();
  }

  function toggle() {
    if (isPanelOpen) { close(); } else { open(); }
  }

  // ── Init ────────────────────────────────────────────────────
  function init() {
    panel = createPanel();
    listEl = document.getElementById('qp-list');
    countEl = document.getElementById('qp-count');
    nowEl = document.getElementById('qp-now');
    nextHead = document.getElementById('qp-next-head');
    nextEmpty = document.getElementById('qp-next-empty');
    historyBox = document.getElementById('qp-history');
    historyHead = document.getElementById('qp-history-head');
    historyList = document.getElementById('qp-history-list');

    document.getElementById('qp-close').addEventListener('click', close);
    document.getElementById('qp-pin').addEventListener('click', togglePin);

    isPinned = readPinned();
    applyDock();

    // Passaggio a schermo stretto/largo: il dock si attiva o si spegne
    if (DOCK_QUERY) {
      const onChange = function () { applyDock(); };
      if (DOCK_QUERY.addEventListener) DOCK_QUERY.addEventListener('change', onChange);
      else if (DOCK_QUERY.addListener) DOCK_QUERY.addListener(onChange);
    }
    window.addEventListener('resize', function () { if (isDocked()) syncNavHeight(); });

    // Bottone nello sticky player (footer.php, fuori da <main>:
    // persiste alla navigazione SPA, quindi basta un bind unico)
    const btn = document.getElementById('sp-queue');
    if (btn) btn.addEventListener('click', toggle);

    // Ri-renderizza a ogni cambio di stato del Player
    document.addEventListener('player:changed', function () {
      // Coda fissata: all'avvio di una nuova coda il pannello si apre da
      // solo (solo su schermi larghi, dove è agganciato a lato).
      const len = (typeof Player !== 'undefined' && typeof Player.getPlaylist === 'function')
        ? Player.getPlaylist().length : 0;
      if (isPinned && canDock() && !isPanelOpen && lastQueueLength === 0 && len > 0) {
        lastQueueLength = len;
        open();
        return;
      }
      lastQueueLength = len;
      if (isPanelOpen) render();
    });

    // Aggiorna lo stato pausa delle barre nel pannello.
    // (setPlaying in app.js lo fa già globalmente su .track-playing-icon,
    // questo listener serve solo come sicurezza al primo render dopo un play)
    const audio = document.getElementById('global-audio');
    if (audio) {
      audio.addEventListener('play', function () {
        if (isPanelOpen && !isDragging) render();
      });
      audio.addEventListener('pause', function () {
        if (isPanelOpen && !isDragging) render();
      });
    }

    // ESC chiude il pannello (solo se aperto)
    // (con la coda agganciata Esc è lasciato a modali e menu)
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isPanelOpen && !isDocked()) close();
    });

    // Click fuori dal pannello → chiudi (ma non sul bottone che lo apre)
    // Con la coda agganciata il pannello resta aperto: clic sulla pagina
    // e navigazione SPA non lo chiudono.
    document.addEventListener('click', function (e) {
      if (!isPanelOpen) return;
      if (isDocked()) return;
      if (panel.contains(e.target)) return;
      if (e.target.closest('#sp-queue')) return;
      close();
    });
  }

  // API pubblica minima (per debug o usi futuri)
  window.QueuePanel = {
    open: open,
    close: close,
    toggle: toggle,
    refresh: render,
    togglePin: togglePin,
    isDocked: isDocked
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();