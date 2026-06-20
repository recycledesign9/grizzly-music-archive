<?php
/**
 * 404.php — Pagina errore 404 coordinata al design Grizzly Music Archive
 * Posizionamento: /views/errors/404.php
 *
 * Uso nei controller (sostituisce i vecchi echo '<h1>404..'):
 *   http_response_code(404);
 *   $errorContext = 'disco';   // oppure 'playlist', 'artista', 'risorsa'
 *   require BASE_PATH . '/views/errors/404.php';
 *   exit;
 */

// Contesto passato dal controller — fallback generico
$errorContext = $errorContext ?? 'risorsa';

$contextMap = [
    'disco'    => ['icon' => 'bi-vinyl-fill',           'label' => 'Disco',    'color' => '#ffc107'],
    'playlist' => ['icon' => 'bi-collection-play-fill', 'label' => 'Playlist', 'color' => '#ffc107'],
    'artista'  => ['icon' => 'bi-person-music',         'label' => 'Artista',  'color' => '#ffc107'],
    'risorsa'  => ['icon' => 'bi-question-circle-fill', 'label' => 'Pagina',   'color' => '#ffc107'],
];

$ctx   = $contextMap[$errorContext] ?? $contextMap['risorsa'];
$label = $ctx['label'];
$icon  = $ctx['icon'];

// Quote casuali da mostrare — cambiano ad ogni caricamento
$quotes = [
    ["«Questo solco non esiste nel vinile.»", "— Il Giradischi"],
    ["«Nastro rotto. Traccia perduta nell'archivio.»", "— La Musicassetta"],
    ["«Il CD è graffiato. Impossibile leggere il settore.»", "— Il Lettore"],
    ["«Stavo cercando anch'io. Non l'ho trovato.»", "— Il Grizzly"],
    ["«Forse era un b-side dimenticato da tutti.»", "— Il Collezionista"],
];
$q = $quotes[array_rand($quotes)];

$pageTitle = '404 — Non trovato';
require BASE_PATH . '/views/layout/header.php';
?>

<style>
/* ── 404 Page Styles ── */
.err404-wrap {
  min-height: 75vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 3rem 1.5rem 6rem;
  position: relative;
  overflow: hidden;
}

/* Cerchi decorativi di sfondo */
.err404-bg-circle {
  position: absolute;
  border-radius: 50%;
  opacity: .06;
  pointer-events: none;
}
.err404-bg-circle.c1 {
  width: 520px; height: 520px;
  background: #ffc107;
  top: -160px; left: -120px;
  animation: floatCircle 8s ease-in-out infinite alternate;
}
.err404-bg-circle.c2 {
  width: 360px; height: 360px;
  background: #ffc107;
  bottom: -80px; right: -80px;
  animation: floatCircle 10s ease-in-out infinite alternate-reverse;
}
.err404-bg-circle.c3 {
  width: 200px; height: 200px;
  background: #fff;
  top: 40%; left: 55%;
  animation: floatCircle 12s ease-in-out infinite alternate;
}
@keyframes floatCircle {
  from { transform: translateY(0) scale(1);   }
  to   { transform: translateY(30px) scale(1.06); }
}

/* Vinile animato */
.vinyl-wrap {
  position: relative;
  width: 220px;
  height: 220px;
  margin: 0 auto 2.5rem;
  cursor: pointer;
}
.vinyl-disc {
  width: 220px;
  height: 220px;
  border-radius: 50%;
  background:
    radial-gradient(circle at center, #1a1a1a 28%, transparent 28%),
    repeating-conic-gradient(#111 0deg 1deg, #222 1deg 3deg);
  box-shadow:
    0 0 0 2px #333,
    0 0 0 4px #111,
    0 0 60px rgba(0,0,0,.5),
    0 0 100px rgba(255,193,7,.08);
  animation: spinVinyl 4s linear infinite;
  animation-play-state: paused;
}
.vinyl-wrap:hover .vinyl-disc,
.vinyl-wrap.spinning .vinyl-disc {
  animation-play-state: running;
}
@keyframes spinVinyl {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}
.vinyl-label {
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: #ffc107;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 0 3px #1a1a1a, 0 0 20px rgba(255,193,7,.3);
}
.vinyl-label .vinyl-num {
  font-size: 1.4rem;
  font-weight: 900;
  color: #1a1a1a;
  line-height: 1;
}
.vinyl-label .vinyl-txt {
  font-size: .5rem;
  font-weight: 700;
  color: #1a1a1a;
  letter-spacing: .05em;
  text-transform: uppercase;
}
/* Aghi giradischi */
.needle-wrap {
  position: absolute;
  top: -10px;
  right: -30px;
  width: 100px;
  height: 120px;
  transform-origin: 20px 20px;
  animation: needleLift 0s linear forwards;
}
.vinyl-wrap:hover .needle-wrap,
.vinyl-wrap.spinning .needle-wrap {
  animation: needleDrop 0.6s cubic-bezier(.25,.8,.25,1) forwards;
}
@keyframes needleDrop {
  from { transform: rotate(-30deg); }
  to   { transform: rotate(0deg); }
}
@keyframes needleLift {
  from { transform: rotate(0deg); }
  to   { transform: rotate(-30deg); }
}
.needle-arm {
  width: 4px;
  height: 90px;
  background: linear-gradient(180deg, #aaa, #666);
  border-radius: 2px;
  margin-left: 18px;
  position: relative;
}
.needle-arm::after {
  content: '';
  position: absolute;
  bottom: 0; left: -3px;
  width: 10px;
  height: 10px;
  border-radius: 0 0 50% 50%;
  background: #ffc107;
}
.needle-head {
  width: 22px;
  height: 14px;
  background: linear-gradient(135deg, #bbb, #777);
  border-radius: 4px;
  margin-top: -2px;
  margin-left: 10px;
}

/* Testo principale */
.err404-code {
  font-size: clamp(5rem, 15vw, 9rem);
  font-weight: 900;
  line-height: 1;
  letter-spacing: -.04em;
  background: linear-gradient(135deg, #ffc107 30%, #ff8f00);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin-bottom: .25rem;
  position: relative;
}
.err404-code::after {
  content: attr(data-text);
  position: absolute;
  left: 3px; top: 3px;
  background: linear-gradient(135deg, rgba(255,193,7,.15) 30%, rgba(255,143,0,.15));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  z-index: -1;
}

.err404-subtitle {
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--bs-body-color);
  margin-bottom: .6rem;
}
.err404-context-badge {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  background: rgba(255,193,7,.12);
  border: 1px solid rgba(255,193,7,.25);
  color: #2f2815;
  border-radius: 2rem;
  padding: .3rem .9rem;
  font-size: .8rem;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  margin-bottom: 1.5rem;
}
.err404-quote {
  max-width: 440px;
  margin: 0 auto 2.5rem;
  color: var(--bs-secondary-color);
  font-style: italic;
  line-height: 1.7;
  font-size: .97rem;
}
.err404-quote cite {
  display: block;
  font-style: normal;
  font-size: .8rem;
  font-weight: 600;
  color: #ffc107;
  margin-top: .4rem;
  letter-spacing: .04em;
}

/* Azioni */
.err404-actions {
  display: flex;
  flex-wrap: wrap;
  gap: .75rem;
  justify-content: center;
  margin-bottom: 3rem;
}
.err404-actions .btn {
  border-radius: 2rem;
  padding: .55rem 1.5rem;
  font-weight: 600;
  transition: transform .15s, box-shadow .15s;
}
.err404-actions .btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(255,193,7,.25);
}
.err404-actions .btn-warning {
  background: #ffc107;
  border-color: #ffc107;
  color: #1a1a1a;
}

/* Quick search bar */
.err404-search {
  max-width: 400px;
  width: 100%;
}
.err404-search .form-control {
  border-radius: 2rem 0 0 2rem;
  border-color: rgba(255,193,7,.3);
  background: var(--bs-body-bg);
  color: var(--bs-body-color);
}
.err404-search .form-control:focus {
  border-color: #ffc107;
  box-shadow: 0 0 0 .2rem rgba(255,193,7,.2);
}
.err404-search .btn {
  border-radius: 0 2rem 2rem 0;
  background: #ffc107;
  border-color: #ffc107;
  color: #1a1a1a;
}

/* Suggerimenti link */
.err404-links {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem;
  justify-content: center;
  margin-top: 2.5rem;
}
.err404-links a {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  font-size: .82rem;
  color: var(--bs-secondary-color);
  text-decoration: none;
  border: 1px solid var(--bs-border-color);
  border-radius: 1.5rem;
  padding: .3rem .85rem;
  transition: color .15s, border-color .15s, background .15s;
}
.err404-links a:hover {
  color: #1a1a1a;
  background: #ffc107;
  border-color: #ffc107;
}

/* Particles canvas */
#err404-canvas {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  pointer-events: none;
  opacity: .4;
}

/* Dark mode tweaks */
[data-bs-theme="dark"] .vinyl-disc {
  box-shadow:
    0 0 0 2px #444,
    0 0 0 4px #111,
    0 0 80px rgba(0,0,0,.7),
    0 0 120px rgba(255,193,7,.12);
}
[data-bs-theme="dark"] .err404-search .form-control {
  background: #1a1a1a;
}
</style>

<div class="err404-wrap position-relative">

  <!-- Canvas particelle musicali -->
  <canvas id="err404-canvas"></canvas>

  <!-- Cerchi decorativi -->
  <div class="err404-bg-circle c1"></div>
  <div class="err404-bg-circle c2"></div>
  <div class="err404-bg-circle c3"></div>

  <!-- Vinile animato — hover o click per farlo girare -->
  <div class="vinyl-wrap" id="vinylWrap" title="Clicca per far girare il vinile!">
    <div class="vinyl-disc"></div>
    <div class="vinyl-label">
      <span class="vinyl-num">404</span>
      <span class="vinyl-txt">Not Found</span>
    </div>
    <div class="needle-wrap">
      <div class="needle-head"></div>
      <div class="needle-arm"></div>
    </div>
  </div>

  <!-- Codice errore -->
  <div class="err404-code" data-text="404">404</div>

  <!-- Badge contesto -->
  <div class="err404-context-badge">
    <i class="bi <?= $icon ?>"></i>
    <?= htmlspecialchars($label) ?> non trovato
  </div>

  <!-- Sottotitolo -->
  <p class="err404-subtitle">
    Questo solco non esiste nel tuo archivio
  </p>

  <!-- Quote casuale -->
  <blockquote class="err404-quote">
    <?= htmlspecialchars($q[0]) ?>
    <cite><?= htmlspecialchars($q[1]) ?></cite>
  </blockquote>

  <!-- CTA principali -->
  <div class="err404-actions">
    <a href="<?= BASE_URL ?>" class="btn btn-warning">
      <i class="bi bi-house-fill me-1"></i>Torna alla Dashboard
    </a>
    <a href="<?= BASE_URL ?>/index.php?route=albums/list" class="btn btn-outline-secondary">
      <i class="bi bi-collection me-1"></i>Sfoglia l'Archivio
    </a>
    <button onclick="history.back()" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Torna indietro
    </button>
  </div>

  <!-- Mini search bar -->
  <form class="err404-search d-flex" action="<?= BASE_URL ?>/index.php" method="get">
    <input type="hidden" name="route" value="search/index">
    <input type="search" name="q" class="form-control"
           placeholder="Cerca artista o titolo…"
           autocomplete="off">
    <button type="submit" class="btn">
      <i class="bi bi-search"></i>
    </button>
  </form>

  <!-- Shortcut links -->
  <div class="err404-links">
    <a href="<?= BASE_URL ?>/index.php?route=albums/create">
      <i class="bi bi-plus-circle"></i>Aggiungi disco
    </a>
    <a href="<?= BASE_URL ?>/index.php?route=playlists">
      <i class="bi bi-collection-play"></i>Playlist
    </a>
    <a href="<?= BASE_URL ?>/index.php?route=artists/index">
      <i class="bi bi-person-music"></i>Artisti
    </a>
    <a href="<?= BASE_URL ?>/index.php?route=search/index">
      <i class="bi bi-search"></i>Ricerca avanzata
    </a>
    <a href="<?= BASE_URL ?>/index.php?route=settings">
      <i class="bi bi-gear"></i>Impostazioni
    </a>
  </div>

</div>

<script>
(function () {
  /* ── Vinile: toggle spinning al click ── */
  var vw = document.getElementById('vinylWrap');
  if (vw) {
    vw.addEventListener('click', function () {
      vw.classList.toggle('spinning');
    });
  }

  /* ── Particelle musicali (note, ♪, ♫, ●) ── */
  var canvas = document.getElementById('err404-canvas');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');

  var symbols = ['♪', '♫', '♬', '♩', '●', '○'];
  var particles = [];
  var W, H;

  function resize() {
    W = canvas.width  = canvas.offsetWidth;
    H = canvas.height = canvas.offsetHeight;
  }
  resize();
  window.addEventListener('resize', resize);

  // Colore dal tema corrente
  function getColor() {
    return document.documentElement.getAttribute('data-bs-theme') === 'dark'
      ? 'rgba(255,193,7,'
      : 'rgba(180,130,0,';
  }

  // Inizializza particelle
  for (var i = 0; i < 28; i++) {
    particles.push({
      x:    Math.random() * (W || 800),
      y:    Math.random() * (H || 600),
      vx:   (Math.random() - .5) * .6,
      vy:   -(Math.random() * .8 + .3),
      size: Math.random() * 14 + 10,
      sym:  symbols[Math.floor(Math.random() * symbols.length)],
      alpha: Math.random() * .5 + .1,
      life:  Math.random(),
    });
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    var col = getColor();
    particles.forEach(function (p) {
      p.x += p.vx;
      p.y += p.vy;
      p.life += .003;
      p.alpha = .15 + .35 * Math.sin(p.life * Math.PI);

      if (p.y < -30) {
        p.y = H + 20;
        p.x = Math.random() * W;
        p.life = 0;
      }
      if (p.x < -20 || p.x > W + 20) {
        p.vx = -p.vx;
      }

      ctx.globalAlpha = p.alpha;
      ctx.fillStyle   = col + p.alpha + ')';
      ctx.font        = p.size + 'px serif';
      ctx.fillText(p.sym, p.x, p.y);
    });
    ctx.globalAlpha = 1;
    requestAnimationFrame(draw);
  }
  draw();
})();
</script>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>