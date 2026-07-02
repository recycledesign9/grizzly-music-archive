/* ============================================================
Music Archive — app.js
SPA-lite navigation + Sticky Audio Player
============================================================ */

const BASE_URL = document.querySelector('meta[name="base-url"]')?.content ?? '';
// -------------------------------------------------------
// Dark mode toggle — applica tema salvato all'avvio
// I listener sui bottoni sono in header.php inline script
// -------------------------------------------------------
(function initDarkMode() {
  const stored = localStorage.getItem('theme');
  if (stored) {
    document.documentElement.setAttribute('data-bs-theme', stored);
  }
})();

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

  if (!audio || !panel) return { load: () => { }, playTrack: () => { } };

  let playlist = [];   // array di tracce con src
  let cursor = -1;   // indice corrente
  let albumMeta = {};   // { cover, artist }

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
      updateFill(seek, '#ffc107');
    }

    if (timeCur) timeCur.textContent = '0:00';
    if (timeDur) timeDur.textContent = '0:00';

    if (btnPlay) {
      btnPlay.innerHTML = '<i class="bi bi-play-fill"></i>';
    }

    document.querySelectorAll('.track-item').forEach(function (li) {
      li.classList.remove('track-playing');

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

      var posSpan = li.querySelector('.text-muted.small');
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
    artistEl.textContent = albumMeta.artist || '';
    coverImg.src = t.cover || albumMeta.cover || '';
    document.title = t.title + ' — ' + (albumMeta.artist || '') + ' · Music Archive';

    const coverLink = document.getElementById('sp-cover-link');
    if (coverLink) {
      if (albumMeta.playlistId) {
        coverLink.href = BASE_URL + '/index.php?route=playlists/detail/' + albumMeta.playlistId;
        coverLink.title = 'Vai alla playlist';
      } else if (albumMeta.id) {
        coverLink.href = BASE_URL + '/index.php?route=albums/detail/' + albumMeta.id;
        coverLink.title = 'Vai al disco';
      }
    }

    // Evidenzia riga attiva nella tracklist e aggiorna pulsanti play/pause
    document.querySelectorAll('.track-item').forEach(li => {
      const tid = parseInt(li.dataset.trackId || 0);
      li.classList.remove('track-playing');
      // Pulisce l'icona: svuota lo slot statico (playlist) o rimuove l'icona inserita (album)
      const oldSlot = li.querySelector('.pl-track-playing-icon');
      if (oldSlot) {
        oldSlot.innerHTML = '';
        oldSlot.className = 'pl-track-playing-icon';
        oldSlot.style.display = 'none';
      } else {
        // Ripristina il numero originale nello span album (se era stato sostituito)
        const posSpan = li.querySelector('.text-muted.small');
        if (posSpan && posSpan.dataset.origText) {
          posSpan.textContent = posSpan.dataset.origText;
          delete posSpan.dataset.origText;
        } else {
          li.querySelector('.track-playing-icon')?.remove();
        }
      }
      const btn = li.querySelector('.btn-track-play');

      if (tid === t.id) {
        li.classList.add('track-playing');
        // Cerca prima lo slot dedicato nelle righe playlist (.pl-track-playing-icon)
        // Quello slot è dentro il wrapper flex della posizione, non sposta nulla.
        // Fallback: riga album — inserisce l'icona dopo il numero posizione come prima.
        const playlistSlot = li.querySelector('.pl-track-playing-icon');
        if (playlistSlot) {
          playlistSlot.innerHTML = '<span class="bar"></span><span class="bar"></span><span class="bar"></span>';
          playlistSlot.className = 'pl-track-playing-icon track-playing-icon';
          playlistSlot.style.display = '';
        } else {
          // Riga album: nasconde il numero e mostra l'icona dentro lo stesso span
          // così il layout flex della riga non si sposta
          const posSpan = li.querySelector('.text-muted.small');
          if (posSpan) {
            posSpan.dataset.origText = posSpan.dataset.origText || posSpan.textContent;
            posSpan.innerHTML = '<span class="track-playing-icon" style="display:inline-flex">'
              + '<span class="bar"></span><span class="bar"></span><span class="bar"></span>'
              + '</span>';
          }
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
        if (tid === t.id) {
          btn.innerHTML = playing ? '<i class="bi bi-pause-fill"></i>' : '<i class="bi bi-play-fill"></i>';
          btn.title = playing ? 'Pausa' : 'Ascolta';
        }
      });
    }
    // Sincronizza lista playlist se visibile
    if (typeof window.__syncPlaylistListUI === 'function') {
      window.__syncPlaylistListUI();
    }
  }
  // Carica tracce da album o playlist e avvia riproduzione
  function load(albumData, startIndex) {
    if (!albumData || !albumData.tracks || albumData.tracks.length === 0) return;
    playlist = albumData.tracks;
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

  // Riempimento barre seek e volume con gradiente inline
  function updateFill(input, activeColor) {
    const pct = (parseFloat(input.value) / parseFloat(input.max || 100)) * 100;
    input.style.background =
      `linear-gradient(to right, ${activeColor} ${pct}%, rgba(255,255,255,0.15) ${pct}%)`;
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

    resetPlayerState('completed');
  });
  audio.addEventListener('timeupdate', () => {
    if (!audio.duration) return;
    const pct = (audio.currentTime / audio.duration) * 100;
    seek.value = Math.round(pct);
    timeCur.textContent = fmt(audio.currentTime);
    updateFill(seek, '#ffc107');
  });
  audio.addEventListener('loadedmetadata', () => {
    timeDur.textContent = fmt(audio.duration);
  });

  // Controls
  btnPlay.addEventListener('click', () => {
    if (!audio.src) return;
    audio.paused ? safePlay() : audio.pause();
  });
  btnPrev.addEventListener('click', () => {
    // Cerca indietro la precedente traccia con src valido
    let prev = cursor - 1;
    while (prev >= 0 && !(playlist[prev] && playlist[prev].src)) { prev--; }
    if (prev >= 0) playAt(prev);
  });
  btnNext.addEventListener('click', () => {
    // Cerca avanti la prossima traccia con src valido
    let next = cursor + 1;
    while (next < playlist.length && !(playlist[next] && playlist[next].src)) { next++; }
    if (next < playlist.length) playAt(next);
  });
  btnStop.addEventListener('click', () => resetPlayerState('stopped'));

  seek.addEventListener('input', () => {
    if (audio.duration) audio.currentTime = (seek.value / 100) * audio.duration;
    updateFill(seek, '#ffc107');
  });

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
    refreshUI: updateUI,
    currentSrc: () => audio.src,
    currentIndex: () => cursor,
    currentTrackId: () => (playlist[cursor] ? playlist[cursor].id : null),
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
// -------------------------------------------------------
document.addEventListener('keydown', function (e) {
  // Ignora se il focus è su un input, textarea, select o elemento editabile
  var tag = document.activeElement ? document.activeElement.tagName : '';
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
  if (document.activeElement && document.activeElement.isContentEditable) return;

  if (e.code === 'Space' || e.key === ' ') {
    var playerPanel = document.getElementById('sticky-player');
    // Agisce solo se il player è visibile (audio caricato)
    if (!playerPanel || playerPanel.style.display === 'none') return;

    e.preventDefault(); // evita lo scroll della pagina
    var btnPlayPause = document.getElementById('sp-play-pause');
    if (btnPlayPause) btnPlayPause.click();
  }
});

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
      // Se è già la traccia corrente → toggle play/pause
      if (audio && audio.src === src) {
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