/* ============================================================
   Music Archive — search-suggest.js
   Suggerimenti mentre si scrive nei campi di ricerca dell'Archivio
   e della pagina Cerca.

   Markup atteso (views/albums/list.php, views/search/results.php):
     <div data-suggest>
       <input type="search" name="q" data-suggest-input>
     </div>

   Tutti i listener sono delegati sul documento: la navigazione SPA
   di app.js sostituisce il contenuto di <main>, quindi non c'è nulla
   da riagganciare dopo un cambio pagina. Il pannello viene creato al
   primo uso dentro il contenitore del campo e sparisce con esso.

   Le voci sono link normali: il clic passa dall'intercettazione dei
   link di app.js. L'ultima voce è un pulsante submit del form che
   contiene il campo, quindi "tutti i risultati" segue il form della
   pagina (filtro dell'Archivio oppure pagina Cerca).
   ============================================================ */
(function () {
  'use strict';

  var baseMeta = document.querySelector('meta[name="base-url"]');
  var BASE = baseMeta ? baseMeta.content : '';

  var MIN_CHARS = 2;      // stessa soglia del server
  var DEBOUNCE_MS = 180;

  // Stato dell'unico pannello aperto alla volta.
  var state = {
    input: null,       // campo collegato
    panel: null,       // elemento del pannello
    options: [],       // elementi selezionabili con le frecce
    active: -1,        // indice evidenziato
    timer: null,
    controller: null,  // AbortController della richiesta in corso
    seq: 0
  };

  var panelCounter = 0;

  function hostOf(input) {
    return input.closest('[data-suggest]');
  }

  function isSuggestInput(el) {
    return !!(el && el.matches && el.matches('[data-suggest-input]') && hostOf(el));
  }

  function cancelPending() {
    if (state.timer !== null) {
      clearTimeout(state.timer);
      state.timer = null;
    }
    if (state.controller) {
      state.controller.abort();
      state.controller = null;
    }
    state.seq += 1;
  }

  function close() {
    cancelPending();
    if (state.panel) {
      state.panel.hidden = true;
      state.panel.innerHTML = '';
    }
    if (state.input) {
      state.input.setAttribute('aria-expanded', 'false');
      state.input.removeAttribute('aria-activedescendant');
    }
    state.options = [];
    state.active = -1;
  }

  // Collega lo stato al campo indicato, creando il pannello se serve.
  function attach(input) {
    if (state.input === input && state.panel && state.panel.isConnected) return;

    close();

    var host = hostOf(input);
    var panel = host.querySelector('.grz-suggest__panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.className = 'grz-suggest__panel';
      panel.id = 'grz-suggest-' + (++panelCounter);
      panel.setAttribute('role', 'listbox');
      panel.setAttribute('aria-label', 'Suggerimenti');
      panel.hidden = true;
      host.appendChild(panel);
    }

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', panel.id);

    state.input = input;
    state.panel = panel;
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function groupLabel(text) {
    var node = el('div', 'grz-suggest__group', text);
    node.setAttribute('role', 'presentation');
    return node;
  }

  function thumb(src, round) {
    var cls = 'grz-suggest__thumb' + (round ? ' grz-suggest__thumb--round' : '');
    if (!src) {
      var empty = el('span', cls + ' grz-suggest__thumb--empty');
      var icon = el('i', 'bi ' + (round ? 'bi-person-fill' : 'bi-disc'));
      icon.setAttribute('aria-hidden', 'true');
      empty.appendChild(icon);
      return empty;
    }
    var img = el('img', cls);
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    img.addEventListener('error', function () {
      img.replaceWith(thumb('', round));
    });
    img.src = src;
    return img;
  }

  function option(href, media, title, sub, meta) {
    var a = el('a', 'grz-suggest__item');
    a.href = href;
    a.setAttribute('role', 'option');
    a.setAttribute('aria-selected', 'false');
    a.appendChild(media);

    var text = el('span', 'grz-suggest__text');
    text.appendChild(el('span', 'grz-suggest__title', title));
    if (sub) text.appendChild(el('span', 'grz-suggest__sub', sub));
    a.appendChild(text);

    if (meta) a.appendChild(el('span', 'grz-suggest__meta', meta));
    return a;
  }

  function render(data, term) {
    var panel = state.panel;
    if (!panel) return;

    panel.innerHTML = '';
    state.options = [];
    state.active = -1;

    var artists = (data && data.artists) || [];
    var albums = (data && data.albums) || [];
    var tracks = (data && data.tracks) || [];

    if (artists.length) {
      panel.appendChild(groupLabel('Artisti'));
      artists.forEach(function (ar) {
        var count = ar.count === 1 ? '1 album' : ar.count + ' album';
        panel.appendChild(option(ar.url, thumb(ar.image, true), ar.name, '', count));
      });
    }

    if (albums.length) {
      panel.appendChild(groupLabel('Album'));
      albums.forEach(function (al) {
        panel.appendChild(option(
          al.url, thumb(al.cover, false), al.title, al.artist, al.year ? String(al.year) : ''
        ));
      });
    }

    // Tracce: la voce apre la scheda del disco che contiene il brano.
    if (tracks.length) {
      panel.appendChild(groupLabel('Tracce'));
      tracks.forEach(function (tr) {
        panel.appendChild(option(
          tr.url, thumb(tr.cover, false), tr.title, tr.artist + ' · ' + tr.album, tr.duration || ''
        ));
      });
    }

    if (!artists.length && !albums.length && !tracks.length) {
      panel.appendChild(el('div', 'grz-suggest__empty', 'Nessun suggerimento per “' + term + '”.'));
    }

    // Ultima voce: invia il form che contiene il campo.
    var all = el('button', 'grz-suggest__all');
    all.type = 'submit';
    all.setAttribute('role', 'option');
    all.setAttribute('aria-selected', 'false');
    var icon = el('i', 'bi bi-arrow-return-left');
    icon.setAttribute('aria-hidden', 'true');
    all.appendChild(el('span', '', 'Tutti i risultati per “' + term + '”'));
    all.appendChild(icon);
    panel.appendChild(all);

    state.options = Array.prototype.slice.call(
      panel.querySelectorAll('.grz-suggest__item, .grz-suggest__all')
    );
    state.options.forEach(function (node, i) {
      node.id = panel.id + '-opt-' + i;
    });

    panel.hidden = false;
    state.input.setAttribute('aria-expanded', 'true');
  }

  function setActive(index) {
    if (!state.options.length) return;

    if (state.active >= 0 && state.options[state.active]) {
      state.options[state.active].classList.remove('is-active');
      state.options[state.active].setAttribute('aria-selected', 'false');
    }

    // -1 = nessuna voce: il campo torna "libero" e Invio invia il form.
    if (index < -1) index = state.options.length - 1;
    if (index >= state.options.length) index = -1;
    state.active = index;

    if (index === -1) {
      state.input.removeAttribute('aria-activedescendant');
      return;
    }

    var node = state.options[index];
    node.classList.add('is-active');
    node.setAttribute('aria-selected', 'true');
    state.input.setAttribute('aria-activedescendant', node.id);
    if (node.scrollIntoView) node.scrollIntoView({ block: 'nearest' });
  }

  function request(input) {
    var term = input.value.trim();

    cancelPending();

    if (term.length < MIN_CHARS) {
      close();
      return;
    }

    var seq = state.seq;

    state.timer = setTimeout(function () {
      state.timer = null;

      var controller = ('AbortController' in window) ? new AbortController() : null;
      state.controller = controller;

      fetch(BASE + '/index.php?route=search/suggest&q=' + encodeURIComponent(term), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller ? controller.signal : undefined
      })
        .then(function (res) {
          if (!res.ok) throw new Error('search/suggest HTTP ' + res.status);
          return res.json();
        })
        .then(function (data) {
          // Risposta superata da un'altra digitazione, o campo non più
          // in pagina (navigazione SPA nel frattempo): si scarta.
          if (seq !== state.seq) return;
          if (state.input !== input || !input.isConnected) return;
          if (document.activeElement !== input) return;
          if (!data || !data.ok) { close(); return; }
          state.controller = null;
          render(data, term);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          // Un errore dei suggerimenti non deve bloccare la ricerca:
          // il campo resta utilizzabile, il pannello si chiude.
          if (seq === state.seq) close();
        });
    }, DEBOUNCE_MS);
  }

  function isOpen() {
    return !!(state.panel && !state.panel.hidden && state.panel.isConnected);
  }

  // ---- Digitazione ----
  document.addEventListener('input', function (e) {
    if (!isSuggestInput(e.target)) return;
    attach(e.target);
    request(e.target);
  });

  // ---- Tastiera: frecce, Invio, Esc ----
  document.addEventListener('keydown', function (e) {
    if (!isSuggestInput(e.target)) return;
    if (e.isComposing) return;

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!isOpen() || state.input !== e.target) {
        // Riapre i suggerimenti del testo già presente nel campo.
        if (e.key === 'ArrowDown' && e.target.value.trim().length >= MIN_CHARS) {
          e.preventDefault();
          attach(e.target);
          request(e.target);
        }
        return;
      }
      e.preventDefault();
      setActive(state.active + (e.key === 'ArrowDown' ? 1 : -1));
      return;
    }

    if (e.key === 'Enter') {
      if (!isOpen() || state.input !== e.target) {
        // Invio senza pannello: il form parte, la richiesta in sospeso no.
        cancelPending();
        return;
      }
      var node = state.active >= 0 ? state.options[state.active] : null;
      if (node && node.tagName === 'A') {
        e.preventDefault();
        var href = node.getAttribute('href');
        close();
        if (typeof window._spaNavigate === 'function') window._spaNavigate(href);
        else window.location.href = href;
        return;
      }
      // Nessuna voce (o "Tutti i risultati"): invio normale del form.
      close();
      return;
    }

    if (e.key === 'Escape' && isOpen() && state.input === e.target) {
      // Chiude solo il pannello; un secondo Esc svuota il campo come
      // fa il browser per i campi di ricerca.
      e.preventDefault();
      close();
    }
  });

  // ---- Clic fuori dal campo e dal pannello ----
  document.addEventListener('click', function (e) {
    if (!state.input) return;
    var host = state.input.isConnected ? hostOf(state.input) : null;
    if (host && host.contains(e.target)) {
      // Clic su una voce: il link o il submit fanno il loro corso,
      // il pannello si chiude subito.
      if (e.target.closest && e.target.closest('.grz-suggest__item, .grz-suggest__all')) {
        setTimeout(close, 0);
      }
      return;
    }
    close();
  });

  // ---- Il focus esce dal campo con la tastiera ----
  document.addEventListener('focusin', function (e) {
    if (!state.input || !isOpen()) return;
    var host = hostOf(state.input);
    if (host && host.contains(e.target)) return;
    close();
  });

  // ---- Invio del form da qualsiasi punto: niente pannello residuo ----
  document.addEventListener('submit', function () {
    if (state.input) close();
  }, true);
})();
