/* ============================================================
   queue-panel.js
   Pannello coda di riproduzione del player nativo (sticky player).

   Costruito interamente sopra l'API pubblica di Player (app.js):
   getPlaylist(), setPlaylist(), currentIndex(), playTrack().
   Non tocca gli internals del Player.

   Funzioni:
   - visualizza la coda corrente con traccia attiva evidenziata
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
  let sortable = null;
  let isPanelOpen = false;
  let isDragging = false;
  let snapshot = [];  // copia della playlist al momento dell'ultimo render

  // ── Costruzione pannello ────────────────────────────────────
  function createPanel() {
    const el = document.createElement('div');
    el.id = 'queue-panel';
    el.className = 'qp-panel';
    el.setAttribute('aria-label', 'Coda di riproduzione');
    el.innerHTML = [
      '<div class="qp-header">',
        '<span class="qp-title"><i class="bi bi-music-note-list me-2"></i>Coda di riproduzione</span>',
        '<span class="qp-count" id="qp-count"></span>',
        '<button class="qp-close" id="qp-close" title="Chiudi">',
          '<i class="bi bi-x-lg"></i>',
        '</button>',
      '</div>',
      '<ul class="qp-list" id="qp-list"></ul>'
    ].join('');
    document.body.appendChild(el);
    return el;
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
      if (isPanelOpen) close();
      return;
    }

    const playable = snapshot.filter(function (t) { return t && t.src; }).length;
    countEl.textContent = playable + (playable === 1 ? ' traccia' : ' tracce');

    const audio = document.getElementById('global-audio');
    const isPaused = !audio || audio.paused;

    listEl.innerHTML = '';

    snapshot.forEach(function (t, i) {
      if (!t) return;

      const li = document.createElement('li');
      li.className = 'qp-item'
        + (i === cur ? ' qp-current' : '')
        + (!t.src ? ' qp-unavailable' : '');
      li.dataset.qIdx = i;
      if (t.id) li.dataset.trackId = t.id;
      if (!t.src) li.title = 'File audio non disponibile';

      // Maniglia drag
      const handle = document.createElement('span');
      handle.className = 'qp-handle';
      handle.title = 'Trascina per riordinare';
      handle.innerHTML = '<i class="bi bi-grip-vertical"></i>';
      li.appendChild(handle);

      // Posizione / barre in riproduzione
      const pos = document.createElement('span');
      pos.className = 'qp-pos';
      if (i === cur) {
        pos.innerHTML = '<span class="track-playing-icon' + (isPaused ? ' paused' : '') + '" style="display:inline-flex">'
          + '<span class="bar"></span><span class="bar"></span><span class="bar"></span>'
          + '</span>';
      } else {
        pos.textContent = t.position || (i + 1);
      }
      li.appendChild(pos);

      // Titolo + artista (l'artista esiste solo nelle tracce playlist;
      // le tracce album non hanno il campo e restano su una riga sola)
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

      // Rimozione — mai sulla traccia corrente
      if (i !== cur) {
        const rm = document.createElement('button');
        rm.className = 'qp-remove';
        rm.title = 'Rimuovi dalla coda';
        rm.innerHTML = '<i class="bi bi-x-lg"></i>';
        rm.addEventListener('click', function (e) {
          e.stopPropagation();
          removeAt(i);
        });
        li.appendChild(rm);
      }

      // Click sulla riga → salta alla traccia (solo se riproducibile)
      if (t.src && t.id && i !== cur) {
        li.addEventListener('click', function (e) {
          if (e.target.closest('.qp-handle') || e.target.closest('.qp-remove')) return;
          Player.playTrack(t.id);
        });
      }

      listEl.appendChild(li);
    });

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
        const reordered = [];
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
  }

  function close() {
    isPanelOpen = false;
    panel.classList.remove('is-open');
  }

  function toggle() {
    if (isPanelOpen) { close(); } else { open(); }
  }

  // ── Init ────────────────────────────────────────────────────
  function init() {
    panel = createPanel();
    listEl = document.getElementById('qp-list');
    countEl = document.getElementById('qp-count');

    document.getElementById('qp-close').addEventListener('click', close);

    // Bottone nello sticky player (footer.php, fuori da <main>:
    // persiste alla navigazione SPA, quindi basta un bind unico)
    const btn = document.getElementById('sp-queue');
    if (btn) btn.addEventListener('click', toggle);

    // Ri-renderizza a ogni cambio di stato del Player
    document.addEventListener('player:changed', function () {
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
    }

    // ESC chiude il pannello (solo se aperto)
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isPanelOpen) close();
    });

    // Click fuori dal pannello → chiudi (ma non sul bottone che lo apre)
    document.addEventListener('click', function (e) {
      if (!isPanelOpen) return;
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
    refresh: render
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();