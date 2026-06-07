<?php
$pageTitle = $artist['name'] ?? 'Artista';
require BASE_PATH . '/views/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-person me-2"></i>
    <?= htmlspecialchars($artist['name']) ?>
  </h4>

  <a href="<?= BASE_URL ?>/index.php?route=albums/list"
    class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
</div>

<!-- Info artista -->
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <div class="small text-muted">Totale album</div>
        <div class="fs-5 fw-bold"><?= count($albums) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Album -->
<?php if (empty($albums)): ?>
  <div class="alert alert-info">
    Nessun disco presente per questo artista.
  </div>
<?php else: ?>

  <div class="table-responsive shadow-sm rounded">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-dark">
        <tr>
          <th style="width:60px">Cover</th>
          <th>Titolo</th>
          <th>Formato</th>
          <th>Anno</th>
          <th>Genere</th>
          <th>Tracce</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($albums as $a): ?>
          <tr>
            <td>
              <img src="<?= $a['cover_local']
                          ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
                          : BASE_URL . '/public/img/placeholder.png' ?>"
                class="cover-mini rounded">
            </td>

            <td>
              <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
                class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($a['title']) ?>
              </a>
            </td>

            <td>
              <span class="badge bg-<?= formatBadge($a['format_name']) ?>">
                <?= htmlspecialchars($a['format_name']) ?>
              </span>
            </td>

            <td><?= htmlspecialchars($a['year'] ?? '—') ?></td>

            <td class="text-muted small">
              <?= htmlspecialchars($a['genre_name'] ?? '—') ?>
            </td>

            <td class="text-center">
              <?php if ($a['track_count']): ?>
                <span class="badge bg-light text-dark border">
                  <?= $a['track_count'] ?>
                </span>
              <?php else: echo '—';
              endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

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
      return 'success';
    default:
      return 'secondary';
  }
}
?>