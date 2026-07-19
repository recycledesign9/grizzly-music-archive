<?php

/** @var array $artist */
/** @var array $albums */
/** @var array $stats */

$pageTitle = $artist['name'] ?? 'Artista';
require BASE_PATH . '/views/layout/header.php';

// Immagine già salvata (locale o remota) per render iniziale
$artistImg = '';
if (!empty($artist['image_local'])) {
  $artistImg = BASE_URL . '/public/uploads/' . htmlspecialchars($artist['image_local']);
} elseif (!empty($artist['image_url'])) {
  $artistImg = htmlspecialchars($artist['image_url']);
}

// La bio è già stata cercata in passato? (anche se vuota)
$bioFetched = !empty($artist['bio_fetched_at']);

// Range anni della collezione
$yearRange = '';
if (!empty($stats['year_min'])) {
  $yearRange = $stats['year_min'];
  if (!empty($stats['year_max']) && $stats['year_max'] != $stats['year_min']) {
    $yearRange .= '–' . $stats['year_max'];
  }
}

// Anni di attività dell'artista (da MusicBrainz)
$activeRange = '';
if (!empty($artist['active_from'])) {
  $activeRange = $artist['active_from'] . '–' . (!empty($artist['active_to']) ? $artist['active_to'] : 'oggi');
}

// Genere principale (dalla collezione)
$mainGenre = $stats['genres'][0] ?? '';

// Discografia ufficiale gia' recuperata?
$discoFetched = !empty($artist['disco_fetched_at']);
?>

<!-- ============================================================
     HERO ARTISTA
     ============================================================ -->
<div class="artist-hero mb-4" id="artistHero">
  <!-- sfondo atmosferico sfocato -->
  <div class="artist-hero__bg"
    <?= $artistImg ? 'style="background-image:url(\'' . $artistImg . '\')"' : '' ?>></div>

  <!-- foto "copertina" grande a destra -->
  <div class="artist-hero__photo" id="artistPhoto"
    <?= $artistImg ? 'style="background-image:url(\'' . $artistImg . '\')"' : '' ?>></div>

  <div class="artist-hero__content">
    <div class="artist-hero__topbar">
      <h1 class="artist-hero__name" id="artistName"><?= htmlspecialchars($artist['name']) ?></h1>
      <a href="<?= BASE_URL ?>/index.php?route=albums/list"
        class="btn btn-sm btn-outline-light artist-hero__back">
        <i class="bi bi-arrow-left"></i>
      </a>
    </div>

    <!-- Meta inline: paese · anni attività · genere principale -->
    <div class="artist-hero__meta" id="artistMeta"
      data-genre="<?= htmlspecialchars($mainGenre) ?>">
      <?php if (!empty($artist['country'])): ?>
        <span class="artist-hero__chip"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($artist['country']) ?></span>
      <?php endif; ?>
      <?php if ($activeRange): ?>
        <span class="artist-hero__chip"><i class="bi bi-calendar3"></i><?= htmlspecialchars($activeRange) ?></span>
      <?php endif; ?>
      <?php if ($mainGenre !== ''): ?>
        <span class="artist-hero__chip"><i class="bi bi-music-note-beamed"></i><?= htmlspecialchars($mainGenre) ?></span>
      <?php endif; ?>
    </div>

    <!-- BIO -->
    <div class="artist-hero__bio" id="artistBio">
      <?php if ($bioFetched && !empty($artist['bio'])): ?>
        <p class="artist-bio-text" id="bioText"><?= nl2br(htmlspecialchars($artist['bio'])) ?></p>
        <button type="button" class="artist-bio-toggle" id="bioToggle" hidden>Mostra tutto</button>
        <?php if (!empty($artist['bio_source'])): ?>
          <div class="artist-bio-source">
            Fonte:
            <?php if (!empty($artist['bio_url'])): ?>
              <a href="<?= htmlspecialchars($artist['bio_url']) ?>" target="_blank" rel="noopener">
                <?= htmlspecialchars(ucfirst($artist['bio_source'])) ?>
              </a>
            <?php else: ?>
              <?= htmlspecialchars(ucfirst($artist['bio_source'])) ?>
            <?php endif; ?>
            <?php if (!empty($artist['bio_lang']) && $artist['bio_lang'] !== 'it'): ?>
              <span class="artist-bio-lang"><?= htmlspecialchars(strtoupper($artist['bio_lang'])) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($bioFetched): ?>
        <p class="artist-bio-empty" id="bioEmpty">
          Nessuna biografia disponibile per questo artista.
        </p>

      <?php else: ?>
        <div class="artist-bio-loading" id="bioLoading">
          <span class="spinner-border spinner-border-sm"></span>
          Recupero biografia in corso…
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============================================================
     STATISTICHE COLLEZIONE
     ============================================================ -->
<div class="artist-stats mb-4">
  <div class="artist-stat">
    <div class="artist-stat__val"><?= (int)($stats['total'] ?? 0) ?></div>
    <div class="artist-stat__lbl">Album</div>
  </div>
  <?php if (!empty($stats['vinili'])): ?>
    <div class="artist-stat">
      <div class="artist-stat__val text-warning"><?= (int)$stats['vinili'] ?></div>
      <div class="artist-stat__lbl">Vinili</div>
    </div>
  <?php endif; ?>
  <?php if (!empty($stats['cd'])): ?>
    <div class="artist-stat">
      <div class="artist-stat__val text-info"><?= (int)$stats['cd'] ?></div>
      <div class="artist-stat__lbl">CD</div>
    </div>
  <?php endif; ?>
  <?php if (!empty($stats['cassette'])): ?>
    <div class="artist-stat">
      <div class="artist-stat__val text-success"><?= (int)$stats['cassette'] ?></div>
      <div class="artist-stat__lbl">Cassette</div>
    </div>
  <?php endif; ?>
  <?php if (!empty($stats['digital'])): ?>
    <div class="artist-stat">
      <div class="artist-stat__val text-primary"><?= (int)$stats['digital'] ?></div>
      <div class="artist-stat__lbl">Digital</div>
    </div>
  <?php endif; ?>
  <?php if ($yearRange): ?>
    <div class="artist-stat">
      <div class="artist-stat__val"><?= htmlspecialchars($yearRange) ?></div>
      <div class="artist-stat__lbl">Periodo</div>
    </div>
  <?php endif; ?>
</div>

<!-- ============================================================
     DISCOGRAFIA
     ============================================================ -->
<h5 class="artist-section-title mb-3">
  <i class="bi bi-disc me-2"></i>Archivio
</h5>

<?php if (empty($albums)): ?>
  <div class="alert alert-info">Nessun disco presente per questo artista.</div>
<?php else: ?>

  <div class="artist-discography">
    <?php foreach ($albums as $a): ?>
      <?php
      // Tutti i formati posseduti della scheda (badge informativi:
      // la scheda è una sola). Fallback sulla colonna legacy.
      $cardFormats = !empty($a['formats'])
        ? $a['formats']
        : [['name' => $a['format_name']]];
      ?>
      <a class="disco-card album-card"
        href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>">
        <div class="disco-card__cover">
          <img src="<?= $a['cover_local']
                      ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
                      : ($a['cover_url'] ? htmlspecialchars($a['cover_url']) : BASE_URL . '/public/img/placeholder.png') ?>"
            alt="<?= htmlspecialchars($a['title']) ?>" loading="lazy">
          <span class="disco-card__fmt d-flex flex-wrap gap-1">
            <?php foreach ($cardFormats as $fmt): ?>
              <span class="badge badge-format bg-<?= formatBadge($fmt['name']) ?>">
                <?= htmlspecialchars($fmt['name']) ?>
              </span>
            <?php endforeach; ?>
          </span>
        </div>
        <div class="disco-card__body">
          <div class="disco-card__title"><?= htmlspecialchars($a['title']) ?></div>
          <div class="disco-card__meta">
            <span><?= htmlspecialchars($a['year'] ?? '—') ?></span>
            <?php if (!empty($a['genre_name'])): ?>
              <span class="disco-card__dot">·</span>
              <span><?= htmlspecialchars($a['genre_name']) ?></span>
            <?php endif; ?>
            <?php if (!empty($a['track_count'])): ?>
              <span class="disco-card__dot">·</span>
              <span><?= (int)$a['track_count'] ?> tracce</span>
            <?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<!-- ============================================================
     DISCOGRAFIA UFFICIALE (studio album, da MusicBrainz)
     ============================================================ -->
<div class="official-disco mt-5" id="officialDisco">
  <h5 class="artist-section-title mb-3">
    <i class="bi bi-vinyl me-2"></i>Discografia ufficiale
    <span class="official-disco__hint">album in studio</span>
  </h5>

  <div id="officialDiscoBody">
    <div class="artist-bio-loading text-muted" id="discoLoading" style="font-style:italic">
      <span class="spinner-border spinner-border-sm"></span>
      Recupero discografia in corso…
    </div>
  </div>
</div>

<!-- ============================================================
     SCRIPT — fetch bio AJAX + clamp/expand
     ============================================================ -->
<script>
  (function() {
    var BASE = '<?= BASE_URL ?>';
    var ARTIST_ID = <?= (int)$artist['id'] ?>;
    var ALREADY_FETCHED = <?= $bioFetched ? 'true' : 'false' ?>;
    var DISCO_FETCHED = <?= $discoFetched ? 'true' : 'false' ?>;

    function esc(s) {
      var d = document.createElement('div');
      d.textContent = (s == null ? '' : String(s));
      return d.innerHTML;
    }

    // ---- Expand/collapse bio (clamp) -------------------------
    function setupClamp() {
      var txt = document.getElementById('bioText');
      var btn = document.getElementById('bioToggle');
      if (!txt || !btn) return;

      if (txt.scrollHeight > txt.clientHeight + 4) {
        btn.hidden = false;
        btn.onclick = function() {
          var expanded = txt.classList.toggle('is-expanded');
          btn.textContent = expanded ? 'Mostra meno' : 'Mostra tutto';
        };
      }
    }

    // ---- Ricostruisce i chip: paese + periodo + genere -------
    // FIX: prima la funzione si attivava solo se il box era vuoto,
    // ma il genere è già presente da PHP -> i chip paese/periodo
    // non comparivano al primo fetch. Ora ricostruisce sempre.
    function renderChips(data) {
      var meta = document.getElementById('artistMeta');
      if (!meta) return;

      var genre = meta.getAttribute('data-genre') || '';
      var chips = '';

      if (data.country) {
        chips += '<span class="artist-hero__chip"><i class="bi bi-geo-alt"></i>' + esc(data.country) + '</span>';
      }
      if (data.active_from) {
        var to = data.active_to ? data.active_to : 'oggi';
        chips += '<span class="artist-hero__chip"><i class="bi bi-calendar3"></i>' + esc(data.active_from) + '–' + esc(to) + '</span>';
      }
      if (genre) {
        chips += '<span class="artist-hero__chip"><i class="bi bi-music-note-beamed"></i>' + esc(genre) + '</span>';
      }

      // aggiorna solo se abbiamo qualcosa in più del solo genere
      if (data.country || data.active_from) {
        meta.innerHTML = chips;
      }
    }

    // ---- Render della bio recuperata -------------------------
    function renderMeta(data) {
      var bioBox = document.getElementById('artistBio');
      if (bioBox) {
        if (data.bio && data.bio.trim() !== '') {
          var srcHtml = '';
          if (data.bio_source) {
            var label = data.bio_source.charAt(0).toUpperCase() + data.bio_source.slice(1);
            var inner = data.bio_url
              ? '<a href="' + esc(data.bio_url) + '" target="_blank" rel="noopener">' + esc(label) + '</a>'
              : esc(label);
            var lang = (data.bio_lang && data.bio_lang !== 'it')
              ? ' <span class="artist-bio-lang">' + esc(data.bio_lang.toUpperCase()) + '</span>'
              : '';
            srcHtml = '<div class="artist-bio-source">Fonte: ' + inner + lang + '</div>';
          }
          bioBox.innerHTML =
            '<p class="artist-bio-text" id="bioText">' + esc(data.bio) + '</p>' +
            '<button type="button" class="artist-bio-toggle" id="bioToggle" hidden>Mostra tutto</button>' +
            srcHtml;
          setupClamp();
        } else {
          bioBox.innerHTML = '<p class="artist-bio-empty">Nessuna biografia disponibile per questo artista.</p>';
        }
      }

      // Immagine: popola foto grande + sfondo se prima mancavano
      if (data.image) {
        var photo = document.getElementById('artistPhoto');
        if (photo && !photo.style.backgroundImage) {
          photo.style.backgroundImage = "url('" + data.image + "')";
        }
        var bg = document.querySelector('.artist-hero__bg');
        if (bg && !bg.style.backgroundImage) {
          bg.style.backgroundImage = "url('" + data.image + "')";
        }
      }

      // Chip paese / periodo (FIX anomalia)
      renderChips(data);
    }

    // ---- Fetch on demand (solo prima volta) ------------------
    function fetchMeta(done) {
      fetch(BASE + '/index.php?route=artists/fetch-meta/' + ARTIST_ID, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data && data.ok) renderMeta(data);
          else renderMeta({ bio: '' });
        })
        .catch(function() { renderMeta({ bio: '' }); })
        // Eseguito in ogni caso (successo o errore), come un finally:
        // sblocca la chiamata alla discografia SOLO quando fetch-meta
        // ha finito e l'eventuale MBID è stato salvato in DB.
        .then(function() {
          if (typeof done === 'function') done();
        });
    }


    // ---- Discografia ufficiale -------------------------------
    function renderDisco(items) {
      var body = document.getElementById('officialDiscoBody');
      if (!body) return;

      if (!items || !items.length) {
        body.innerHTML = '<p class="text-muted" style="font-style:italic">Discografia non disponibile per questo artista.</p>';
        return;
      }

      var rows = '';
      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        var yr = it.year ? esc(it.year) : '—';
        var title = esc(it.title);
        var num = (i + 1);

        // Cover: preferisci l'URL fornito dal server (cache locale su
        // disco, o proxy lazy che scarica al primo accesso). Fallback
        // storico su CAA diretto solo se il campo manca. L'onerror
        // resta la rete di sicurezza: placeholder in ogni caso.
        var coverHtml;
        var coverUrl = it.cover || '';
        if (!coverUrl && it.mb_release_group_id) {
          coverUrl = 'https://coverartarchive.org/release-group/'
            + encodeURIComponent(it.mb_release_group_id) + '/front-250';
        }
        if (coverUrl) {
          coverHtml =
            '<span class="off-thumb">' +
              '<img src="' + coverUrl + '" alt="" loading="lazy" ' +
              'onerror="this.parentNode.classList.add(\'is-empty\');this.remove();">' +
              '<i class="bi bi-disc off-thumb__ph"></i>' +
            '</span>';
        } else {
          coverHtml = '<span class="off-thumb is-empty"><i class="bi bi-disc off-thumb__ph"></i></span>';
        }

        rows +=
          '<tr>' +
            '<td class="official-disco__num">' + num + '</td>' +
            '<td class="official-disco__cover">' + coverHtml + '</td>' +
            '<td class="official-disco__title">' + title + '</td>' +
            '<td class="official-disco__year">' + yr + '</td>' +
          '</tr>';
      }

      body.innerHTML =
        '<div class="official-disco__table-wrap">' +
        '<table class="official-disco__table"><tbody>' + rows + '</tbody></table>' +
        '</div>' +
        '<div class="official-disco__source">Fonte: ' +
        '<a href="https://musicbrainz.org/" target="_blank" rel="noopener">MusicBrainz</a> · ' +
        'copertine: <a href="https://coverartarchive.org/" target="_blank" rel="noopener">Cover Art Archive</a></div>';
    }

    function fetchDisco() {
      fetch(BASE + '/index.php?route=artists/fetch-discography/' + ARTIST_ID, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data && data.ok) renderDisco(data.items);
          else renderDisco([]);
        })
        .catch(function() { renderDisco([]); });
    }

    function init() {
      if (ALREADY_FETCHED) {
        setupClamp();
        // Bio già in DB → l'MBID (se esiste) è già disponibile:
        // la discografia può partire subito.
        fetchDisco();
      } else {
        // RACE CONDITION da evitare: fetch-discography dipende
        // dall'MBID che viene trovato e salvato da fetch-meta.
        // Con le due chiamate in parallelo, la discografia leggerebbe
        // un MBID ancora vuoto e mostrerebbe "non disponibile"
        // (prima "funzionava" solo perché il session lock di PHP
        // serializzava le richieste per sbaglio).
        fetchMeta(fetchDisco);
      }
    }

    document.addEventListener('DOMContentLoaded', init);
    if (document.readyState !== 'loading') init();
  })();
</script>

<?php
require BASE_PATH . '/views/layout/footer.php';

/* PHP 7.4 compatibile */
function formatBadge($f)
{
  switch ($f) {
    case 'Vinile':
      return 'warning';
    case 'CD':
      return 'info';
    case 'Musicassetta':
    case 'Tape':
      return 'success';
    case 'Digital':
      return 'primary';
    default:
      return 'secondary';
  }
}