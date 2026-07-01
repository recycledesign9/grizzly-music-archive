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
  var lightbox   = null;
  var spinner    = null;
  var trackLabel = null;
  var isOpen     = false;

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
          '<span id="yt-track-label"></span>',
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

    updateLabel(item.artist + ' - ' + item.title);
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

  // ── API pubbliche: single-track ─────────────────────────────
  function openForTrack(trackId, artist, title, btn) {
    pauseAudioIfPlaying();
    isQueueMode = false;
    queue = [{
      trackId: trackId,
      artist:  artist,
      title:   title,
      videoId: (btn && btn.dataset.videoId) ? btn.dataset.videoId : null,
      btn:     btn || null
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
      queue.push({
        trackId: btn.dataset.trackId || '',
        artist:  btn.dataset.artist  || '',
        title:   btn.dataset.title   || '',
        videoId: btn.dataset.videoId || null,
        btn:     btn
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
  }

  // Ri-espande il player a schermo intero centrato.
  function expand() {
    isMinimized = false;
    lightbox.classList.remove('is-minimized');
    document.body.style.overflow = 'hidden';
    updateMinimizeIcon();
  }

  function updateLabel(label) {
    if (trackLabel) { trackLabel.textContent = label || ''; }
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
    document.body.style.overflow = '';
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
    lightbox   = createLightbox();
    spinner    = document.getElementById('yt-spinner');
    trackLabel = document.getElementById('yt-track-label');

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