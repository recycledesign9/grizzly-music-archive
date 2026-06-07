</div> <!-- chiusura container -->
</main> <!-- chiude il <main> aperto in header.php -->

<!-- ===== STICKY PLAYER ===== -->
<div id="sticky-player" class="sticky-player" style="display:none" aria-label="Player audio">
  <div class="sp-cover">
    <a id="sp-cover-link" href="#" title="Vai al disco">
      <img id="sp-cover-img" src="" alt="Cover">
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
  <div class="sp-controls">
    <button class="sp-btn" id="sp-prev" title="Precedente"><i class="bi bi-skip-backward-fill"></i></button>
    <button class="sp-btn sp-btn-main" id="sp-play-pause" title="Play"><i class="bi bi-play-fill"></i></button>
    <button class="sp-btn" id="sp-next" title="Successiva"><i class="bi bi-skip-forward-fill"></i></button>
    <button class="sp-btn" id="sp-stop" title="Stop"><i class="bi bi-stop-fill"></i></button>
    <!-- VOLUME  -->
    <div class="sp-volume-wrap">
      <button class="sp-btn sp-btn-vol" id="sp-mute" title="Muto"><i class="bi bi-volume-up-fill"></i></button>
      <input type="range" class="sp-volume" id="sp-volume" value="100" min="0" max="100" step="1">
    </div>
  </div>
  <div class="sp-progress-wrap">
    <span class="sp-time" id="sp-current">0:00</span>
    <input type="range" class="sp-seek" id="sp-seek" value="0" min="0" max="100" step="1">
    <span class="sp-time" id="sp-duration">0:00</span>
  </div>
</div>

<audio id="global-audio" preload="auto"></audio>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="<?= BASE_URL ?>/public/js/app.js"></script>
<script src="<?= BASE_URL ?>/public/js/youtube-player.js"></script>
<script src="<?= BASE_URL ?>/public/js/playlist-player.js"></script>
</body>

</html>