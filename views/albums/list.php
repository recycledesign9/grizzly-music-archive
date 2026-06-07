<?php
$pageTitle = 'Archivio';
require BASE_PATH . '/views/layout/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-collection me-2"></i>Archivio
    <span class="badge bg-secondary ms-1"><?= $pagination['total'] ?></span>
  </h4>
  <a href="<?= BASE_URL ?>/index.php?route=albums/create"
    class="btn btn-sm btn-warning">
    <i class="bi bi-plus-lg me-1"></i>Aggiungi
  </a>
</div>

<!-- Filtri -->
<form method="get" action="<?= BASE_URL ?>/index.php" class="card card-body mb-4 shadow-sm p-3">
  <input type="hidden" name="route" value="albums/list">
  <input type="hidden" name="page" value="1">
  <input type="hidden" name="per_page" value="<?= $pagination['per_page'] ?>">
  <input type="hidden" name="order" value="<?= $order ?>">
  <input type="hidden" name="dir" value="<?= $dir ?>">

  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-3">
      <input type="search" name="q" class="form-control form-control-sm"
        placeholder="Cerca titolo o artista…"
        value="<?= htmlspecialchars($filters['q']) ?>">
    </div>

    <div class="col-6 col-md-2">
      <select name="format_id" class="form-select form-select-sm">
        <option value="">Tutti i formati</option>
        <?php foreach ($formats as $f): ?>
          <option value="<?= $f['id'] ?>" <?= $filters['format_id'] == $f['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($f['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-md-2">
      <select name="genre_id" class="form-select form-select-sm">
        <option value="">Tutti i generi</option>
        <?php foreach ($genres as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $filters['genre_id'] == $g['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($g['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-md-2">
      <select name="label_id" class="form-select form-select-sm">
        <option value="">Tutte le etichette</option>
        <?php foreach ($labels as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $filters['label_id'] == $l['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($l['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-md-1">
      <input type="number" name="year" class="form-control form-control-sm"
        placeholder="Anno" min="1900" max="<?= date('Y') ?>"
        value="<?= $filters['year'] ?: '' ?>">
    </div>

    <div class="col-6 col-md-1">
      <button type="submit" class="btn btn-sm btn-primary w-100">
        <i class="bi bi-funnel-fill"></i>
      </button>
    </div>

    <div class="col-6 col-md-1">
      <a href="<?= BASE_URL ?>/index.php?route=albums/list"
        class="btn btn-sm btn-outline-secondary w-100">
        <i class="bi bi-x-circle"></i>
      </a>
    </div>
  </div>
</form>

<!-- Tabella -->
<?php if (empty($albums)): ?>
  <div class="alert alert-info">Nessun disco trovato.</div>
<?php else: ?>

  <div class="table-responsive shadow-sm rounded">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-dark">
        <tr>
          <th style="width:60px">Cover</th>

          <th>
            <a href="<?= BASE_URL ?>/index.php?<?= http_build_query(array_merge($filters, [
                                                  'route' => 'albums/list',
                                                  'order' => 'a.title',
                                                  'dir'   => ($order === 'a.title' && $dir === 'ASC') ? 'DESC' : 'ASC',
                                                  'per_page' => $pagination['per_page'],
                                                  'page' => 1
                                                ])) ?>" class="text-white text-decoration-none">
              Titolo
            </a>
          </th>

          <th>
            <a href="<?= BASE_URL ?>/index.php?<?= http_build_query(array_merge($filters, [
                                                  'route' => 'albums/list',
                                                  'order' => 'ar.name',
                                                  'dir'   => ($order === 'ar.name' && $dir === 'ASC') ? 'DESC' : 'ASC',
                                                  'per_page' => $pagination['per_page'],
                                                  'page' => 1
                                                ])) ?>" class="text-white text-decoration-none">
              Artista
            </a>
          </th>

          <th>Formato</th>

          <th>
            <a href="<?= BASE_URL ?>/index.php?<?= http_build_query(array_merge($filters, [
                                                  'route' => 'albums/list',
                                                  'order' => 'a.year',
                                                  'dir'   => ($order === 'a.year' && $dir === 'ASC') ? 'DESC' : 'ASC',
                                                  'per_page' => $pagination['per_page'],
                                                  'page' => 1
                                                ])) ?>" class="text-white text-decoration-none">
              Anno
            </a>
          </th>

          <th>Genere</th>
          <th>Tracce</th>
          <th class="text-end">Azioni</th>
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
                class="link-album fw-semibold">
                <?= htmlspecialchars($a['title']) ?>
              </a>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $a['artist_id'] ?>"
                class="link-artist">
                <?= htmlspecialchars($a['artist_name']) ?>
              </a>
            </td>

            <td>
              <span class="badge badge-format bg-<?= formatBadge($a['format_name']) ?>">
                <?= htmlspecialchars($a['format_name']) ?>
              </span>
            </td>

            <td><?= htmlspecialchars($a['year'] ?? '—') ?></td>
            <td><?= htmlspecialchars($a['genre_name'] ?? '—') ?></td>

            <td class="text-center">
              <?= $a['track_count'] ?: '—' ?>
            </td>

            <td class="text-end">
              <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>"
                class="btn btn-xs btn-outline-primary" title="Dettaglio">
                <i class="bi bi-eye"></i>
              </a>

              <a href="<?= BASE_URL ?>/index.php?route=albums/edit/<?= $a['id'] ?>"
                class="btn btn-xs btn-outline-secondary" title="Modifica">
                <i class="bi bi-pencil"></i>
              </a>

              <button type="button"
                class="btn btn-xs btn-outline-danger"
                data-bs-toggle="modal"
                data-bs-target="#deleteModal"
                data-id="<?= $a['id'] ?>"
                data-title="<?= htmlspecialchars($a['title']) ?>"
                title="Elimina">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- BARRA INFERIORE — sempre visibile -->
  <?php
  $currentPage = $pagination['page'];
  $totalPages  = $pagination['total_pages'];
  $baseParams  = array_merge($filters, [
    'route'    => 'albums/list',
    'order'    => $order,
    'dir'      => $dir,
    'per_page' => $pagination['per_page'],
  ]);
  ?>
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">

    <!-- Info record -->
    <div class="small text-muted">
      Visualizzati <?= $pagination['from'] ?>–<?= $pagination['to'] ?> di <?= $pagination['total'] ?> dischi
    </div>

    <!-- Paginazione numerica — solo se servono più pagine -->
    <?php if ($totalPages > 1): ?>
      <nav>
        <ul class="pagination pagination-sm mb-0">

          <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link"
              href="<?= $currentPage <= 1 ? '#' : BASE_URL . '/index.php?' . http_build_query(array_merge($baseParams, ['page' => $currentPage - 1])) ?>">
              &laquo;
            </a>
          </li>

          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p == $currentPage ? 'active' : '' ?>">
              <a class="page-link"
                href="<?= BASE_URL . '/index.php?' . http_build_query(array_merge($baseParams, ['page' => $p])) ?>">
                <?= $p ?>
              </a>
            </li>
          <?php endfor; ?>

          <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link"
              href="<?= $currentPage >= $totalPages ? '#' : BASE_URL . '/index.php?' . http_build_query(array_merge($baseParams, ['page' => $currentPage + 1])) ?>">
              &raquo;
            </a>
          </li>

        </ul>
      </nav>
    <?php endif; ?>

    <!-- Selettore per_page — SEMPRE visibile, anche con una sola pagina -->
    <form method="get" action="<?= BASE_URL ?>/index.php" class="d-flex align-items-center gap-2">
      <?php foreach ($filters as $k => $v): ?>
        <?php if ($v !== ''): ?>
          <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endif; ?>
      <?php endforeach; ?>

      <input type="hidden" name="route" value="albums/list">
      <input type="hidden" name="order" value="<?= $order ?>">
      <input type="hidden" name="dir" value="<?= $dir ?>">
      <input type="hidden" name="page" value="1">

      <label class="small text-muted mb-0">Mostra</label>
      <select name="per_page" class="form-select form-select-sm" style="width:auto" onchange="this.form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))">
        <?php foreach ($pagination['allowed_per_page'] as $n): ?>
          <option value="<?= $n ?>" <?= $pagination['per_page'] == $n ? 'selected' : '' ?>>
            <?= $n ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="small text-muted">per pagina</span>
    </form>

  </div>

<?php endif; ?>

<!-- Modal conferma eliminazione -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">
          <i class="bi bi-exclamation-triangle me-2"></i>Elimina disco
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body small">
        Sei sicuro di voler eliminare <strong id="deleteAlbumTitle"></strong>?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-secondary"
          data-bs-dismiss="modal">Annulla</button>

        <form id="deleteForm" method="post">
          <input type="hidden" name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
        </form>

      </div>
    </div>
  </div>
</div>

<?php
require BASE_PATH . '/views/layout/footer.php';

function formatBadge(string $f): string
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