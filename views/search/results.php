<?php
$pageTitle = 'Ricerca';
require BASE_PATH . '/views/layout/header.php';

function srCover(array $a): string {
    if (!empty($a['cover_local'])) return BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local']);
    if (!empty($a['cover_url']))   return htmlspecialchars($a['cover_url']);
    return BASE_URL . '/public/img/placeholder.png';
}

function srFmtClass(string $n): string {
    $n = strtolower($n);
    if (strpos($n, 'vinile') !== false || strpos($n, 'vinyl') !== false) return 'bg-warning';
    if (strpos($n, 'cd')    !== false) return 'bg-info';
    if (strpos($n, 'cass')  !== false || strpos($n, 'tape') !== false) return 'bg-success';
    if (strpos($n, 'digital') !== false) return 'bg-primary';
    return 'bg-secondary';
}

// Tutti i formati della scheda, con fallback sulla colonna legacy
function srFormats(array $a): array {
    if (!empty($a['formats'])) return $a['formats'];
    return [['name' => $a['format_name'] ?? '']];
}
?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
  <div>
    <h4 class="mb-1">
      <i class="bi bi-search opacity-40 me-2"></i>Risultati per:
      <?php if (!empty($q)): ?>
        <span class="text-warning">"<?= htmlspecialchars($q) ?>"</span>
      <?php endif; ?>
    </h4>
    <?php if (!empty($albums) || !empty($artists)): ?>
      <p class="text-muted small mb-0">
        <?php
          $parts = [];
          if (!empty($artists)) $parts[] = count($artists) . ' ' . (count($artists) === 1 ? 'artista' : 'artisti');
          if (!empty($albums))  $parts[] = count($albums) . ' album';
          echo implode(' · ', $parts);
        ?>
      </p>
    <?php endif; ?>
  </div>
  <a href="<?= BASE_URL ?>/index.php" class="btn btn-sm btn-outline-secondary ms-3 flex-shrink-0">
    <i class="bi bi-arrow-left me-1"></i>Indietro
  </a>
</div>

<?php if (!isset($_GET['q'])): ?>

  <div class="text-center py-5 text-muted">
    <i class="bi bi-search display-4 d-block mb-3 opacity-25"></i>
    <p>Inizia a cercare un artista o un album.</p>
  </div>

<?php elseif (strlen($q) < 2): ?>

  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>Inserisci almeno 2 caratteri.
  </div>

<?php elseif (empty($albums) && empty($artists)): ?>

  <div class="text-center py-5 text-muted">
    <i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>
    <p class="mb-1">Nessun risultato per <strong>"<?= htmlspecialchars($q) ?>"</strong></p>
    <p class="small">Prova con un termine diverso.</p>
  </div>

<?php else: ?>

  <!-- ARTISTI -->
  <?php if (!empty($artists)): ?>
  <div class="sr-artists-wrap">
    <div class="sr-label"><i class="bi bi-person-fill"></i>Artisti</div>
    <?php foreach ($artists as $ar): ?>
      <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $ar['id'] ?>"
         class="sr-artist-row">
        <span class="sr-artist-name"><?= htmlspecialchars($ar['name']) ?></span>
        <span class="sr-artist-count"><?= (int)$ar['album_count'] ?> album in archivio</span>
        <i class="bi bi-chevron-right sr-artist-arrow"></i>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ALBUM -->
  <?php if (!empty($albums)): ?>
  <div>
    <div class="sr-label">
      <i class="bi bi-collection-fill"></i>Album
      <span class="badge bg-secondary fw-normal ms-1"><?= count($albums) ?></span>
    </div>

    <?php if (count($albums) === 1):
      $a = $albums[0]; ?>

      <!-- 1 solo album: hero col-12 con cover grande -->
      <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
         class="sr-single-wrap">
        <div class="sr-single-cover">
          <img src="<?= srCover($a) ?>"
               alt="<?= htmlspecialchars($a['title']) ?>"
               onerror="this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
        </div>
        <div class="sr-single-info">
          <div class="sr-single-title"><?= htmlspecialchars($a['title']) ?></div>
          <div class="sr-single-artist"><?= htmlspecialchars($a['artist_name']) ?></div>
          <div class="sr-single-meta">
            <?php foreach (srFormats($a) as $fmt): ?>
              <span class="badge-format <?= srFmtClass($fmt['name']) ?>">
                <?= htmlspecialchars($fmt['name']) ?>
              </span>
            <?php endforeach; ?>
            <?php if (!empty($a['year'])): ?>
              <span class="sr-single-year"><?= (int)$a['year'] ?></span>
            <?php endif; ?>
          </div>
          <div class="sr-single-cta">
            Vai alla scheda <i class="bi bi-arrow-right"></i>
          </div>
        </div>
      </a>

    <?php else: ?>

      <!-- Più album: tabella con cover 72px -->
      <table class="sr-table">
        <thead>
          <tr>
            <th class="sr-cover-cell">Cover</th>
            <th>Titolo</th>
            <th>Artista</th>
            <th>Formato</th>
            <th>Anno</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($albums as $a): ?>
            <tr>
              <td class="sr-cover-cell">
                <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>">
                  <img src="<?= srCover($a) ?>"
                       alt="<?= htmlspecialchars($a['title']) ?>"
                       class="sr-cover-thumb"
                       onerror="this.src='<?= BASE_URL ?>/public/img/placeholder.png'">
                </a>
              </td>
              <td>
                <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
                   class="sr-title-link">
                  <?= htmlspecialchars($a['title']) ?>
                </a>
              </td>
              <td>
                <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $a['artist_id'] ?>"
                   class="sr-artist-link">
                  <?= htmlspecialchars($a['artist_name']) ?>
                </a>
              </td>
              <td>
                <?php foreach (srFormats($a) as $fmt): ?>
                  <span class="badge-format <?= srFmtClass($fmt['name']) ?> me-1">
                    <?= htmlspecialchars($fmt['name']) ?>
                  </span>
                <?php endforeach; ?>
              </td>
              <td class="text-muted"><?= htmlspecialchars($a['year'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>