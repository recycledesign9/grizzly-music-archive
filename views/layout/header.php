<?php
$pageTitle = $pageTitle ?? 'Music Archive';
$baseUrl   = BASE_URL;
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="auto">

<head>
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
  <link rel="stylesheet" href="<?= $baseUrl ?>/public/css/app.css">
</head>

<body>

  <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm">
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
            <a class="nav-link" href="<?= $baseUrl ?>">
              <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=albums/list">
              <i class="bi bi-collection me-1"></i>Archivio
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=albums/create">
              <i class="bi bi-plus-circle me-1"></i>Aggiungi disco
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=playlists">
              <i class="bi bi-collection-play me-1"></i>Playlist
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=search/index">
              <i class="bi bi-search me-1"></i>Cerca
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="<?= $baseUrl ?>/index.php?route=settings">
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

      // Sincronizza i due toggle dark mode (desktop + mobile)
      function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        localStorage.setItem('theme', theme);
        var icon = theme === 'dark' ? 'bi-sun-fill' : 'bi-moon-stars-fill';
        ['darkToggle', 'darkToggleMobile'].forEach(function(id) {
          var btn = document.getElementById(id);
          if (btn) btn.querySelector('i').className = 'bi ' + icon;
        });
      }

      ['darkToggle', 'darkToggleMobile'].forEach(function(id) {
        var btn = document.getElementById(id);
        if (btn) {
          btn.addEventListener('click', function() {
            var current = document.documentElement.getAttribute('data-bs-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
          });
        }
      });
    })();
  </script>


  <main id="page-content">
    <div class="container-fluid px-3 px-md-4 px-lg-5 py-4">