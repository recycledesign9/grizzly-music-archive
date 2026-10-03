(function () {
  'use strict';

  const BASE_URL = (typeof window.BASE_URL !== 'undefined' && window.BASE_URL)
    ? window.BASE_URL
    : (document.querySelector('meta[name="base-url"]')?.content || '');

  if (!BASE_URL) {
    console.warn('[youtube-player] BASE_URL non trovata.');
    return;
  }

  const API_ENDPOINT = BASE_URL + '/api/youtube-track.php';

  // ── Stato modulo ────────────────────────────────────────────
  var lightbox       = null;
  var spinner        = null;
  var trackLabel     = null;
  var trackTitleLink  = null;
  var trackArtistLink = null;
  var trackAlbumLink  = null;
  var trackMetaSep   = null;
  var isOpen         = false;

  var ytPlayer   = null;   // istanza YT.Player
  var apiReady   = false;  // IFrame API caricata e pronta
  var pendingCmd = null;   // comando da eseguire appena l'API è pronta

  // Stato coda: la modalità single-track è semplicemente una coda di lunghezza 1
  var queue      = [];     // [{ trackId, artist, title, videoId|null, btn|null }]
  var qIndex     = -1;     // indice traccia corrente nella coda
  var isQueueMode = false; // true se avviato da "Riproduci tutti"
  var isMinimized = false; // true se il player è in modalità mini (PiP flottante)

  // ── Costruzione lightbox ────────────────────────────────────
  function createLightbox() {
    var lb = document.createElement('div');
    lb.id = 'yt-lightbox';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.innerHTML = [
      '<div id="yt-lightbox-backdrop"></div>',
      '<div id="yt-lightbox-panel">',
        '<div id="yt-lightbox-header">',
          '<div id="yt-track-info">',
            '<span id="yt-track-label"></span>',
            '<a id="yt-track-title-link" href="#" title="Vai al disco"></a>',
            '<div id="yt-track-meta">',
              '<a id="yt-track-artist-link" href="#" title="Vai all\'artista"></a>',
              '<span id="yt-track-meta-sep" aria-hidden="true">·</span>',
              '<a id="yt-track-album-link" href="#" title="Vai al disco"></a>',
            '</div>',
          '</div>',
          '<div id="yt-queue-controls" style="display:none;">',
            '<button id="yt-prev" title="Traccia precedente">',
              '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">',
                '<path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/>',
              '</svg>',
            '</button>',
            '<span id="yt-queue-counter"></span>',
            '<button id="yt-next" title="Traccia successiva">',
              '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">',
                '<path d="M16 6h2v12h-2zM6 18l8.5-6L6 6z"/>',
              '</svg>',
            '</button>',
          '</div>',
          '<button id="yt-lightbox-minimize" title="Riduci a finestra">',
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">',
              '<polyline points="4 14 10 14 10 20"/>',
              '<polyline points="20 10 14 10 14 4"/>',
              '<line x1="14" y1="10" x2="21" y2="3"/>',
              '<line x1="3" y1="21" x2="10" y2="14"/>',
            '</svg>',
          '</button>',
          '<button id="yt-lightbox-close" title="Chiudi (ESC)">',
            '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">',
              '<line x1="18" y1="6" x2="6" y2="18"/>',
              '<line x1="6" y1="6" x2="18" y2="18"/>',
            '</svg>',
          '</button>',
        '</div>',
        '<div id="yt-iframe-wrapper">',
          '<div id="yt-spinner">',
            '<div class="yt-spin-ring"></div>',
            '<span>Ricerca in corso...</span>',
          '</div>',
          // L'IFrame API rimpiazza questo div con il proprio <iframe>
          '<div id="yt-player"></div>',
        '</div>',
      '</div>'
    ].join('');
    document.body.appendChild(lb);
    return lb;
  }

  // ── Loader IFrame API (una sola volta) ──────────────────────
  function loadIframeApi() {
    if (window.YT && window.YT.Player) {
      apiReady = true;
      return;
    }
    // Se già in caricamento, non ri-iniettare lo script
    if (document.getElementById('yt-iframe-api-script')) { return; }

    var tag = document.createElement('script');
    tag.id  = 'yt-iframe-api-script';
    tag.src = 'https://www.youtube.com/iframe_api';
    var first = document.getElementsByTagName('script')[0];
    first.parentNode.insertBefore(tag, first);
  }

  // Callback globale richiesta dall'IFrame API
  window.onYouTubeIframeAPIReady = function () {
    apiReady = true;
    createPlayer();
  };

  function createPlayer() {
    if (ytPlayer) { return; }
    ytPlayer = new YT.Player('yt-player', {
      width:  '100%',
      height: '100%',
      playerVars: {
        autoplay:       1,
        rel:            0,
        modestbranding: 1,
        origin:         window.location.origin
      },
      events: {
        onReady:       onPlayerReady,
        onStateChange: onPlayerStateChange,
        onError:       onPlayerError
      }
    });
  }

  function onPlayerReady() {
    // Se un comando era in attesa della creazione del player, eseguilo ora
    if (pendingCmd) {
      var cmd = pendingCmd;
      pendingCmd = null;
      cmd();
    }
  }

  // ── Eventi player ───────────────────────────────────────────
  function onPlayerStateChange(e) {
    // Nasconde lo spinner appena parte la riproduzione o il buffering
    if (e.data === YT.PlayerState.PLAYING || e.data === YT.PlayerState.BUFFERING) {
      showSpinner(false);
    }
    // Regola unica speculare di mutua esclusione: qualsiasi avvio del video
    // (loadVideoById o play interno all'iframe) mette in pausa l'audio nativo.
    // Copre anche la ripresa manuale dai controlli dentro l'iframe YouTube.
    if (e.data === YT.PlayerState.PLAYING) {
      pauseAudioIfPlaying();
    }
    // Fine traccia → avanza (solo in modalità coda)
    if (e.data === YT.PlayerState.ENDED) {
      if (isQueueMode) {
        playNext();
      }
    }
  }

  function onPlayerError() {
    // Codici 100/101/150 = video rimosso o embed negato.
    // In modalità coda: skip silenzioso alla traccia successiva.
    // In single-track: messaggio pulito.
    if (isQueueMode) {
      playNext();
    } else {
      showError('Video non disponibile per l\u2019embed.');
    }
  }

  // ── Motore di riproduzione ──────────────────────────────────
  // Esegue fn quando il player è pronto; altrimenti la mette in coda.
  function whenPlayerReady(fn) {
    if (ytPlayer && typeof ytPlayer.loadVideoById === 'function') {
      fn();
    } else {
      pendingCmd = fn;
      loadIframeApi();
      // Se l'API era già pronta ma il player non ancora creato
      if (apiReady) { createPlayer(); }
    }
  }

  function loadVideo(videoId) {
    whenPlayerReady(function () {
      showSpinner(false);
      ytPlayer.loadVideoById(videoId);
    });
  }

  // Riproduce la traccia all'indice i della coda, risolvendo lazy il videoId
  function playIndex(i) {
    if (i < 0 || i >= queue.length) {
      // Fine coda
      closeLightbox();
      return;
    }
    qIndex = i;
    var item = queue[i];

    updateTrackInfo(item);
    updateQueueCounter();
    showSpinner(true);

    // videoId già noto (cache DOM o già risolto)?
    if (item.videoId) {
      loadVideo(item.videoId);
      return;
    }

    // Risoluzione lazy via youtube-track.php
    var params = new URLSearchParams();
    params.set('track_id', item.trackId || '');
    params.set('artist',   item.artist);
    params.set('title',    item.title);

    fetch(API_ENDPOINT + '?' + params.toString())
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.json();
      })
      .then(function (data) {
        if (data.error || !data.video_id) {
          // Nessun video per questa traccia
          if (isQueueMode) { playNext(); }
          else { showError(data.error || 'Nessun video trovato.'); }
          return;
        }
        item.videoId = data.video_id;
        // Aggiorna la cache DOM sul bottone (riuso tra single e coda)
        if (item.btn) { item.btn.dataset.videoId = data.video_id; }
        loadVideo(data.video_id);
      })
      .catch(function (err) {
        if (isQueueMode) { playNext(); }
        else { showError('Errore: ' + err.message); }
      });
  }

  function playNext() { playIndex(qIndex + 1); }
  function playPrev() { playIndex(qIndex - 1); }

  // ── Contesto album per il PiP ──────────────────────────────
  // Il riferimento viene catturato quando si apre YouTube, così resta corretto
  // anche se poi l'utente naviga altrove mentre il mini-player continua a suonare.
  function albumIdFromHref(href) {
    if (!href) { return null; }
    try {
      var u = new URL(href, window.location.href);
      var route = u.searchParams.get('route') || '';
      var m = route.match(/^albums\/detail\/(\d+)/);
      return m ? parseInt(m[1], 10) : null;
    } catch (e) {
      return null;
    }
  }

  function currentAlbumContext() {
    try {
      var u = new URL(window.location.href);
      var route = u.searchParams.get('route') || '';
      var m = route.match(/^albums\/detail\/(\d+)/);
      if (!m || !window.__album) {
        return { albumId: null, albumTitle: '', artistHref: '' };
      }

      var routeId = parseInt(m[1], 10);
      var globalId = parseInt(window.__album.id || 0, 10);

      // window.__album sopravvive alla SPA: usalo solo se appartiene davvero
      // alla pagina album attualmente aperta, evitando metadati rimasti stale.
      if (!routeId || routeId !== globalId) {
        return { albumId: null, albumTitle: '', artistHref: '' };
      }

      var artistLink = document.querySelector('.album-hero-artist a[href*="route=artists/profile/"]');

      return {
        albumId: routeId,
        albumTitle: window.__album.title || '',
        artistHref: artistLink ? artistLink.getAttribute('href') || '' : ''
      };
    } catch (e) {
      return { albumId: null, albumTitle: '', artistHref: '' };
    }
  }

  function albumContextForButton(btn) {
    var albumId = null;
    var albumTitle = '';
    var artistHref = '';

    // Supporto futuro/retrocompatibile se il markup espone già i data-*.
    if (btn) {
      if (btn.dataset.albumId) {
        var parsed = parseInt(btn.dataset.albumId, 10);
        if (parsed > 0) { albumId = parsed; }
      }
      albumTitle = btn.dataset.albumTitle || '';
      artistHref = btn.dataset.artistHref || '';

      // Se il bottone vive in una riga playlist che contiene un link al disco,
      // ricava il contesto senza richiedere modifiche al markup esistente.
      if (!albumId || !albumTitle) {
        var row = btn.closest('.track-item, tr, li');
        var albumLink = row ? row.querySelector('a[href*="route=albums/detail/"]') : null;
        if (albumLink) {
          if (!albumId) { albumId = albumIdFromHref(albumLink.getAttribute('href')); }
          if (!albumTitle) {
            albumTitle = albumLink.dataset.albumTitle || albumLink.textContent.trim();
          }
        }

        if (!artistHref) {
          var artistLink = row ? row.querySelector('a[href*="route=artists/profile/"]') : null;
          if (artistLink) {
            artistHref = artistLink.getAttribute('href') || '';
          }
        }
      }
    }

    // Nella scheda album corrente recupera anche il link già esistente
    // al profilo artista, evitando di inventare route o lookup aggiuntivi.
    var current = currentAlbumContext();
    if (!albumId && current.albumId) { albumId = current.albumId; }
    if (!albumTitle && current.albumTitle) { albumTitle = current.albumTitle; }
    if (!artistHref && current.artistHref) { artistHref = current.artistHref; }

    return {
      albumId: albumId,
      albumTitle: albumTitle,
      artistHref: artistHref
    };
  }

  // ── API pubbliche: single-track ─────────────────────────────
  function openForTrack(trackId, artist, title, btn) {
    pauseAudioIfPlaying();
    isQueueMode = false;

    var album = albumContextForButton(btn);
    queue = [{
      trackId:   trackId,
      artist:    artist,
      title:     title,
      albumId:    album.albumId,
      albumTitle: album.albumTitle,
      artistHref: album.artistHref,
      videoId:    (btn && btn.dataset.videoId) ? btn.dataset.videoId : null,
      btn:       btn || null
    }];
    setQueueControlsVisible(false);
    openLightbox();
    playIndex(0);
  }

  // ── API pubbliche: album intero ─────────────────────────────
  // Legge le tracce dai .btn-yt già presenti nel DOM, in ordine.
  function playAlbum() {
    var btns = document.querySelectorAll('.btn-yt');
    if (!btns.length) { return; }

    queue = [];
    btns.forEach(function (btn) {
      var album = albumContextForButton(btn);
      queue.push({
        trackId:    btn.dataset.trackId || '',
        artist:     btn.dataset.artist  || '',
        title:      btn.dataset.title   || '',
        albumId:    album.albumId,
        albumTitle: album.albumTitle,
        artistHref: album.artistHref,
        videoId:    btn.dataset.videoId || null,
        btn:        btn
      });
    });

    if (!queue.length) { return; }

    pauseAudioIfPlaying();
    isQueueMode = true;
    setQueueControlsVisible(true);
    openLightbox();
    playIndex(0);
  }

  // ── UI helpers ──────────────────────────────────────────────
  function bindTrackButtons() {
    var btns = document.querySelectorAll('.btn-yt');
    btns.forEach(function (btn) {
      if (btn.dataset.ytBound) { return; }
      btn.dataset.ytBound = '1';
      btn.addEventListener('click', function () {
        openForTrack(
          btn.dataset.trackId || '',
          btn.dataset.artist  || '',
          btn.dataset.title   || '',
          btn
        );
      });
    });

    // Bottone "Riproduci tutti da YouTube" nel dropdown album
    var playAllBtn = document.getElementById('yt-play-all');
    if (playAllBtn && !playAllBtn.dataset.ytBound) {
      playAllBtn.dataset.ytBound = '1';
      playAllBtn.addEventListener('click', function () { playAlbum(); });
    }
  }

  function pauseAudioIfPlaying() {
    var audio = document.getElementById('global-audio');
    if (audio && !audio.paused && audio.src) {
      audio.pause();
    }
  }

  function openLightbox() {
    lightbox.classList.add('is-open');
    // In modalità mini non blocchiamo lo scroll della pagina
    if (!isMinimized) {
      document.body.style.overflow = 'hidden';
    }
    isOpen = true;
  }

  // SVG dei due stati del pulsante mini/ripristina
  var ICON_MINIMIZE = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
  var ICON_EXPAND   = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';

  // Aggiorna icona e tooltip del pulsante in base allo stato
  function updateMinimizeIcon() {
    var btn = document.getElementById('yt-lightbox-minimize');
    if (!btn) { return; }
    if (isMinimized) {
      btn.innerHTML = ICON_EXPAND;
      btn.title = 'Ripristina';
    } else {
      btn.innerHTML = ICON_MINIMIZE;
      btn.title = 'Riduci a finestra';
    }
  }

  // Riduce il player a finestra flottante (PiP): l'iframe NON viene ricaricato,
  // cambia solo la classe CSS → la riproduzione continua senza interruzione.
  function minimize() {
    isMinimized = true;
    lightbox.classList.add('is-minimized');
    // Sblocca scroll e click sulla pagina sotto
    document.body.style.overflow = '';
    updateMinimizeIcon();
    // Ripristina l'ultima posizione trascinata (ri-clampata alla viewport)
    if (savedPos) {
      var panel = getPanel();
      if (panel) {
        var r = panel.getBoundingClientRect();
        var pos = clampPos(savedPos.left, savedPos.top, r.width, r.height);
        applyPos(pos.left, pos.top);
        savedPos = pos;
      }
    }
  }

  // Ri-espande il player a schermo intero centrato.
  function expand() {
    isMinimized = false;
    lightbox.classList.remove('is-minimized');
    document.body.style.overflow = 'hidden';
    updateMinimizeIcon();
    // Rimuove la posizione inline: il lightbox torna centrato.
    // savedPos viene conservata per il prossimo minimize().
    clearPos();
  }

  // ── Drag della finestra mini (PiP flottante) ────────────────
  // Si trascina afferrando l'header. Usa i Pointer Events con
  // setPointerCapture: funziona con mouse e touch e gli eventi
  // di move restano sull'header anche passando sopra l'iframe.
  var dragging = null;   // stato del drag corrente
  var savedPos = null;   // ultima posizione trascinata {left, top}

  function getPanel() {
    return document.getElementById('yt-lightbox-panel');
  }

  // Mantiene la finestra dentro la viewport con un margine di 8px
  function clampPos(left, top, w, h) {
    var margin = 8;
    var maxL = window.innerWidth  - w - margin;
    var maxT = window.innerHeight - h - margin;
    return {
      left: Math.min(Math.max(left, margin), Math.max(maxL, margin)),
      top:  Math.min(Math.max(top,  margin), Math.max(maxT, margin))
    };
  }

  // Passa dall'ancoraggio bottom/right (CSS) a coordinate left/top inline
  function applyPos(left, top) {
    lightbox.style.left   = left + 'px';
    lightbox.style.top    = top + 'px';
    lightbox.style.right  = 'auto';
    lightbox.style.bottom = 'auto';
  }

  // Rimuove le coordinate inline → torna il posizionamento da CSS
  function clearPos() {
    lightbox.style.left   = '';
    lightbox.style.top    = '';
    lightbox.style.right  = '';
    lightbox.style.bottom = '';
  }

  function navigateHeaderLink(link) {
    if (!link) { return; }
    var href = link.getAttribute('href');
    if (!href) { return; }

    // Usa la navigazione SPA di Grizzly quando disponibile; fallback normale.
    if (typeof window._spaNavigate === 'function') {
      window._spaNavigate(href);
    } else {
      window.location.href = href;
    }
  }

  function onDragStart(e) {
    if (!isMinimized) { return; }

    // I pulsanti dell'header restano esclusivamente pulsanti.
    if (e.target.closest('button')) { return; }

    var panel = getPanel();
    if (!panel) { return; }

    var link = e.target.closest('a');
    var rect = panel.getBoundingClientRect();

    dragging = {
      dx: e.clientX - rect.left,
      dy: e.clientY - rect.top,
      startX: e.clientX,
      startY: e.clientY,
      w: rect.width,
      h: rect.height,
      moved: false,
      link: link || null
    };

    if (e.currentTarget.setPointerCapture) {
      e.currentTarget.setPointerCapture(e.pointerId);
    }

    // Blocca il comportamento nativo del link e la selezione testo.
    // Se il gesto resta un click, la navigazione viene eseguita manualmente
    // in onDragEnd(); se si muove, l'intera area diventa una maniglia di drag.
    e.preventDefault();
  }

  function onDragMove(e) {
    if (!dragging) { return; }

    var deltaX = e.clientX - dragging.startX;
    var deltaY = e.clientY - dragging.startY;

    // Piccola soglia per distinguere un click da un vero trascinamento.
    if (!dragging.moved && Math.sqrt(deltaX * deltaX + deltaY * deltaY) < 5) {
      return;
    }

    if (!dragging.moved) {
      dragging.moved = true;
      lightbox.classList.add('is-dragging');
    }

    var pos = clampPos(
      e.clientX - dragging.dx,
      e.clientY - dragging.dy,
      dragging.w,
      dragging.h
    );

    applyPos(pos.left, pos.top);
    savedPos = pos;
    e.preventDefault();
  }

  function onDragEnd(e) {
    if (!dragging) { return; }

    var state = dragging;
    dragging = null;
    lightbox.classList.remove('is-dragging');

    // Nessun movimento: era un click sul metadato.
    if (!state.moved && state.link) {
      navigateHeaderLink(state.link);
    }

    if (e && e.preventDefault) {
      e.preventDefault();
    }
  }

  // Se la finestra del browser viene ridimensionata, ri-clampa
  // la posizione per non lasciare il player fuori schermo.
  function onWindowResize() {
    if (!isMinimized || !savedPos) { return; }
    var panel = getPanel();
    if (!panel) { return; }
    var r = panel.getBoundingClientRect();
    var pos = clampPos(savedPos.left, savedPos.top, r.width, r.height);
    applyPos(pos.left, pos.top);
    savedPos = pos;
  }

  function updateTrackInfo(item) {
    item = item || {};

    var artist = item.artist || '';
    var title = item.title || '';
    var albumTitle = item.albumTitle || '';
    var albumId = parseInt(item.albumId || 0, 10);
    var albumHref = albumId > 0
      ? BASE_URL + '/index.php?route=albums/detail/' + albumId
      : '';

    // Modalità normale: conserva ESATTAMENTE la label storica "Artista - Titolo".
    if (trackLabel) {
      trackLabel.textContent = (artist ? artist + ' - ' : '') + title;
    }

    // Modalità PiP: titolo del brano cliccabile verso il disco corrispondente.
    if (trackTitleLink) {
      trackTitleLink.textContent = title;
      if (albumHref) {
        trackTitleLink.href = albumHref;
        trackTitleLink.classList.remove('is-disabled');
        trackTitleLink.setAttribute('aria-label', 'Apri il disco ' + (albumTitle || title));
      } else {
        trackTitleLink.removeAttribute('href');
        trackTitleLink.classList.add('is-disabled');
        trackTitleLink.removeAttribute('aria-label');
      }
    }

    if (trackArtistLink) {
      trackArtistLink.textContent = artist;
      if (item.artistHref) {
        trackArtistLink.href = item.artistHref;
        trackArtistLink.classList.remove('is-disabled');
        trackArtistLink.setAttribute('aria-label', 'Apri artista ' + artist);
      } else {
        trackArtistLink.removeAttribute('href');
        trackArtistLink.classList.add('is-disabled');
        trackArtistLink.removeAttribute('aria-label');
      }
    }

    if (trackAlbumLink) {
      trackAlbumLink.textContent = albumTitle;
      if (albumHref && albumTitle) {
        trackAlbumLink.href = albumHref;
        trackAlbumLink.classList.remove('is-disabled');
        trackAlbumLink.setAttribute('aria-label', 'Apri il disco ' + albumTitle);
      } else {
        trackAlbumLink.removeAttribute('href');
        trackAlbumLink.classList.add('is-disabled');
        trackAlbumLink.removeAttribute('aria-label');
      }
    }

    if (trackMetaSep) {
      trackMetaSep.style.display = (artist && albumTitle) ? '' : 'none';
    }
  }

  function updateQueueCounter() {
    var counter = document.getElementById('yt-queue-counter');
    if (counter && isQueueMode) {
      counter.textContent = (qIndex + 1) + ' / ' + queue.length;
    }
  }

  function setQueueControlsVisible(show) {
    var ctrls = document.getElementById('yt-queue-controls');
    if (ctrls) { ctrls.style.display = show ? 'flex' : 'none'; }
  }

  function closeLightbox() {
    if (ytPlayer && typeof ytPlayer.stopVideo === 'function') {
      ytPlayer.stopVideo();
    }
    lightbox.classList.remove('is-open');
    lightbox.classList.remove('is-minimized');
    lightbox.classList.remove('is-dragging');
    document.body.style.overflow = '';
    // Reset posizione: alla prossima apertura riparte da bottom/right
    clearPos();
    savedPos = null;
    dragging = null;
    isOpen = false;
    isQueueMode = false;
    isMinimized = false;
    queue = [];
    qIndex = -1;
    setQueueControlsVisible(false);
    updateMinimizeIcon();
  }

  function showSpinner(show) {
    if (!spinner) { return; }
    spinner.style.display = show ? 'flex' : 'none';
    // Ripristina il contenuto standard dello spinner (se sostituito da showError)
    if (show && spinner.dataset.errShown) {
      spinner.innerHTML = '<div class="yt-spin-ring"></div><span>Ricerca in corso...</span>';
      delete spinner.dataset.errShown;
    }
  }

  function showError(msg) {
    if (!spinner) { return; }
    spinner.style.display = 'flex';
    spinner.dataset.errShown = '1';
    spinner.innerHTML = '<div style="text-align:center;padding:1rem;">' +
      '<p style="margin:.5rem 0 0;font-size:.85rem;color:#ccc;">' + msg + '</p></div>';
  }

  // ── Init ────────────────────────────────────────────────────
  function init() {
    lightbox       = createLightbox();
    spinner        = document.getElementById('yt-spinner');
    trackLabel     = document.getElementById('yt-track-label');
    trackTitleLink  = document.getElementById('yt-track-title-link');
    trackArtistLink = document.getElementById('yt-track-artist-link');
    trackAlbumLink  = document.getElementById('yt-track-album-link');
    trackMetaSep   = document.getElementById('yt-track-meta-sep');

    document.getElementById('yt-lightbox-close').addEventListener('click', closeLightbox);

    var minBtn = document.getElementById('yt-lightbox-minimize');
    if (minBtn) {
      minBtn.addEventListener('click', function () {
        if (isMinimized) { expand(); } else { minimize(); }
      });
    }

    // Click sul video ridotto → ri-espande (ma non i bottoni dell'header)
    var wrapper = document.getElementById('yt-iframe-wrapper');
    if (wrapper) {
      wrapper.addEventListener('click', function () {
        if (isMinimized) { expand(); }
      });
    }

    // Drag della finestra mini: si afferra l'header
    var header = document.getElementById('yt-lightbox-header');
    if (header && window.PointerEvent) {
      header.addEventListener('pointerdown',   onDragStart);
      header.addEventListener('pointermove',   onDragMove);
      header.addEventListener('pointerup',     onDragEnd);
      header.addEventListener('pointercancel', onDragEnd);
    }
    window.addEventListener('resize', onWindowResize);

    var prevBtn = document.getElementById('yt-prev');
    var nextBtn = document.getElementById('yt-next');
    if (prevBtn) { prevBtn.addEventListener('click', playPrev); }
    if (nextBtn) { nextBtn.addEventListener('click', playNext); }

    document.addEventListener('keydown', function (e) {
      if (!isOpen) { return; }
      if (e.key === 'Escape') { closeLightbox(); }
      else if (isQueueMode && e.key === 'ArrowRight') { playNext(); }
      else if (isQueueMode && e.key === 'ArrowLeft')  { playPrev(); }
    });

    // Precarica l'IFrame API in background così il primo play è più rapido
    loadIframeApi();

    bindTrackButtons();
  }

  window.YTPlayer = {
    rebind:    bindTrackButtons,
    playAlbum: playAlbum,
    stopVideo: function () { if (isOpen) { closeLightbox(); } }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();