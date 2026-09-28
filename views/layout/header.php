<?php
$pageTitle = $pageTitle ?? 'Music Archive';
$baseUrl   = BASE_URL;

// ------------------------------------------------------------
// Voce di menu corrente, ricavata dalla route. Nella navigazione
// SPA app.js copia lo stesso attributo dalla pagina ricevuta,
// quindi la regola vive solo qui.
// ------------------------------------------------------------
$navRoute = (string)($_GET['route'] ?? '');
if ($navRoute === '' || strpos($navRoute, 'dashboard') === 0) {
  $navCurrent = 'home';
} elseif (strpos($navRoute, 'albums/create') === 0) {
  $navCurrent = 'create';
} elseif (strpos($navRoute, 'albums') === 0 || strpos($navRoute, 'artists') === 0) {
  $navCurrent = 'albums';
} elseif (strpos($navRoute, 'playlists') === 0) {
  $navCurrent = 'playlists';
} elseif (strpos($navRoute, 'search') === 0) {
  $navCurrent = 'search';
} elseif (strpos($navRoute, 'settings') === 0) {
  $navCurrent = 'settings';
} else {
  $navCurrent = '';
}
if (!function_exists('navCurrentAttr')) {
  function navCurrentAttr(string $key, string $current): string
  {
    return $key === $current ? ' aria-current="page"' : '';
  }
}

// ------------------------------------------------------------
// Cache-busting automatico degli asset locali: appende ?v=<mtime>
// all'URL. L'header viene incluso PRIMA del footer, quindi la
// definizione vive qui; il footer ha la stessa definizione con
// guard function_exists e la salta senza conflitti.
// ------------------------------------------------------------
if (!function_exists('asset_v')) {
  function asset_v(string $rel): string
  {
    $t = @filemtime(BASE_PATH . $rel);
    return BASE_URL . $rel . ($t ? '?v=' . $t : '');
  }
}
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="dark">

<head>
  <script>
    // Tema effettivo PRIMA dei fogli di stile. I design token sono definiti
    // solo per data-bs-theme="dark" e "light": un valore diverso (come il
    // vecchio "auto") non attiva nessuno dei due blocchi e lascia navbar e
    // sfondo senza colori. Scelta salvata dall'utente, altrimenti tema del
    // sistema; non viene salvata, così il sistema resta seguito finché
    // l'utente non sceglie. Senza JavaScript vale il "dark" dell'HTML.
    (function() {
      var t = null;
      try { t = localStorage.getItem('theme'); } catch (e) {}
      if (t !== 'dark' && t !== 'light') {
        t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches)
          ? 'light' : 'dark';
      }
      document.documentElement.setAttribute('data-bs-theme', t);
    })();
  </script>
  <meta http-equiv="Cache-Control" content="no-store">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="base-url" content="<?= BASE_URL ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — Music Archive</title>
  <link rel="icon" type="image/png" href="<?= $baseUrl ?>/public/img/logo-grizzly.png">
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= asset_v('/public/css/grizzly-tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset_v('/public/css/app.css') ?>">
</head>

<body>

  <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm grz-nav-fix">
    <div class="container-fluid">
      <a class="navbar-brand d-flex align-items-center" href="<?= $baseUrl ?>">
        <img src="<?= $baseUrl ?>/public/img/logo-grizzly.png"
          alt="Music Archive"
          class="navbar-logo me-2">
        <span class="brand-text">
          <span class="brand-title">Grizzly</span>
          <span class="brand-subtitle">Music Archive</span>
        </span>
      </a>
      <div class="d-flex align-items-center gap-2 d-lg-none">
        <button id="darkToggleMobile" class="btn btn-sm btn-outline-light"
          title="Cambia tema">
          <i class="bi bi-moon-stars-fill"></i>
        </button>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
          data-bs-target="#mainNav">
          <span class="navbar-toggler-icon"></span>
        </button>
      </div>
      <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>"<?= navCurrentAttr('home', $navCurrent) ?>>
              <i class="bi bi-grid-1x2 me-1"></i>Panoramica
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=albums/list"<?= navCurrentAttr('albums', $navCurrent) ?>>
              <i class="bi bi-collection me-1"></i>Archivio
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=albums/create"<?= navCurrentAttr('create', $navCurrent) ?>>
              <i class="bi bi-plus-circle me-1"></i>Aggiungi disco
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=playlists"<?= navCurrentAttr('playlists', $navCurrent) ?>>
              <i class="bi bi-collection-play me-1"></i>Playlist
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=search/index"<?= navCurrentAttr('search', $navCurrent) ?>>
              <i class="bi bi-search me-1"></i>Cerca
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=settings"<?= navCurrentAttr('settings', $navCurrent) ?>>
              <i class="bi bi-gear me-1"></i>Impostazioni
            </a>
          </li>
        </ul>

        <!-- Barra ricerca rapida -->
        <form class="d-flex" action="<?= $baseUrl ?>/index.php" method="get">
          <input type="hidden" name="route" value="search/index">
          <div class="input-group input-group-sm">
            <input type="search" name="q" class="form-control"
              placeholder="Artista o titolo…"
              value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            <button class="btn btn-warning" type="submit">
              <i class="bi bi-search"></i>
            </button>
          </div>
        </form>

        <!-- Dark mode toggle (solo desktop) -->
        <button id="darkToggle" class="btn btn-sm btn-outline-light ms-3 d-none d-lg-block"
          title="Cambia tema">
          <i class="bi bi-moon-stars-fill"></i>
        </button>
      </div>
    </div>
  </nav>

  <script>
    (function() {
      // Chiude il collapse navbar al click su qualsiasi nav-link (mobile)
      var mainNav = document.getElementById('mainNav');
      if (mainNav) {
        mainNav.addEventListener('click', function(e) {
          if (e.target.closest('.nav-link')) {
            var bsCollapse = bootstrap.Collapse.getInstance(mainNav);
            if (bsCollapse) bsCollapse.hide();
          }
        });
      }

      // Risolve 'auto' (o l'assenza di un tema) nel valore effettivo,
      // seguendo la preferenza di sistema. 'dark'/'light' passano invariati.
      function resolveTheme(theme) {
        if (theme === 'dark' || theme === 'light') return theme;
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ?
          'dark' : 'light';
      }

      // Aggiorna solo le icone dei due toggle in base al tema risolto,
      // senza toccare attributo/localStorage (usata anche in fase di sync iniziale).
      function syncIcons(theme) {
        var icon = resolveTheme(theme) === 'dark' ? 'bi-sun-fill' : 'bi-moon-stars-fill';
        ['darkToggle', 'darkToggleMobile'].forEach(function(id) {
          var btn = document.getElementById(id);
          if (btn) btn.querySelector('i').className = 'bi ' + icon;
        });
      }

      // Imposta il tema, lo persiste e aggiorna le icone (usata dal click)
      function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        try { localStorage.setItem('theme', theme); } catch (e) {}
        syncIcons(theme);
      }

      function storedThemeValue() {
        var t = null;
        try { t = localStorage.getItem('theme'); } catch (e) {}
        return (t === 'dark' || t === 'light') ? t : null;
      }

      // Sincronizzazione all'avvio: applica il tema salvato in localStorage
      // (se presente) e allinea SEMPRE le icone al tema effettivamente attivo.
      // Prima di questa fix, l'icona veniva aggiornata solo al click: dopo un
      // refresh o una navigazione a pagina piena, l'attributo data-bs-theme
      // veniva ripristinato correttamente (da app.js) ma l'icona restava
      // quella di default nell'HTML, disallineata dal tema reale.
      // L'attributo è già stato risolto dallo script nel <head>: qui si
      // allineano solo le icone al tema effettivo.
      syncIcons(document.documentElement.getAttribute('data-bs-theme'));

      // Nessuna scelta salvata: se cambia il tema del sistema, Grizzly lo segue.
      if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onSystemChange = function() {
          if (storedThemeValue()) return;
          var t = mq.matches ? 'dark' : 'light';
          document.documentElement.setAttribute('data-bs-theme', t);
          syncIcons(t);
        };
        if (mq.addEventListener) mq.addEventListener('change', onSystemChange);
        else if (mq.addListener) mq.addListener(onSystemChange);
      }

      ['darkToggle', 'darkToggleMobile'].forEach(function(id) {
        var btn = document.getElementById(id);
        if (btn) {
          btn.addEventListener('click', function() {
            var current = resolveTheme(document.documentElement.getAttribute('data-bs-theme'));
            applyTheme(current === 'dark' ? 'light' : 'dark');
          });
        }
      });
    })();
  </script>


  <main id="page-content">
    <div class="container-fluid px-3 px-md-4 px-lg-5 py-4">