</div> <!-- chiusura container -->
</main> <!-- chiude il <main> aperto in header.php -->

<!-- ===== STICKY PLAYER ===== -->
<!-- Una riga: [disco] [trasporto] [barra + tempi] [coda, volume].
     Gli id restano quelli di prima: app.js, queue-panel.js e
     playlist-player.js li cercano per nome. -->
<div id="sticky-player" class="sticky-player" style="display:none" role="region" aria-label="Player audio">
  <div class="sp-left">
    <div class="sp-cover">
      <a id="sp-cover-link" href="#" title="Vai al disco">
        <img id="sp-cover-img" src="<?= BASE_URL ?>/public/img/placeholder.png" alt="Cover" onerror="this.onerror=null;this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
      </a>
    </div>
    <div class="sp-meta">
      <div class="sp-track" id="sp-track-title">—</div>
      <div class="sp-artist" id="sp-artist-name">—</div>
      <div class="sp-context" id="sp-context" style="display:none">
        <i class="bi bi-collection-play me-1" style="font-size:.65rem"></i>
        <span id="sp-context-name"></span>
      </div>
    </div>
  </div>

  <div class="sp-controls">
    <button type="button" class="sp-btn" id="sp-stop" title="Stop" aria-label="Stop"><i class="bi bi-stop-fill"></i></button>
    <button type="button" class="sp-btn" id="sp-prev" title="Traccia precedente" aria-label="Traccia precedente"><i class="bi bi-skip-backward-fill"></i></button>
    <button type="button" class="sp-btn sp-btn-main" id="sp-play-pause" title="Play" aria-label="Riproduci o metti in pausa"><i class="bi bi-play-fill"></i></button>
    <button type="button" class="sp-btn" id="sp-next" title="Traccia successiva" aria-label="Traccia successiva"><i class="bi bi-skip-forward-fill"></i></button>
    <button type="button" class="sp-btn" id="sp-repeat" data-mode="off" aria-pressed="false" title="Ripeti: disattivato" aria-label="Ripeti: disattivato">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>
    </button>
  </div>

  <div class="sp-progress-wrap">
    <span class="sp-time" id="sp-current">0:00</span>
    <div class="sp-seek-wrap" id="sp-seek-wrap">
      <input type="range" class="sp-seek" id="sp-seek" value="0" min="0" max="1000" step="1" aria-label="Posizione nella traccia">
      <div class="sp-ruler" id="sp-ruler" aria-hidden="true"></div>
      <span class="sp-hover-line" id="sp-hover-line" aria-hidden="true" hidden></span>
      <span class="sp-hover-tip" id="sp-hover-tip" aria-hidden="true" hidden>0:00</span>
    </div>
    <button type="button" class="sp-time sp-time-toggle" id="sp-time-toggle" title="Durata. Mostra il tempo residuo" aria-label="Durata. Mostra il tempo residuo">
      <span id="sp-duration">0:00</span>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4l-4 4 4 4"/><path d="M3 8h13"/><path d="M17 20l4-4-4-4"/><path d="M21 16H8"/></svg>
    </button>
  </div>

  <div class="sp-extra">
    <button type="button" class="sp-btn" id="sp-queue" title="Coda di riproduzione" aria-label="Coda di riproduzione"><i class="bi bi-music-note-list"></i></button>
    <span class="sp-sep" aria-hidden="true"></span>
    <!-- VOLUME  -->
    <div class="sp-volume-wrap">
      <button type="button" class="sp-btn sp-btn-vol" id="sp-mute" title="Muto" aria-label="Muto"><i class="bi bi-volume-up-fill"></i></button>
      <input type="range" class="sp-volume" id="sp-volume" value="100" min="0" max="100" step="1" aria-label="Volume">
    </div>
  </div>
</div>

<audio id="global-audio" preload="auto"></audio>

<?php
// ------------------------------------------------------------
// Cache-busting automatico degli asset locali: appende ?v=<mtime>
// all'URL, così ogni deploy (docker compose build ri-copia i file
// e ne aggiorna il timestamp) invalida la cache del browser senza
// bump manuali né hard refresh. I CDN restano senza ?v= perché
// già versionati nell'URL.
// ------------------------------------------------------------
if (!function_exists('asset_v')) {
  function asset_v(string $rel): string
  {
    $t = @filemtime(BASE_PATH . $rel);
    return BASE_URL . $rel . ($t ? '?v=' . $t : '');
  }
}
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="<?= asset_v('/public/js/app.js') ?>"></script>
<script src="<?= asset_v('/public/js/youtube-player.js') ?>"></script>
<script src="<?= asset_v('/public/js/playlist-player.js') ?>"></script>
<script src="<?= asset_v('/public/js/queue-panel.js') ?>"></script>
<script src="<?= asset_v('/public/js/instant-mix.js') ?>"></script>
</body>

</html>