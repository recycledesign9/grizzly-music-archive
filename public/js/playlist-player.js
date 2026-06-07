/* ============================================================
   playlist-player.js
   Carica una playlist dal server e la passa al Player globale.
   Dipende da: Player (app.js), BASE_URL (meta tag)
   ============================================================ */

var PlaylistPlayer = (function () {

  // Tiene traccia della playlist attualmente caricata nel player
  var _activePlaylistId = null;

  /**
   * Carica la playlist con l'id specificato e avvia la riproduzione.
   *
   * @param {number} playlistId  - ID della playlist nel database
   * @param {number} [startIndex=0] - Indice (0-based) da cui iniziare
   */
  function load(playlistId, startIndex) {
    if (!playlistId) return;

    fetch(BASE_URL + '/index.php?route=playlists/api-tracks/' + playlistId, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data.tracks || data.tracks.length === 0) {
          console.warn('[PlaylistPlayer] Playlist vuota o senza dati.');
          return;
        }

        // Conta le tracce riproducibili (src non nullo)
        var playable = data.tracks.filter(function (t) { return !!t.src; });
        if (playable.length === 0) {
          alert('Questa playlist non ha tracce con file audio disponibile.');
          return;
        }

        // Costruisce la struttura compatibile con Player.load()
        // cover e artist vengono presi dalla prima traccia riproducibile
        var first = playable[0];
        var albumLike = {
          id: null,           // null = non è un album, è una playlist
          playlistId: playlistId,     // necessario per cover link e contesto nel player
          cover: first.cover || '',
          artist: first.artist || '',
          tracks: data.tracks     // contiene TUTTE le tracce, incluse quelle senza src
          // — il Player le salterà automaticamente in playAt()
        };

        // startIndex è 0-based sull'array completo (non solo sulle riproducibili)
        var idx = (typeof startIndex === 'number' && startIndex >= 0)
          ? startIndex
          : 0;

        _activePlaylistId = playlistId;
        Player.load(albumLike, idx);

        // Aggiorna contesto playlist nel player sticky
        setPlayerContext(playlistId, data.name || '');
      })
      .catch(function (err) {
        console.error('[PlaylistPlayer] Errore caricamento:', err);
      });
  }

  /**
   * Mostra il nome della playlist attiva nel player sticky
   * e aggiorna il link della cover verso la playlist.
   */
  function setPlayerContext(playlistId, playlistName) {
    var coverLink = document.getElementById('sp-cover-link');
    if (coverLink) {
      coverLink.href = BASE_URL + '/index.php?route=playlists/detail/' + playlistId;
      coverLink.title = 'Vai alla playlist';
    }

    var ctxEl = document.getElementById('sp-context');
    var ctxName = document.getElementById('sp-context-name');
    if (ctxEl && ctxName && playlistName) {
      ctxName.textContent = playlistName;
      ctxEl.style.display = '';
    }
  }

  /**
   * Nasconde il contesto playlist nel player.
   * Chiamato da app.js quando si avvia la riproduzione da un album.
   */
  function clearContext() {
    _activePlaylistId = null;

    var ctxEl = document.getElementById('sp-context');
    if (ctxEl) ctxEl.style.display = 'none';

    if (typeof window.__syncPlaylistListUI === 'function') {
      window.__syncPlaylistListUI();
    }
  }

  /**
   * Riordina la queue in memoria del Player secondo il nuovo ordine
   * dei track_id fornito (array di interi).
   * Chiamato dopo il drag-and-drop senza interrompere la riproduzione.
   *
   * @param {number[]} orderedTrackIds - track_id nell'ordine nuovo
   */
  function reorderQueue(orderedTrackIds) {
    if (!orderedTrackIds || !orderedTrackIds.length) return;
    if (typeof Player === 'undefined' || typeof Player.getPlaylist !== 'function') return;

    var current = Player.getPlaylist();
    if (!current || !current.length) return;

    // Costruisce una mappa track_id → oggetto traccia
    var map = {};
    current.forEach(function (t) { if (t && t.id) map[t.id] = t; });

    // Ricostruisce l'array nell'ordine nuovo
    // Le tracce non presenti nell'ordine (es. senza id) vengono ignorate
    var reordered = [];
    orderedTrackIds.forEach(function (tid) {
      if (map[tid]) reordered.push(map[tid]);
    });

    // Aggiunge eventuali tracce non mappate in fondo (sicurezza)
    current.forEach(function (t) {
      if (t && t.id && orderedTrackIds.indexOf(t.id) === -1) {
        reordered.push(t);
      }
    });

    Player.setPlaylist(reordered);
  }

  /**
   * Ricarica silenziosamente le tracce dal server e aggiorna la queue
   * senza interrompere la riproduzione. Da chiamare dopo addTracks().
   * Se la playlist non è quella attiva non fa nulla.
   */
  function refreshQueue(playlistId) {
    var pid = playlistId || _activePlaylistId;
    if (!pid) return;
    if (typeof Player === 'undefined' || typeof Player.setPlaylist !== 'function') return;

    fetch(BASE_URL + '/index.php?route=playlists/api-tracks/' + pid, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.tracks || !data.tracks.length) return;
        // Mantiene la traccia corrente al suo posto nel nuovo ordine
        var currentId = (typeof Player.currentTrackId === 'function')
          ? Player.currentTrackId() : null;
        var newList = data.tracks;
        Player.setPlaylist(newList);
        // Se la traccia corrente non è più nella nuova lista, non fare nulla
        // (la riproduzione continua sulla traccia corrente fino a fine naturale)
        console.log('[PlaylistPlayer] Queue aggiornata: ' + newList.length + ' tracce');
      })
      .catch(function (err) {
        console.warn('[PlaylistPlayer] refreshQueue fallito:', err);
      });
  }

  /**
   * Rimuove una traccia dalla queue in memoria senza interrompere
   * la riproduzione. Da chiamare dopo remove-track.
   *
   * @param {number} trackId - ID della traccia da rimuovere
   */
  function removeFromQueue(trackId) {
    if (!trackId || typeof Player === 'undefined') return;
    if (typeof Player.getPlaylist !== 'function') return;
    if (typeof Player.setPlaylist !== 'function') return;

    var current = Player.getPlaylist();
    if (!current || !current.length) return;

    var filtered = current.filter(function (t) {
      return t && t.id !== trackId;
    });

    if (filtered.length !== current.length) {
      Player.setPlaylist(filtered);

      if (typeof Player.refreshUI === 'function') {
        Player.refreshUI();
      }
    }
  }

  // API pubblica
  return {
    load: load,
    clearContext: clearContext,
    reorderQueue: reorderQueue,
    refreshQueue: refreshQueue,
    removeFromQueue: removeFromQueue,
    activeId: function () { return _activePlaylistId; }
  };

})();