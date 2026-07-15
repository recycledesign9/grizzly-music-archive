<?php
$pageTitle = 'Archivio';
require BASE_PATH . '/views/layout/header.php';

// Filtri avanzati attivi: su mobile apre il pannello collassato
// e alimenta il contatore sul toggle.
$advCount = count(array_filter([
  $filters['format_id'] ?? '',
  $filters['genre_id'] ?? '',
  $filters['label_id'] ?? '',
  $filters['year'] ?? '',
]));
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
<form method="get" action="<?= BASE_URL ?>/index.php"
  class="card card-body mb-4 shadow-sm p-3 filters-form<?= $advCount > 0 ? ' filters-open' : '' ?>">
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

    <div class="col-6 col-md-2 adv-filter">
      <select name="format_id" class="form-select form-select-sm">
        <option value="">Tutti i formati</option>
        <?php foreach ($formats as $f): ?>
          <option value="<?= $f['id'] ?>" <?= $filters['format_id'] == $f['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($f['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-md-2 adv-filter">
      <select name="genre_id" class="form-select form-select-sm">
        <option value="">Tutti i generi</option>
        <?php foreach ($genres as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $filters['genre_id'] == $g['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($g['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-md-2 adv-filter">
      <select name="label_id" class="form-select form-select-sm">
        <option value="">Tutte le etichette</option>
        <?php foreach ($labels as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $filters['label_id'] == $l['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($l['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-md-1 adv-filter">
      <input type="number" name="year" class="form-control form-control-sm"
        placeholder="Anno" min="1900" max="<?= date('Y') ?>"
        value="<?= $filters['year'] ?: '' ?>">
    </div>

    <div class="col-4 d-md-none">
      <button type="button"
        class="btn btn-sm btn-outline-secondary w-100 btn-adv-toggle"
        onclick="this.closest('form').classList.toggle('filters-open')">
        <i class="bi bi-sliders me-1"></i>Filtri<?= $advCount > 0 ? ' · ' . $advCount : '' ?>
      </button>
    </div>

    <div class="col-4 col-md-1">
      <button type="submit" class="btn btn-sm btn-primary w-100">
        <i class="bi bi-funnel-fill"></i>
      </button>
    </div>

    <div class="col-4 col-md-1">
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

  <!-- Lista mobile: card compatte, tap sulla riga → dettaglio.
       Modifica ed eliminazione restano nel dropdown della scheda. -->
  <div class="m-card-list d-md-none shadow-sm">
    <?php foreach ($albums as $a): ?>
      <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>" class="m-card">
        <img src="<?= $a['cover_local']
                    ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
                    : BASE_URL . '/public/img/placeholder.png' ?>"
          class="m-card-cover" alt="" loading="lazy">
        <div class="m-card-body">
          <div class="m-card-title"><?= htmlspecialchars($a['title']) ?></div>
          <div class="m-card-sub"><?= htmlspecialchars($a['artist_name']) ?></div>
          <div class="m-card-meta">
            <?php
            $rowFormats = !empty($a['formats'])
              ? $a['formats']
              : [['name' => $a['format_name']]];
            ?>
            <?php foreach ($rowFormats as $fmt): ?>
              <span class="badge badge-format bg-<?= formatBadge($fmt['name']) ?>">
                <?= htmlspecialchars($fmt['name']) ?>
              </span>
            <?php endforeach; ?>
            <?php if (!empty($a['year'])): ?>
              <span><?= (int)$a['year'] ?></span>
            <?php endif; ?>
            <?php if (!empty($a['track_count'])): ?>
              <span><?= (int)$a['track_count'] ?> tracce</span>
            <?php endif; ?>
          </div>
        </div>
        <i class="bi bi-chevron-right m-card-arrow"></i>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Archivio desktop: righe ibride nel linguaggio grz- del dashboard
       (cover, badge overlay, gerarchia titolo/artista) invece della
       tabella Bootstrap grezza. L'ordinamento resta sugli stessi
       header link; solo la resa delle righe cambia da <tr> a blocchi
       flex. condition/copies arrivano già da Album::getAll(), qui
       finalmente mostrati. -->
  <div class="grz-archive-list d-none d-md-block shadow-sm">
    <div class="grz-archive-head">
      <span class="grz-col-cover"></span>
      <a class="grz-col-title" href="<?= BASE_URL ?>/index.php?<?= http_build_query(array_merge($filters, [
                                            'route' => 'albums/list',
                                            'order' => 'a.title',
                                            'dir'   => ($order === 'a.title' && $dir === 'ASC') ? 'DESC' : 'ASC',
                                            'per_page' => $pagination['per_page'],
                                            'page' => 1
                                          ])) ?>">Titolo</a>
      <a class="grz-col-artist" href="<?= BASE_URL ?>/index.php?<?= http_build_query(array_merge($filters, [
                                            'route' => 'albums/list',
                                            'order' => 'ar.name',
                                            'dir'   => ($order === 'ar.name' && $dir === 'ASC') ? 'DESC' : 'ASC',
                                            'per_page' => $pagination['per_page'],
                                            'page' => 1
                                          ])) ?>">Artista</a>
      <span class="grz-col-genre">Genere</span>
      <span class="grz-col-format">Formato</span>
      <a class="grz-col-year" href="<?= BASE_URL ?>/index.php?<?= http_build_query(array_merge($filters, [
                                            'route' => 'albums/list',
                                            'order' => 'a.year',
                                            'dir'   => ($order === 'a.year' && $dir === 'ASC') ? 'DESC' : 'ASC',
                                            'per_page' => $pagination['per_page'],
                                            'page' => 1
                                          ])) ?>">Anno</a>
      <span class="grz-col-tracks">Tracce</span>
      <span class="grz-col-actions">Azioni</span>
    </div>

    <?php foreach ($albums as $a): ?>
      <?php
      $rowFormats = !empty($a['formats'])
        ? $a['formats']
        : [['name' => $a['format_name']]];
      $totalTracks = (int)($a['track_count'] ?? 0);
      $tracksWithAudio = (int)($a['tracks_with_audio_count'] ?? 0);
      // Verde a due note SOLO se tutte le tracce hanno audio,
      // arancio a una nota se mancano del tutto o solo in parte.
      $hasAudio = $totalTracks > 0 && $tracksWithAudio >= $totalTracks;
      $audioTitle = $hasAudio
        ? 'Tutte le tracce hanno audio'
        : ($tracksWithAudio > 0
            ? $tracksWithAudio . ' di ' . $totalTracks . ' tracce con audio'
            : 'Nessun file audio caricato');
      ?>
      <div class="grz-archive-row">
        <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>" class="grz-col-cover">
          <img src="<?= $a['cover_local']
                      ? BASE_URL . '/public/uploads/' . htmlspecialchars($a['cover_local'])
                      : BASE_URL . '/public/img/placeholder.png' ?>"
            alt="" loading="lazy" draggable="false">
        </a>

        <div class="grz-col-title">
          <a href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>" class="grz-ar-title">
            <?= htmlspecialchars($a['title']) ?>
          </a>
          <?php if ((int)($a['copies'] ?? 1) > 1): ?>
            <span class="grz-ar-copies">×<?= (int)$a['copies'] ?></span>
          <?php endif; ?>
        </div>

        <div class="grz-col-artist">
          <a href="<?= BASE_URL ?>/index.php?route=artists/profile/<?= $a['artist_id'] ?>" class="grz-ar-artist">
            <?= htmlspecialchars($a['artist_name']) ?>
          </a>
        </div>

        <div class="grz-col-genre">
          <?= !empty($a['genre_name']) ? htmlspecialchars($a['genre_name']) : '<span class="text-muted">—</span>' ?>
        </div>

        <div class="grz-col-format">
          <?php foreach ($rowFormats as $fmt): ?>
            <span class="badge badge-format bg-<?= formatBadge($fmt['name']) ?>">
              <?= htmlspecialchars($fmt['name']) ?>
            </span>
          <?php endforeach; ?>
        </div>

        <div class="grz-col-year"><?= !empty($a['year']) ? (int)$a['year'] : '—' ?></div>

        <div class="grz-col-tracks" title="<?= $audioTitle ?>">
          <?php if ($a['track_count']): ?>
            <i class="bi <?= $hasAudio ? 'bi-music-note-beamed grz-track-audio' : 'bi-music-note grz-track-noaudio' ?>"></i><?= (int)$a['track_count'] ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </div>

        <div class="grz-col-actions dropdown">
          <button type="button" class="btn btn-xs btn-outline-secondary"
            data-bs-toggle="dropdown" aria-expanded="false" title="Azioni">
            <i class="bi bi-three-dots-vertical"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li>
              <a class="dropdown-item small" href="<?= BASE_URL ?>/index.php?route=albums/detail/<?= $a['id'] ?>">
                <i class="bi bi-eye me-2 text-muted"></i>Dettaglio
              </a>
            </li>
            <li>
              <a class="dropdown-item small" href="<?= BASE_URL ?>/index.php?route=albums/edit/<?= $a['id'] ?>">
                <i class="bi bi-pencil me-2 text-muted"></i>Modifica
              </a>
            </li>
            <li>
              <button type="button" class="dropdown-item small text-danger"
                data-bs-toggle="modal" data-bs-target="#deleteModal"
                data-id="<?= $a['id'] ?>" data-title="<?= htmlspecialchars($a['title']) ?>">
                <i class="bi bi-trash me-2"></i>Elimina
              </button>
            </li>
          </ul>
        </div>
      </div>
    <?php endforeach; ?>
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
      Visualizzati <?= $pagination['from'] ?>–<?= $pagination['to'] ?> di <?= $pagination['total'] ?> titoli
    </div>

    <!-- Paginazione numerica — solo se servono più pagine.
         Finestra attorno alla pagina corrente + prima/ultima pagina,
         con "…" per i salti: utile anche quando l'archivio cresce
         e le pagine diventano tante. -->
    <?php if ($totalPages > 1):
      $pageItems = [1];
      for ($p = $currentPage - 1; $p <= $currentPage + 1; $p++) {
        if ($p > 1 && $p < $totalPages) $pageItems[] = $p;
      }
      if ($totalPages > 1) $pageItems[] = $totalPages;
      $pageItems = array_values(array_unique($pageItems));
      sort($pageItems);
    ?>
      <nav class="grz-pagination" aria-label="Paginazione">
        <a class="grz-page-btn grz-page-nav<?= $currentPage <= 1 ? ' disabled' : '' ?>"
          href="<?= $currentPage <= 1 ? '#' : BASE_URL . '/index.php?' . http_build_query(array_merge($baseParams, ['page' => $currentPage - 1])) ?>"
          aria-label="Pagina precedente">
          <i class="bi bi-chevron-left"></i>
        </a>

        <?php $prev = 0; foreach ($pageItems as $p): ?>
          <?php if ($p - $prev > 1): ?>
            <span class="grz-page-ellipsis">…</span>
          <?php endif; ?>
          <a class="grz-page-btn<?= $p == $currentPage ? ' active' : '' ?>"
            href="<?= BASE_URL . '/index.php?' . http_build_query(array_merge($baseParams, ['page' => $p])) ?>">
            <?= $p ?>
          </a>
          <?php $prev = $p; ?>
        <?php endforeach; ?>

        <a class="grz-page-btn grz-page-nav<?= $currentPage >= $totalPages ? ' disabled' : '' ?>"
          href="<?= $currentPage >= $totalPages ? '#' : BASE_URL . '/index.php?' . http_build_query(array_merge($baseParams, ['page' => $currentPage + 1])) ?>"
          aria-label="Pagina successiva">
          <i class="bi bi-chevron-right"></i>
        </a>
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
    case 'Tape':
      return 'success';
    default:
      return 'secondary';
  }
}



?>
