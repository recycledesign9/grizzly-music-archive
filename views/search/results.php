<?php
$pageTitle = 'Ricerca';
require BASE_PATH . '/views/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-search me-2"></i>
    Risultati per:
    <?php if (!empty($q)): ?>
      <span class="text-primary">"<?= htmlspecialchars($q) ?>"</span>
    <?php endif; ?>
  </h4>
  <a href="<?= BASE_URL ?>/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
</div>

<?php if (!isset($_GET['q'])): ?>
  <!-- pagina aperta senza ricerca -->
  <div class="alert alert-secondary">
    Inizia a cercare un artista o un album.
  </div>

<?php elseif (strlen($q) < 2): ?>
  <!-- ricerca troppo corta -->
  <div class="alert alert-warning">
    Inserisci almeno 2 caratteri.
  </div>

<?php elseif (empty($albums) && empty($artists)): ?>
  <!-- nessun risultato -->
  <div class="alert alert-info">
    Nessun risultato trovato.
  </div>

<?php else: ?>

  <div class="row g-4">

    <!-- ===== ARTISTI ===== -->
    <?php if (!empty($artists)): ?>
      <div class="col-md-4">
        <div class="card shadow-sm h-100">
          <div class="card-header fw-semibold">
            <i class="bi bi-person me-2"></i>Artisti
          </div>
          <ul class="list-group list-group-flush">
            <?php foreach ($artists as $ar): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $ar['id'] ?>"
                  class="text-decoration-none fw-semibold">
                  <?= htmlspecialchars($ar['name']) ?>
                </a>
                <span class="badge bg-secondary">
                  <?= (int)$ar['album_count'] ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <!-- ===== ALBUM ===== -->
    <?php if (!empty($albums)): ?>
      <div class="col-md-8">
        <div class="card shadow-sm">
          <div class="card-header fw-semibold">
            <i class="bi bi-collection me-2"></i>Album
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th style="width:60px">Cover</th>
                  <th>Titolo</th>
                  <th>Artista</th>
                  <th>Formato</th>
                  <th>Anno</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($albums as $a): ?>
                  <tr>
                    <td>
                      <img src="<?= $a['cover_local']
                                  ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
                                  : BASE_URL . '/public/img/placeholder.png' ?>"
                        class="rounded" style="width:50px;height:auto;">
                    </td>
                    <td>
                      <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
                        class="fw-semibold text-decoration-none">
                        <?= htmlspecialchars($a['title']) ?>
                      </a>
                    </td>
                    <td>
                      <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $a['artist_id'] ?>"
                        class="text-muted text-decoration-none">
                        <?= htmlspecialchars($a['artist_name']) ?>
                      </a>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark border">
                        <?= htmlspecialchars($a['format_name']) ?>
                      </span>
                    </td>
                    <td>
                      <?= htmlspecialchars($a['year'] ?? '—') ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    <?php endif; ?>

  </div>

<?php endif; ?>

<?php require BASE_PATH . '/views/layout/footer.php'; ?>