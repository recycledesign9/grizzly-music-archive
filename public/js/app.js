/* ============================================================
Music Archive — app.js
SPA-lite navigation + Sticky Audio Player
============================================================ */

const BASE_URL = document.querySelector('meta[name="base-url"]')?.content ?? '';
// -------------------------------------------------------
// Dark mode: init + toggle interamente in header.php (inline script).
// Non duplicare qui: quello script gira PRIMA di questo file (è inline,
// subito dopo la nav) e gestisce sia data-bs-theme che le icone insieme,
// nello stesso punto — è proprio la duplicazione tra i due file (questo
// impostava l'attributo, header.php solo le icone al click) la causa del
// disallineamento icona/tema che si vedeva dopo un refresh.
// -------------------------------------------------------
// Modal elimina
// -------------------------------------------------------
document.addEventListener('shown.bs.modal', (e) => {
  if (e.target.id !== 'deleteModal') return;
  const trigger = e.relatedTarget;
  if (!trigger) return;
  const id = trigger.dataset.id;
  const title = trigger.dataset.title;
  const el = document.getElementById('deleteAlbumTitle');
  const form = document.getElementById('deleteForm');
  if (el) el.textContent = title || '';
  if (form && id) form.action = `index.php?route=albums/delete/${id}`;
});

// -------------------------------------------------------
// Sticky Player
// -------------------------------------------------------
const Player = (function () {
  const audio = document.getElementById('global-audio');
  const panel = document.getElementById('sticky-player');
  const btnPlay = document.getElementById('sp-play-pause');
  const btnPrev = document.getElementById('sp-prev');
  const btnNext = document.getElementById('sp-next');
  const btnStop = document.getElementById('sp-stop');
  const seek = document.getElementById('sp-seek');
  const timeCur = document.getElementById('sp-current');
  const timeDur = document.getElementById('sp-duration');
  const coverImg = document.getElementById('sp-cover-img');
  const trackEl = document.getElementById('sp-track-title');
  const artistEl = document.getElementById('sp-artist-name');
  const btnRepeat = document.getElementById('sp-repeat');
  const timeToggle = document.getElementById('sp-time-toggle');
  const seekWrap = document.getElementById('sp-seek-wrap');
  const ruler = document.getElementById('sp-ruler');
  const hoverLine = document.getElementById('sp-hover-line');
  const hoverTip = document.getElementById('sp-hover-tip');

  if (!audio || !panel) return { load: () => { }, playTrack: () => { } };

  let playlist = [];   // array di tracce con src
  let cursor = -1;   // indice corrente
  let albumMeta = {};   // { cover, artist }

  // Barra di avanzamento: risoluzione 1/1000 (deve combaciare con max
  // dell'input #sp-seek in footer.php). Con la barra lunga l'1% era
  // un passo di diversi pixel e di alcuni secondi.
  const SEEK_MAX = 1000;
  const ACCENT = '#f5b400';
  const ACCENT_DEEP = '#c98f00';

  // "Precedente": oltre questa soglia (secondi) riavvia la traccia
  // corrente, entro la soglia passa alla traccia prima.
  const RESTART_THRESHOLD = 3;

  // Ripeti a tre stati e formato del tempo a destra, ricordati tra le
  // sessioni. localStorage può mancare (navigazione privata): si
  // ripiega sui valori di default senza errori.
  const REPEAT_KEY = 'grz.playerRepeat';
  const REMAINING_KEY = 'grz.playerRemaining';
  const REPEAT_MODES = ['off', 'all', 'one'];
  const REPEAT_LABELS = {
    off: 'Ripeti: disattivato',
    all: 'Ripeti la coda',
    one: 'Ripeti la traccia'
  };

  function readPref(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  function writePref(key, value) {
    try { localStorage.setItem(key, value); } catch (e) { /* storage non disponibile */ }
  }

  const storedRepeat = readPref(REPEAT_KEY);
  let repeatMode = REPEAT_MODES.indexOf(storedRepeat) !== -1 ? storedRepeat : 'off';
  let showRemaining = readPref(REMAINING_KEY) === '1';
  let rulerTicks = [];        // [{ el, at }] tacche del righello dei minuti
  let prevRestarts = null;    // ultimo stato dell'etichetta di "precedente"

  function fmt(s) {
    s = Math.floor(s || 0);
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  }

  function show() {
    panel.style.display = 'flex';
  }

  // Notifica ai moduli esterni (es. queue-panel.js) che lo stato del player
  // è cambiato: traccia corrente, playlist o reset. Nessun accoppiamento diretto.
  function notifyChange() {
    document.dispatchEvent(new CustomEvent('player:changed'));
  }

  // -------------------------------------------------------
  // Contesto di riproduzione
  // La stessa traccia può comparire in più playlist e nel suo album.
  // "In riproduzione" (barra, equalizzatore, pausa) vale solo nella
  // lista che corrisponde al contesto caricato; nelle altre la riga
  // riceve solo il segnale leggero .track-elsewhere.
  // Le liste dichiarano il proprio contesto con data-player-context
  // ("playlist:ID" o "album:ID"); una lista senza attributo mantiene
  // il comportamento precedente (confronto sul solo id traccia).
  // -------------------------------------------------------
  function currentContext() {
    if (albumMeta.playlistId) return 'playlist:' + albumMeta.playlistId;
    if (albumMeta.id) return 'album:' + albumMeta.id;
    return '';
  }

  function rowInContext(li) {
    const host = li.closest('[data-player-context]');
    if (!host) return true;
    return host.dataset.playerContext === currentContext();
  }

  function elsewhereLabel() {
    if (albumMeta.playlistId) {
      const nameEl = document.getElementById('sp-context-name');
      const name = nameEl ? nameEl.textContent.trim() : '';
      return name ? 'in ascolto da ' + name : 'in ascolto da una playlist';
    }
    // Coda caricata senza disco né playlist di partenza (Instant Mix):
    // la provenienza è l'etichetta del player, se visibile, ridotta alla
    // sola sorgente ("Instant Mix · Post-Rock, …" → "Instant Mix") perché
    // l'etichetta della riga deve restare corta come "in ascolto dal disco".
    if (!albumMeta.id) {
      const ctxEl = document.getElementById('sp-context');
      const ctxName = document.getElementById('sp-context-name');
      const name = (ctxEl && ctxName && ctxEl.style.display !== 'none')
        ? ctxName.textContent.split(' · ')[0].trim() : '';
      if (name) return 'in ascolto da ' + name;
    }
    return 'in ascolto dal disco';
  }

  function clearElsewhere(li) {
    li.classList.remove('track-elsewhere');
    const titleEl = li.querySelector('.fw-semibold');
    if (titleEl) {
      delete titleEl.dataset.elsewhere;
      // Ripristina il tooltip del titolo ("Riproduci …")
      if (titleEl.dataset.origTitle !== undefined) {
        titleEl.title = titleEl.dataset.origTitle;
        delete titleEl.dataset.origTitle;
      }
    }
  }

  function resetPlayerState(reason) {
    // reason: 'stopped' | 'completed' | 'no-playable-track'
    panel.style.display = 'none';

    try {
      audio.pause();
    } catch (e) { }

    audio.removeAttribute('src');
    audio.load();

    playlist = [];
    cursor = -1;
    albumMeta = {};

    if (trackEl) trackEl.textContent = '';
    if (artistEl) artistEl.textContent = '';
    if (coverImg) coverImg.removeAttribute('src');

    if (seek) {
      seek.value = 0;
      seekFill();
    }

    if (timeCur) timeCur.textContent = '0:00';
    if (timeDur) timeDur.textContent = '0:00';
    clearRuler();
    hideHover();
    updatePrevLabel();

    if (btnPlay) {
      btnPlay.innerHTML = '<i class="bi bi-play-fill"></i>';
    }

    document.querySelectorAll('.track-item').forEach(function (li) {
      li.classList.remove('track-playing');
      clearElsewhere(li);

      var playlistSlot = li.querySelector('.pl-track-playing-icon');
      if (playlistSlot) {
        playlistSlot.innerHTML = '';
        playlistSlot.className = 'pl-track-playing-icon';
        playlistSlot.style.display = 'none';
      }

      var playingIcon = li.querySelector('.track-playing-icon');
      if (playingIcon && !playingIcon.classList.contains('pl-track-playing-icon')) {
        playingIcon.remove();
      }

      var posSpan = li.querySelector('.track-index-cell .track-num') || li.querySelector('.text-muted.small');
      if (posSpan && posSpan.dataset.origText) {
        posSpan.textContent = posSpan.dataset.origText;
        delete posSpan.dataset.origText;
      }

      var btn = li.querySelector('.btn-track-play');
      if (btn) {
        btn.innerHTML = '<i class="bi bi-play-fill"></i>';
        btn.title = 'Ascolta';
      }
    });

    window.__PlaylistState = {
      id: null,
      tracks: [],
      cursor: -1,
      status: reason || 'stopped'
    };

    if (window.PlaylistPlayer &&
      typeof window.PlaylistPlayer.clearContext === 'function') {
      window.PlaylistPlayer.clearContext();
    }

    if (typeof window.__syncPlaylistListUI === 'function') {
      window.__syncPlaylistListUI();
    }

    notifyChange();
  }

  function hide() {
    resetPlayerState('stopped');
  }

  function safePlay() {
    if (!audio || !audio.src) {
      return;
    }

    audio.play().catch(function (err) {
      console.warn('[Player] Impossibile avviare audio:', err.message);
    });
  }

  function updateUI() {
    const t = playlist[cursor];
    if (!t) return;
    trackEl.textContent = t.position + '. ' + t.title;
    // Artista per traccia (playlist multi-artista) con fallback
    // sull'artista dell'album — stesso pattern già usato per la cover.
    const artistName = t.artist || albumMeta.artist || '';
    artistEl.textContent = artistName;
    coverImg.src = t.cover || albumMeta.cover || '';
    document.title = t.title + ' — ' + artistName + ' · Music Archive';

    const coverLink = document.getElementById('sp-cover-link');
    if (coverLink) {
      if (albumMeta.playlistId) {
        coverLink.href = BASE_URL + '/index.php?route=playlists/detail/' + albumMeta.playlistId;
        coverLink.title = 'Vai alla playlist';
      } else if (t.albumId) {
        // Segue la traccia CORRENTE: con la coda mista, la traccia in
        // riproduzione può appartenere a un album diverso da quello che
        // ha avviato il player — il link deve portare al SUO disco.
        coverLink.href = BASE_URL + '/index.php?route=albums/detail/' + t.albumId;
        coverLink.title = 'Vai al disco';
      } else if (albumMeta.id) {
        coverLink.href = BASE_URL + '/index.php?route=albums/detail/' + albumMeta.id;
        coverLink.title = 'Vai al disco';
      }
    }

    // Evidenzia riga attiva nella tracklist e aggiorna pulsanti play/pause
    document.querySelectorAll('.track-item').forEach(li => {
      const tid = parseInt(li.dataset.trackId || 0);
      li.classList.remove('track-playing');
      clearElsewhere(li);

      const slot = li.querySelector('.pl-track-playing-icon');
      if (slot) {
        slot.innerHTML = '';
        slot.className = 'pl-track-playing-icon';
        slot.style.display = 'none';
      }

      const albumNum = !slot ? (li.querySelector('.track-index-cell .track-num') || li.querySelector('.text-muted.small')) : null;
      if (albumNum && albumNum.dataset.origText) {
        albumNum.textContent = albumNum.dataset.origText;
        delete albumNum.dataset.origText;
      }

      const btn = li.querySelector('.btn-track-play');

      if (tid === t.id && !rowInContext(li)) {
        // Stesso brano, contesto diverso: segnale leggero, nessuna pausa.
        // Il play della riga avvia questo contesto da questa traccia.
        li.classList.add('track-elsewhere');
        const titleEl = li.querySelector('.fw-semibold');
        if (titleEl) {
          titleEl.dataset.elsewhere = elsewhereLabel();
          // Il titolo qui non avvia nulla: il tooltip lo dice e indica
          // il pulsante che riproduce il brano dal disco.
          if (titleEl.hasAttribute('title') && titleEl.dataset.origTitle === undefined) {
            titleEl.dataset.origTitle = titleEl.title;
            titleEl.title = 'Brano ' + elsewhereLabel() + '. Per ascoltarlo da qui usa il pulsante play della riga.';
          }
        }
        if (btn) { btn.innerHTML = '<i class="bi bi-play-fill"></i>'; btn.title = 'Riproduci da qui'; }
      } else if (tid === t.id) {
        li.classList.add('track-playing');
        if (slot) {
          slot.innerHTML = '<span class="bar"></span><span class="bar"></span><span class="bar"></span>';
          slot.className = 'pl-track-playing-icon track-playing-icon';
          slot.style.display = '';
        } else if (albumNum) {
          albumNum.dataset.origText = albumNum.dataset.origText || albumNum.textContent;
          albumNum.innerHTML = '<span class="track-playing-icon" style="display:inline-flex">'
            + '<span class="bar"></span><span class="bar"></span><span class="bar"></span>'
            + '</span>';
        }
        if (btn) { btn.innerHTML = '<i class="bi bi-pause-fill"></i>'; btn.title = 'Pausa'; }
      } else {
        if (btn) { btn.innerHTML = '<i class="bi bi-play-fill"></i>'; btn.title = 'Ascolta'; }
      }
    });

    notifyChange();
  }

  function setPlaying(playing) {
    btnPlay.innerHTML = playing
      ? '<i class="bi bi-pause-fill"></i>'
      : '<i class="bi bi-play-fill"></i>';
    // Pausa/riprendi animazione barre
    document.querySelectorAll('.track-playing-icon').forEach(el => {
      el.classList.toggle('paused', !playing);
    });
    // Aggiorna icona pulsante sulla riga attiva
    const t = playlist[cursor];
    if (t) {
      document.querySelectorAll('.track-item').forEach(li => {
        const btn = li.querySelector('.btn-track-play');
        if (!btn) return;
        const tid = parseInt(li.dataset.trackId || 0);
        if (tid === t.id && rowInContext(li)) {
          btn.innerHTML = playing ? '<i class="bi bi-pause-fill"></i>' : '<i class="bi bi-play-fill"></i>';
          btn.title = playing ? 'Pausa' : 'Ascolta';
        }
      });
    }
    // Sincronizza lista playlist se visibile
    if (typeof window.__syncPlaylistListUI === 'function') {
      window.__syncPlaylistListUI();
    }
    // Notifica il cambio di stato play/pausa (usato dal pulsante hero
    // "Riproduci album" per i suoi tre stati). Riusa l'evento esistente.
    notifyChange();
  }
  // Carica tracce da album o playlist e avvia riproduzione
  function load(albumData, startIndex) {
    if (!albumData || !albumData.tracks || albumData.tracks.length === 0) return;
    // Ogni traccia entra in coda già "vestita" dei propri metadati
    // (artist, cover, albumId), ereditandoli dall'album se non li ha.
    // Così il queue-panel mostra SEMPRE l'artista sotto il titolo — sia
    // per le tracce dell'album di partenza che per quelle accodate da
    // altri album — e il link della cover può seguire la traccia corrente.
    // Copie shallow: non si mutano gli oggetti di window.__album.
    playlist = albumData.tracks.map(function (t) {
      if (!t) return t;
      return Object.assign({}, t, {
        artist:  t.artist  || albumData.artist || '',
        cover:   t.cover   || albumData.cover  || '',
        albumId: t.albumId || albumData.id     || null
      });
    });
    albumMeta = {
      id: albumData.id || null,
      playlistId: albumData.playlistId || null,
      cover: albumData.cover || '',
      artist: albumData.artist || ''
    };
    cursor = (startIndex !== undefined) ? startIndex : 0;
    // Aggiorna stato globale — fonte unica di verità
    if (!window.__PlaylistState) window.__PlaylistState = {};
    window.__PlaylistState.id = albumData.playlistId || null;
    window.__PlaylistState.tracks = playlist.slice();
    window.__PlaylistState.cursor = cursor;
    // Se è un album (non playlist) nascondi il contesto playlist nel player
    if (albumData.id && !albumData.playlistId) {
      if (window.PlaylistPlayer && typeof window.PlaylistPlayer.clearContext === 'function') {
        window.PlaylistPlayer.clearContext();
      }
    }
    playAt(cursor);
  }

  function playAt(index) {
    if (index < 0 || index >= playlist.length) return;

    // Cerca la prima traccia con src valido nella direzione richiesta
    // (necessario per lo skip automatico delle tracce senza file audio)
    const step = (index >= cursor || cursor === -1) ? 1 : -1;
    let target = -1;
    let i = index;
    while (i >= 0 && i < playlist.length) {
      if (playlist[i] && playlist[i].src) { target = i; break; }
      i += step;
    }

    // Nessuna traccia riproducibile trovata in quella direzione:
    if (target === -1) {
      resetPlayerState('no-playable-track');
      return;
    }
    cursor = target;
    const t = playlist[cursor];

    if (!t || !t.src) {
      return;
    }

    audio.src = t.src;
    audio.load();
    // Il righello si ricostruisce su loadedmetadata con la nuova durata
    clearRuler();
    safePlay();

    // Nota: lo stop del player YouTube non è più chiamato qui.
    // È gestito dalla regola unica nel listener 'play' dell'elemento audio,
    // che scatta quando safePlay() va effettivamente in riproduzione.

    updateUI();
    show();
  }

  // Esposto per la tracklist in detail.php
  function playTrack(trackId) {
    const idx = playlist.findIndex(t => t.id === trackId);
    if (idx !== -1) { playAt(idx); return; }
    // fallback: carica dall'album corrente se disponibile
    if (window.__album) { load(window.__album); }
  }

  // Accoda una traccia in fondo alla coda di riproduzione, anche se
  // appartiene a un album diverso da quello in riproduzione.
  // La traccia DEVE portarsi dietro i propri metadati (artist, cover):
  // updateUI e queue-panel usano t.artist/t.cover con fallback su
  // albumMeta, quindi una traccia "estranea" senza metadati propri
  // erediterebbe artista e cover sbagliati.
  //
  // track: { id, position, title, src, artist, cover, albumId? }
  // Ritorna: 'playing'   → player fermo, la traccia è partita subito
  //          'queued'    → aggiunta in fondo senza interrompere l'ascolto
  //          'duplicate' → già presente in coda (id duplicati romperebbero
  //                        playTrack e l'evidenziazione, quindi bloccati)
  //          'invalid'   → traccia senza src o senza id
  function enqueue(track) {
    if (!track || !track.src || !track.id) return 'invalid';

    // Player fermo / coda vuota → comportati come un load a traccia singola
    if (!playlist.length || cursor === -1) {
      load({
        id: track.albumId || null,
        cover: track.cover || '',
        artist: track.artist || '',
        tracks: [track]
      }, 0);
      return 'playing';
    }

    if (playlist.some(t => t && t.id === track.id)) return 'duplicate';

    playlist.push(track);

    // Sincronizza stato globale (stessa disciplina di setPlaylist)
    if (window.__PlaylistState) {
      window.__PlaylistState.tracks = playlist.slice();
      window.__PlaylistState.cursor = cursor;
    }

    notifyChange();
    return 'queued';
  }

  // Riempimento barre seek e volume con gradiente inline
  function updateFill(input, activeColor) {
    const pct = (parseFloat(input.value) / parseFloat(input.max || 100)) * 100;
    input.style.background =
      `linear-gradient(to right, ${activeColor} ${pct}%, rgba(255,255,255,0.15) ${pct}%)`;
  }

  // -------------------------------------------------------
  // Barra di avanzamento, tempi, righello, ripeti
  // -------------------------------------------------------

  // Riempimento della barra: ascoltato in ambra, porzione già caricata
  // appena più chiara, resto della traccia sul fondo.
  function seekFill() {
    if (!seek) return;
    const d = audio.duration;
    const hasDur = !!d && isFinite(d);
    const pct = hasDur
      ? Math.min(100, (audio.currentTime / d) * 100)
      : (parseFloat(seek.value) / SEEK_MAX) * 100;
    let buf = pct;
    if (hasDur && audio.buffered) {
      for (let i = 0; i < audio.buffered.length; i++) {
        if (audio.buffered.start(i) <= audio.currentTime + 0.5 &&
          audio.buffered.end(i) >= audio.currentTime) {
          buf = Math.max(buf, Math.min(100, (audio.buffered.end(i) / d) * 100));
        }
      }
    }
    seek.style.background =
      'linear-gradient(to right, ' +
      ACCENT_DEEP + ' 0%, ' + ACCENT + ' ' + pct + '%, ' +
      'rgba(255,255,255,0.16) ' + pct + '%, rgba(255,255,255,0.16) ' + buf + '%, ' +
      'rgba(255,255,255,0.09) ' + buf + '%)';
  }

  // Tempo corrente a sinistra; a destra durata oppure tempo residuo
  function renderTimes() {
    const d = audio.duration;
    if (!d || !isFinite(d)) return;
    const cur = Math.min(audio.currentTime, d);
    if (timeCur) timeCur.textContent = fmt(cur);
    if (timeDur) timeDur.textContent = showRemaining ? '−' + fmt(d - cur) : fmt(d);
  }

  function updateTimeToggleLabel() {
    if (!timeToggle) return;
    const label = showRemaining
      ? 'Tempo residuo. Mostra la durata'
      : 'Durata. Mostra il tempo residuo';
    timeToggle.title = label;
    timeToggle.setAttribute('aria-label', label);
  }

  // Righello: una tacca per minuto sotto la barra. Per brani lunghi
  // il passo sale a 5 o 10 minuti, così le tacche restano leggibili.
  function clearRuler() {
    rulerTicks = [];
    if (ruler) ruler.innerHTML = '';
  }

  function buildRuler() {
    clearRuler();
    const d = audio.duration;
    if (!ruler || !d || !isFinite(d)) return;
    const step = d <= 1200 ? 60 : (d <= 3600 ? 300 : 600);
    const frag = document.createDocumentFragment();
    for (let at = step; at < d - 1; at += step) {
      const left = ((at / d) * 100) + '%';
      const tick = document.createElement('span');
      tick.className = 'sp-tick';
      tick.style.left = left;
      const label = document.createElement('span');
      label.className = 'sp-tick-label';
      label.style.left = left;
      label.textContent = String(at / 60);
      frag.appendChild(tick);
      frag.appendChild(label);
      rulerTicks.push({ el: tick, at: at });
    }
    ruler.appendChild(frag);
    updateTicks();
  }

  function updateTicks() {
    const t = audio.currentTime;
    for (let i = 0; i < rulerTicks.length; i++) {
      rulerTicks[i].el.classList.toggle('is-past', t >= rulerTicks[i].at);
    }
  }

  // Etichetta di "precedente" coerente con quello che farà il clic
  function updatePrevLabel() {
    if (!btnPrev) return;
    const restarts = !!audio.src && audio.currentTime > RESTART_THRESHOLD;
    if (restarts === prevRestarts) return;
    prevRestarts = restarts;
    const label = restarts ? 'Ricomincia la traccia' : 'Traccia precedente';
    btnPrev.title = label;
    btnPrev.setAttribute('aria-label', label);
  }

  // Aggiorna barra, tempi e righello dopo uno spostamento della posizione
  function syncPosition() {
    if (seek && audio.duration && isFinite(audio.duration)) {
      seek.value = Math.round((audio.currentTime / audio.duration) * SEEK_MAX);
    }
    seekFill();
    renderTimes();
    updateTicks();
    updatePrevLabel();
  }

  // Riporta la traccia corrente a 0:00 mantenendo play o pausa
  function restartCurrent() {
    try { audio.currentTime = 0; } catch (e) { }
    syncPosition();
    if (timeCur) {
      timeCur.classList.remove('sp-restart');
      void timeCur.offsetWidth;
      timeCur.classList.add('sp-restart');
      setTimeout(function () { timeCur.classList.remove('sp-restart'); }, 650);
    }
  }

  // Prima traccia riproducibile della coda (per "ripeti la coda")
  function firstPlayable() {
    for (let i = 0; i < playlist.length; i++) {
      if (playlist[i] && playlist[i].src) return i;
    }
    return -1;
  }

  // Fine coda senza ripetizione: il player resta caricato sull'ultima
  // traccia, in pausa a 0:00, così play la riascolta. Lo stop resta
  // il modo per chiuderlo.
  function endOfQueue() {
    try { audio.pause(); } catch (e) { }
    try { audio.currentTime = 0; } catch (e) { }
    syncPosition();
    notifyChange();
  }

  // Ripeti la traccia usa audio.loop: a fine brano il browser riparte
  // da 0:00 senza emettere 'ended', quindi coda, liste e pannello non
  // vedono alcun cambio di traccia. Precedente/successiva funzionano
  // normalmente perché passano da playAt().
  function applyRepeat() {
    audio.loop = (repeatMode === 'one');
    if (!btnRepeat) return;
    const label = REPEAT_LABELS[repeatMode];
    btnRepeat.dataset.mode = repeatMode;
    btnRepeat.classList.toggle('is-on', repeatMode !== 'off');
    btnRepeat.setAttribute('aria-pressed', repeatMode !== 'off' ? 'true' : 'false');
    btnRepeat.title = label;
    btnRepeat.setAttribute('aria-label', label);
  }

  function hideHover() {
    if (hoverLine) hoverLine.hidden = true;
    if (hoverTip) hoverTip.hidden = true;
  }

  function ratioAt(e) {
    const rect = seek.getBoundingClientRect();
    if (!rect.width) return null;
    return Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
  }

  // Audio events
  audio.addEventListener('play', () => {
    // Regola unica di mutua esclusione: qualsiasi avvio dell'audio nativo
    // (btnPlay, spacebar, toggle traccia, playAt) chiude il player YouTube.
    // L'evento 'play' scatta per ogni play() da qualsiasi punto del codice,
    // quindi nessun entry-point presente o futuro può bypassare la regola.
    if (window.YTPlayer && typeof window.YTPlayer.stopVideo === 'function') {
      window.YTPlayer.stopVideo();
    }
    setPlaying(true);
  });
  audio.addEventListener('pause', () => setPlaying(false));
  audio.addEventListener('ended', () => {
    // Cerca la prossima traccia con src valido, saltando quelle senza file audio.
    // Se non esiste, la playlist è davvero conclusa.
    let next = cursor + 1;

    while (next < playlist.length && !(playlist[next] && playlist[next].src)) {
      next++;
    }

    if (next < playlist.length) {
      playAt(next);
      return;
    }

    // Ripeti la coda: riparte dalla prima traccia con file audio
    if (repeatMode === 'all') {
      const first = firstPlayable();
      if (first !== -1) {
        playAt(first);
        return;
      }
    }

    endOfQueue();
  });
  audio.addEventListener('timeupdate', () => {
    if (!audio.duration) return;
    syncPosition();
  });
  audio.addEventListener('loadedmetadata', () => {
    renderTimes();
    buildRuler();
    seekFill();
  });
  // Avanzamento del buffer: aggiorna la porzione già caricata
  audio.addEventListener('progress', seekFill);

  // Controls
  btnPlay.addEventListener('click', () => {
    if (!audio.src) return;
    audio.paused ? safePlay() : audio.pause();
  });
  btnPrev.addEventListener('click', () => {
    // Oltre i primi secondi "precedente" riavvia la traccia corrente:
    // un clic la riascolta, due clic ravvicinati tornano indietro.
    if (audio.src && audio.currentTime > RESTART_THRESHOLD) {
      restartCurrent();
      return;
    }
    // Cerca indietro la precedente traccia con src valido
    let prev = cursor - 1;
    while (prev >= 0 && !(playlist[prev] && playlist[prev].src)) { prev--; }
    if (prev >= 0) {
      playAt(prev);
    } else if (audio.src) {
      // Prima traccia della coda: riparte da 0:00
      restartCurrent();
    }
  });
  btnNext.addEventListener('click', () => {
    // Cerca avanti la prossima traccia con src valido
    let next = cursor + 1;
    while (next < playlist.length && !(playlist[next] && playlist[next].src)) { next++; }
    if (next < playlist.length) playAt(next);
  });
  btnStop.addEventListener('click', () => resetPlayerState('stopped'));

  seek.addEventListener('input', () => {
    if (audio.duration) audio.currentTime = (seek.value / SEEK_MAX) * audio.duration;
    seekFill();
    renderTimes();
    updateTicks();
    updatePrevLabel();
  });

  // Tap-to-seek per touch: iOS Safari non sposta il valore di un
  // range al tap sulla track (richiede il drag del thumb), ma sulla
  // progress line mobile il thumb è invisibile. Il tap posiziona
  // direttamente; il drag successivo prosegue nativo perché il thumb
  // finisce sotto il dito. Limitato a pointerType touch: col mouse
  // il click sulla track funziona già nativamente.
  seek.addEventListener('pointerdown', (e) => {
    if (e.pointerType !== 'touch') return;
    if (!audio.duration) return;
    const rect = seek.getBoundingClientRect();
    if (!rect.width) return;
    let ratio = (e.clientX - rect.left) / rect.width;
    ratio = Math.min(1, Math.max(0, ratio));
    seek.value = Math.round(ratio * SEEK_MAX);
    audio.currentTime = ratio * audio.duration;
    seekFill();
    renderTimes();
    updateTicks();
    updatePrevLabel();
  });

  // Area attorno alla barra (alta 44px): con il mouse il clic sposta la
  // posizione anche fuori dai 6px della traccia, e il passaggio mostra
  // linea guida e tempo del punto indicato. Sull'input il clic nativo
  // funziona già e non viene toccato. Solo mouse: su touch vale il
  // tap-to-seek qui sopra.
  if (seekWrap) {
    seekWrap.addEventListener('pointermove', (e) => {
      if (e.pointerType !== 'mouse' || !hoverLine || !hoverTip) return;
      const d = audio.duration;
      const ratio = ratioAt(e);
      if (!d || !isFinite(d) || ratio === null) { hideHover(); return; }
      const left = (ratio * 100) + '%';
      hoverLine.style.left = left;
      hoverTip.style.left = left;
      hoverTip.textContent = fmt(ratio * d);
      hoverLine.hidden = false;
      hoverTip.hidden = false;
    });
    seekWrap.addEventListener('pointerleave', hideHover);
    seekWrap.addEventListener('pointerdown', (e) => {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      if (e.target === seek) return;
      const d = audio.duration;
      if (!d || !isFinite(d)) return;
      const ratio = ratioAt(e);
      if (ratio === null) return;
      audio.currentTime = ratio * d;
      syncPosition();
    });
  }

  // Ripeti: disattivato → coda → traccia → disattivato
  if (btnRepeat) {
    btnRepeat.addEventListener('click', () => {
      const i = REPEAT_MODES.indexOf(repeatMode);
      repeatMode = REPEAT_MODES[(i + 1) % REPEAT_MODES.length];
      writePref(REPEAT_KEY, repeatMode);
      applyRepeat();
    });
  }

  // Clic sul tempo a destra: alterna durata e tempo residuo
  if (timeToggle) {
    timeToggle.addEventListener('click', () => {
      showRemaining = !showRemaining;
      writePref(REMAINING_KEY, showRemaining ? '1' : '0');
      updateTimeToggleLabel();
      renderTimes();
    });
  }

  // Stato iniziale da preferenze salvate
  applyRepeat();
  updateTimeToggleLabel();
  updatePrevLabel();

  // Volume
  const volSlider = document.getElementById('sp-volume');
  const btnMute = document.getElementById('sp-mute');
  let lastVol = 1;

  if (volSlider) {
    volSlider.value = 100; // inizializza al massimo
    updateFill(volSlider, 'rgba(255,255,255,0.55)');
    volSlider.addEventListener('input', () => {
      audio.volume = volSlider.value / 100;
      updateVolIcon(audio.volume);
      updateFill(volSlider, 'rgba(255,255,255,0.55)');
    });
  }

  if (btnMute) {
    btnMute.addEventListener('click', () => {
      if (audio.volume > 0) {
        lastVol = audio.volume;
        audio.volume = 0;
        if (volSlider) volSlider.value = 0;
      } else {
        audio.volume = lastVol;
        if (volSlider) volSlider.value = Math.round(lastVol * 100);
      }
      updateVolIcon(audio.volume);
      if (volSlider) updateFill(volSlider, 'rgba(255,255,255,0.55)');
    });
  }

  function updateVolIcon(v) {
    if (!btnMute) return;
    if (v === 0) btnMute.innerHTML = '<i class="bi bi-volume-mute-fill"></i>';
    else if (v < 0.4) btnMute.innerHTML = '<i class="bi bi-volume-down-fill"></i>';
    else btnMute.innerHTML = '<i class="bi bi-volume-up-fill"></i>';
  }

  return {
    load,
    playTrack,
    enqueue,
    refreshUI: updateUI,
    currentSrc: () => audio.src,
    currentIndex: () => cursor,
    currentTrackId: () => (playlist[cursor] ? playlist[cursor].id : null),
    // Contesto caricato: "playlist:ID", "album:ID" oppure '' se fermo.
    context: () => (cursor === -1 ? '' : currentContext()),
    // Stato play/pausa e traccia corrente: usati dal pulsante hero
    // "Riproduci album" per calcolare i suoi tre stati.
    isPlaying: () => !!(audio.src && !audio.paused),
    hasTrackLoaded: () => !!audio.src,
    currentTrack: () => (playlist[cursor] || null),
    // Toggle play/pausa dall'esterno (pulsante hero). Non avvia nulla se
    // non c'è una sorgente caricata: in quel caso il chiamante fa load().
    togglePlay: () => {
      if (!audio.src) return;
      if (audio.paused) { safePlay(); } else { audio.pause(); }
    },
    // Restituisce copia dell'array playlist corrente (per reorderQueue)
    getPlaylist: () => playlist.slice(),
    // Sostituisce la playlist in memoria mantenendo cursor sulla traccia corrente.
    // Aggiorna anche window.__PlaylistState.tracks come fonte unica di verità.
    setPlaylist: (newList) => {
      if (!newList || !newList.length) {
        playlist = [];
        cursor = -1;

        if (window.__PlaylistState) {
          window.__PlaylistState.tracks = [];
          window.__PlaylistState.cursor = -1;
        }

        notifyChange();
        return;
      }

      const currentId = playlist[cursor] ? playlist[cursor].id : null;

      playlist = newList;

      if (currentId !== null) {
        const newIdx = playlist.findIndex(t => t && t.id === currentId);

        if (newIdx !== -1) {
          cursor = newIdx;
        } else {
          // La traccia corrente è stata rimossa dalla playlist.
          // Mantiene il cursore dentro i limiti per evitare riferimenti invalidi.
          cursor = Math.min(cursor, playlist.length - 1);
        }
      } else {
        cursor = Math.min(Math.max(cursor, 0), playlist.length - 1);
      }

      if (cursor < 0 || cursor >= playlist.length) {
        cursor = playlist.length ? 0 : -1;
      }

      // Sincronizza stato globale
      if (window.__PlaylistState) {
        window.__PlaylistState.tracks = playlist.slice();
        window.__PlaylistState.cursor = cursor;
      }

      notifyChange();
    }
  };
})();

// -------------------------------------------------------
// Spacebar → toggle play/pause del player sticky
// Lo spazio resta al campo attivo solo dove serve scrivere o
// spuntare (campi di testo, textarea, select, checkbox, elementi
// editabili). Su barra di avanzamento, volume, pulsanti e link fa
// solo play/pausa:
// - se un componente ha già gestito il tasto (es. riga della coda,
//   che chiama preventDefault) non si interviene, evitando il doppio
//   comando;
// - l'elemento attivo perde il focus: Chrome attiva i pulsanti con lo
//   spazio al rilascio del tasto (keyup), quindi senza blur il pulsante
//   cliccato per ultimo ripremerebbe play/pausa annullando la pausa, e
//   il contorno di focus resterebbe acceso;
// - tenendo premuto lo spazio non si alterna play/pausa a raffica.
// -------------------------------------------------------
document.addEventListener('keydown', function (e) {
  if (!(e.code === 'Space' || e.key === ' ')) return;
  if (e.defaultPrevented) return;
  if (e.ctrlKey || e.metaKey || e.altKey) return;

  var el = document.activeElement;
  if (el && el.isContentEditable) return;
  var tag = el ? el.tagName : '';
  if (tag === 'TEXTAREA' || tag === 'SELECT') return;
  // Tra gli input solo lo slider (seek, volume) passa al player
  if (tag === 'INPUT' && String(el.type).toLowerCase() !== 'range') return;

  var playerPanel = document.getElementById('sticky-player');
  // Agisce solo se il player è visibile (audio caricato)
  if (!playerPanel || playerPanel.style.display === 'none') return;

  e.preventDefault(); // evita lo scroll della pagina e lo step dello slider
  if (e.repeat) return;

  if (el && el !== document.body && typeof el.blur === 'function') el.blur();

  var btnPlayPause = document.getElementById('sp-play-pause');
  if (btnPlayPause) btnPlayPause.click();
});

// -------------------------------------------------------
// Righe "in ascolto da …" (stesso brano, altra coda in riproduzione).
// L'etichetta è solo informativa: un clic sulla riga o sull'etichetta
// non deve sostituire la coda in ascolto (mix o playlist) con il disco.
// Restano attivi i pulsanti e i link della riga: il play "Riproduci da
// qui" avvia il disco da questa traccia in modo esplicito. La regola è
// in fase di cattura, quindi vale per qualsiasi gestore di clic sulla
// riga, presente o futuro.
// -------------------------------------------------------
// Nella scheda disco il titolo è un pulsante (.track-title-play) che
// inoltra il clic al play della riga, e l'etichetta è disegnata dentro
// il titolo: per questo il titolo è bloccato come il resto della riga.
// In modalità selezione il titolo spunta la casella e resta attivo.
document.addEventListener('click', function (e) {
  var row = e.target.closest ? e.target.closest('.track-item.track-elsewhere') : null;
  if (!row) return;
  if (row.closest('.selection-mode')) return;
  if (e.target.closest('button:not(.track-title-play), a, input, select, textarea, label, .dropdown-menu')) return;
  e.preventDefault();
  e.stopPropagation();
}, true);

// -------------------------------------------------------
// Audio players sulla tracklist (mini pulsanti play)
// Chiamato sia all'avvio che dopo ogni navigazione SPA
// -------------------------------------------------------
function initTracklistPlayers() {
  // Sostituisce i <audio controls> nativi con pulsanti play
  // che delegano al Player globale
  document.querySelectorAll('audio.track-player').forEach(audioEl => {
    const src = audioEl.querySelector('source')?.src;
    const item = audioEl.closest('.track-item');
    if (!item || !src) return;

    // Ricava track_id dal form di delete o da data-attribute
    const trackId = parseInt(item.dataset.trackId || 0);

    // Crea bottone play/pause delegato
    const btn = document.createElement('button');
    btn.className = 'btn btn-xs btn-outline-warning btn-track-play';
    btn.title = 'Ascolta';
    btn.innerHTML = '<i class="bi bi-play-fill"></i>';
    btn.addEventListener('click', () => {
      if (!window.__album) return;
      const idx = window.__album.tracks.findIndex(t => t.src === src);
      const audio = document.getElementById('global-audio');
      // Toggle play/pausa solo se la traccia è già in ascolto E il
      // contesto caricato è questo album. Se lo stesso brano suona da
      // una playlist, il clic avvia l'album da questa traccia.
      const sameContext = (typeof Player.context !== 'function') ||
        Player.context() === 'album:' + window.__album.id;
      if (audio && audio.src === src && sameContext) {
        if (audio.paused) {
          audio.play().catch(function (err) {
            console.warn('[Player] Impossibile riprendere audio:', err.message);
          });
        } else {
          audio.pause();
        }
      } else {
        Player.load(window.__album, idx);
      }
    });

    audioEl.parentElement.insertBefore(btn, audioEl);
    audioEl.style.display = 'none'; // nasconde il player nativo
  });

  // Pulsanti Play sulle <li> traccia se c'è audio disponibile
  document.querySelectorAll('[data-play-src]').forEach(el => {
    el.addEventListener('click', () => {
      if (window.__album) {
        const src = el.dataset.playSrc;
        const idx = window.__album.tracks.findIndex(t => t.src === src);
        Player.load(window.__album, idx >= 0 ? idx : 0);
      }
    });
  });
}

// -------------------------------------------------------
// Dashboard live refresh
// -------------------------------------------------------
// Il worker media modifica il DB in background. Finché la dashboard è aperta,
// questo modulo interroga un endpoint leggero e, solo se cambia il numero/ultimo
// album, rigenera tre blocchi: statistiche, album recenti e top artisti.
// Sticky player, playlist e resto della pagina non vengono sostituiti.
const DashboardLiveRefresh = (function () {
  const POLL_MS = 10000;

  let timer = null;
  let revision = null;
  let checking = false;
  let generation = 0;
  let userScrolling = false;
  let scrollIdleTimer = null;

  function isDashboard() {
    return !!(
      document.getElementById('dashboard-statband') &&
      document.getElementById('dashboard-recent-panel') &&
      document.getElementById('dashboard-top-artists')
    );
  }

  function stop() {
    generation += 1;
    if (timer !== null) {
      clearInterval(timer);
      timer = null;
    }
    if (scrollIdleTimer !== null) {
      clearTimeout(scrollIdleTimer);
      scrollIdleTimer = null;
    }
    userScrolling = false;
    revision = null;
    checking = false;
    document.body.classList.remove('grz-dashboard-page');
  }

  async function fetchState() {
    const res = await fetch(BASE_URL + '/index.php?route=dashboard/state', {
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    if (!res.ok) {
      throw new Error('dashboard/state HTTP ' + res.status);
    }

    const data = await res.json();
    if (!data || !data.ok || !data.revision) {
      throw new Error((data && data.error) || 'Stato dashboard non valido');
    }

    return data;
  }

  async function refreshFragments(expectedGeneration) {
    const res = await fetch(BASE_URL + '/index.php?route=dashboard', {
      cache: 'no-store',
      headers: { 'X-SPA': '1' }
    });

    if (!res.ok) {
      throw new Error('dashboard HTTP ' + res.status);
    }

    const html = await res.text();

    // Nel frattempo l'utente potrebbe essere uscito dalla dashboard.
    if (expectedGeneration !== generation || !isDashboard()) return false;

    // Non sostituire blocchi DOM mentre l'utente sta scorrendo: il refresh
    // può cambiare l'altezza del contenuto sopra il viewport e produrre uno
    // scatto visibile. La firma resta invariata e verrà riprovata a scroll fermo.
    if (userScrolling) return false;

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const ids = [
      'dashboard-statband',
      'dashboard-recent-panel',
      'dashboard-top-artists'
    ];

    ids.forEach(function (id) {
      const current = document.getElementById(id);
      const fresh = doc.getElementById(id);
      if (current && fresh) {
        current.replaceWith(fresh);
      }
    });

    // La griglia recente ha comportamento JS ("Mostra altri" + resize).
    // Va riagganciato dopo la sostituzione del suo DOM.
    if (typeof window.__initDashboardGrid === 'function') {
      window.__initDashboardGrid();
    }

    // Il blocco playlist non viene sostituito, ma manteniamo comunque
    // coerente l'eventuale stato visuale del player.
    if (typeof window.__syncPlaylistListUI === 'function') {
      window.__syncPlaylistListUI();
    }

    document.dispatchEvent(new CustomEvent('dashboard:refreshed'));
    return true;
  }

  async function check() {
    if (!isDashboard() || document.visibilityState === 'hidden' || checking || userScrolling) {
      return;
    }

    checking = true;
    const thisGeneration = generation;

    try {
      const state = await fetchState();

      if (thisGeneration !== generation || !isDashboard()) return;

      // Prima lettura: stabilisce il baseline senza ricaricare niente.
      if (revision === null) {
        revision = state.revision;
        return;
      }

      if (state.revision !== revision) {
        // Aggiorna la firma solo DOPO una sostituzione DOM completata.
        // Se nel frattempo parte lo scroll, refreshFragments() restituisce
        // false e al primo momento di inattività il cambio viene riprovato.
        const nextRevision = state.revision;
        const refreshed = await refreshFragments(thisGeneration);
        if (refreshed) {
          revision = nextRevision;
        }
      }
    } catch (err) {
      // Un errore di rete non deve mai rompere la dashboard o il player.
      console.warn('[Dashboard live refresh]', err.message);
    } finally {
      if (thisGeneration === generation) {
        checking = false;
      }
    }
  }

  function sync() {
    if (!isDashboard()) {
      stop();
      return;
    }

    document.body.classList.add('grz-dashboard-page');

    if (timer !== null) return;

    generation += 1;
    revision = null;
    checking = false;

    // Baseline immediato e poi polling leggero.
    check();
    timer = setInterval(check, POLL_MS);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && isDashboard()) {
      check();
    }
  });

  // Su mobile la barra indirizzi può generare resize durante lo scroll; inoltre
  // il live refresh non deve mai rimpiazzare la griglia mentre il dito/trackpad
  // sta muovendo la pagina. Il listener è passive e fa solo da debounce.
  window.addEventListener('scroll', function () {
    if (!isDashboard()) return;

    userScrolling = true;

    if (scrollIdleTimer !== null) {
      clearTimeout(scrollIdleTimer);
    }

    scrollIdleTimer = setTimeout(function () {
      scrollIdleTimer = null;
      userScrolling = false;
      check();
    }, 220);
  }, { passive: true });

  return {
    sync: sync,
    stop: stop,
    check: check
  };
})();

window.__syncDashboardLiveRefresh = DashboardLiveRefresh.sync;

// -------------------------------------------------------
// SPA-lite Navigation
// Intercetta tutti i link interni, sostituisce solo <main>
// -------------------------------------------------------
(function initSPANav() {
  const MAIN_ID = 'page-content';

  function isInternal(url) {
    try {
      const u = new URL(url, location.href);
      // Escludi link a file audio/immagini e azioni POST
      if (/\.(mp3|jpg|jpeg|png|webp|gif|pdf|csv)$/i.test(u.pathname)) return false;
      return u.origin === location.origin;
    } catch { return false; }
  }

  async function navigate(url, push = true) {
    try {
      const res = await fetch(url, { headers: { 'X-SPA': '1' } });
      const html = await res.text();

      const parser = new DOMParser();
      const newDoc = parser.parseFromString(html, 'text/html');
      const newMain = newDoc.getElementById(MAIN_ID);

      if (!newMain) { location.href = url; return; }

      // Aggiorna <main>
      const main = document.getElementById(MAIN_ID);
      main.innerHTML = newMain.innerHTML;

      // Aggiorna <title>
      const newTitle = newDoc.querySelector('title');
      if (newTitle) document.title = newTitle.textContent;

      // Voce di menu corrente: la navbar è fuori da <main> e non viene
      // sostituita, quindi si copia aria-current dalla pagina ricevuta
      // (calcolato in header.php dalla route).
      const newNavLinks = newDoc.querySelectorAll('#mainNav .nav-link');
      document.querySelectorAll('#mainNav .nav-link').forEach(function (link, i) {
        const src = newNavLinks[i];
        if (src && src.hasAttribute('aria-current')) {
          link.setAttribute('aria-current', src.getAttribute('aria-current'));
        } else {
          link.removeAttribute('aria-current');
        }
      });

      // Esegui script iniettati (window.__album incluso)
      main.querySelectorAll('script').forEach(oldScript => {
        const s = document.createElement('script');
        if (oldScript.src) { s.src = oldScript.src; s.async = false; }
        else s.textContent = oldScript.textContent;
        document.head.appendChild(s).parentNode.removeChild(s);
      });

      // Re-inizializza componenti Bootstrap e player
      initTracklistPlayers();
      reinitBootstrap();

      // Avvia/ferma il polling della dashboard in base alla pagina corrente.
      // Se siamo usciti dalla dashboard il timer viene eliminato.
      DashboardLiveRefresh.sync();

      // Estrazione colore dominante dell'hero scheda disco (se presente).
      // Va richiamata a ogni navigazione SPA perche' il contenuto e' stato
      // rigenerato: senza questa riga il colore si applicava solo dopo un
      // refresh completo della pagina.
      if (typeof window.__applyAlbumHero === 'function') {
        window.__applyAlbumHero();
      }

      // Sincronizza i tre stati del pulsante hero "Riproduci album"
      // (play / pausa / riprendi) col Player, dopo la rigenerazione SPA.
      if (typeof window.__syncAlbumHeroPlayBtn === 'function') {
        window.__syncAlbumHeroPlayBtn();
      }

      // Re-aggancia bottoni YouTube dopo ogni navigazione SPA
      if (window.YTPlayer && typeof window.YTPlayer.rebind === 'function') {
        window.YTPlayer.rebind();
      }

      // Ripristina visibilità player se c'è una traccia caricata (in play o in pausa)
      const audioEl = document.getElementById('global-audio');
      const panelEl = document.getElementById('sticky-player');
      if (audioEl && panelEl && audioEl.src) {
        panelEl.style.display = 'flex';
      }

      // Ripristina evidenziazione traccia in riproduzione dopo navigazione SPA.
      // Chiamata sempre se il Player esiste e c'è una traccia caricata —
      // funziona sia per pagine album (window.__album) che per pagine playlist.
      if (typeof Player !== 'undefined') {
        const audioEl2 = document.getElementById('global-audio');
        if (audioEl2 && audioEl2.src) {
          Player.refreshUI();
        }
      }

      if (push) history.pushState({ url }, '', url);

      window.scrollTo({ top: 0, behavior: 'smooth' });

    } catch (err) {
      console.warn('SPA nav fallback:', err);
      location.href = url;
    }
  }

  // Espone navigate globalmente per uso esterno (es. delete form)
  window._spaNavigate = navigate;

  // Intercetta click su link
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href]');
    if (!a) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
    // Non intercettare link con target o download
    if (a.target && a.target !== '_self') return;
    if (a.hasAttribute('download')) return;
    if (!isInternal(href)) return;

    e.preventDefault();
    navigate(href);
  });

  // Pulsante back/forward del browser
  window.addEventListener('popstate', (e) => {
    navigate(location.href, false);
  });

  // Intercetta submit dei form GET (filtri, per_page) via SPA
  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form');
    if (!form) return;
    // Solo form GET, non POST (upload, salvataggio album ecc.)
    if (form.method && form.method.toLowerCase() === 'post') return;
    // Non intercettare form con enctype multipart
    if (form.enctype && form.enctype.includes('multipart')) return;

    e.preventDefault();
    const url = form.action + (form.action.includes('?') ? '&' : '?') +
      new URLSearchParams(new FormData(form)).toString();
    navigate(url);
  });

  // Intercetta submit del form album (POST multipart) via fetch AJAX
  // così il player non viene interrotto durante salvataggio/modifica disco
  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form#albumForm');
    if (!form) return;

    e.preventDefault();

    const formData = new FormData(form);
    const action = form.action;

    // Mostra spinner sul bottone submit
    const submitBtn = form.querySelector('[type="submit"]');
    const originalHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvataggio…';
    }

    fetch(action, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then(r => r.json())
      .then(data => {
        if (data.redirect) {
          // Naviga via SPA alla pagina dettaglio — player non viene interrotto
          navigate(data.redirect);
        } else if (data.errors) {
          // Errori di validazione — ripristina bottone e mostra errori
          if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHtml; }
          showFormErrors(data.errors);
        }
      })
      .catch(() => {
        // Fallback: submit tradizionale
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHtml; }
        form.submit();
      });
  });

  // Init al caricamento iniziale
  initTracklistPlayers();
  DashboardLiveRefresh.sync();
})();

// Intercetta submit del form di eliminazione disco via AJAX — player non si interrompe
document.addEventListener('submit', (e) => {
  const form = e.target.closest('form');
  if (!form || form.id === 'albumForm') return;
  if (!form.action || !form.action.includes('albums/delete')) return;
  e.preventDefault();

  fetch(form.action, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(form)
  })
    .then(() => {
      const modalEl = document.getElementById('deleteModal');

      function doNavigate() {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        if (window._spaNavigate) window._spaNavigate(BASE_URL + '/index.php?route=albums/list');
        else location.href = BASE_URL + '/index.php?route=albums/list';
      }

      if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', doNavigate, { once: true });
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
        else doNavigate();
      } else {
        doNavigate();
      }
    })
    .catch(() => form.submit());
});

// Mostra errori validazione form album senza ricaricare la pagina
function showFormErrors(errors) {
  document.querySelectorAll('.ajax-form-error').forEach(el => el.remove());
  document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
  document.querySelectorAll('.invalid-feedback').forEach(el => el.remove());

  const form = document.getElementById('albumForm');
  if (!form) return;

  // Alert generale in cima
  const alert = document.createElement('div');
  alert.className = 'alert alert-danger alert-dismissible ajax-form-error';
  alert.innerHTML = '<strong><i class="bi bi-exclamation-triangle me-2"></i>Correggi gli errori:</strong>'
    + '<ul class="mb-0 mt-1">'
    + Object.values(errors).map(e => `<li>${e}</li>`).join('')
    + '</ul>'
    + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
  form.insertBefore(alert, form.firstChild);

  // Marca i singoli campi in rosso
  const fieldMap = {
    'title': '[name="title"]',
    'format_id': '[name="format_id"]',
    'artist': '#artistAutocomplete',
    'year': '[name="year"]',
  };

  Object.keys(errors).forEach(key => {
    const selector = fieldMap[key];
    if (!selector) return;
    selector.split(',').forEach(sel => {
      const el = form.querySelector(sel.trim());
      if (!el) return;
      el.classList.add('is-invalid');
      // Aggiunge messaggio sotto il campo se non esiste già
      const msg = document.createElement('div');
      msg.className = 'invalid-feedback d-block ajax-form-error';
      msg.textContent = errors[key];
      el.parentNode.appendChild(msg);
    });
  });

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function reinitBootstrap() {
  // Pre-inizializza SOLO i modal aperti da data-bs-toggle (attributo dichiarativo).
  // I modal gestiti via JS puro sono in SKIP_MODALS e vengono ignorati qui.
  const SKIP_MODALS = ['addToPlaylistModal', 'deleteAudioModal', 'removeTrackModal'];
  document.querySelectorAll('[data-bs-toggle="modal"]').forEach(el => {
    const targetId = el.dataset.bsTarget?.replace('#', '');
    if (!targetId || SKIP_MODALS.includes(targetId)) return;
    const targetEl = document.getElementById(targetId);
    if (targetEl) bootstrap.Modal.getOrCreateInstance(targetEl);
  });

  // deleteModal: gestito dal listener globale shown.bs.modal a inizio file.
}