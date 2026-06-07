(function () {
  'use strict';

  const BASE_URL = (typeof window.BASE_URL !== 'undefined' && window.BASE_URL)
    ? window.BASE_URL
    : (document.querySelector('meta[name="base-url"]')?.content || '');

  if (!BASE_URL) {
    console.warn('[youtube-player] BASE_URL non trovata.');
    return;
  }

  const API_ENDPOINT = BASE_URL + '/api/youtube-track.php';
  const EMBED_PARAMS = 'autoplay=1&rel=0&modestbranding=1';

  function createLightbox() {
    var lb = document.createElement('div');
    lb.id = 'yt-lightbox';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.innerHTML = [
      '<div id="yt-lightbox-backdrop"></div>',
      '<div id="yt-lightbox-panel">',
        '<div id="yt-lightbox-header">',
          '<span id="yt-track-label"></span>',
          '<button id="yt-lightbox-close" title="Chiudi (ESC)">',
            '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">',
              '<line x1="18" y1="6" x2="6" y2="18"/>',
              '<line x1="6" y1="6" x2="18" y2="18"/>',
            '</svg>',
          '</button>',
        '</div>',
        '<div id="yt-iframe-wrapper">',
          '<div id="yt-spinner">',
            '<div class="yt-spin-ring"></div>',
            '<span>Ricerca in corso...</span>',
          '</div>',
          '<iframe id="yt-iframe" allow="autoplay; encrypted-media; fullscreen" allowfullscreen frameborder="0" title="YouTube video player"></iframe>',
        '</div>',
      '</div>'
    ].join('');
    document.body.appendChild(lb);
    return lb;
  }

  var lightbox   = null;
  var iframe     = null;
  var spinner    = null;
  var trackLabel = null;
  var isOpen     = false;

  function init() {
    lightbox   = createLightbox();
    iframe     = document.getElementById('yt-iframe');
    spinner    = document.getElementById('yt-spinner');
    trackLabel = document.getElementById('yt-track-label');

    document.getElementById('yt-lightbox-close').addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && isOpen) { closeLightbox(); }
    });

    bindTrackButtons();
  }

  function bindTrackButtons() {
    var btns = document.querySelectorAll('.btn-yt');
    btns.forEach(function(btn) {
      if (btn.dataset.ytBound) { return; }
      btn.dataset.ytBound = '1';
      btn.addEventListener('click', function() {
        openForTrack(
          btn.dataset.trackId || '',
          btn.dataset.artist  || '',
          btn.dataset.title   || '',
          btn
        );
      });
    });
  }

  function pauseAudioIfPlaying() {
    var audio = document.getElementById('global-audio');
    if (audio && !audio.paused && audio.src) {
      audio.pause();
    }
  }

  function openForTrack(trackId, artist, title, btn) {
    pauseAudioIfPlaying();
    openLightbox(artist + ' - ' + title);
    showSpinner(true);
    iframe.src = '';

    var originalHTML = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = '<div class="yt-btn-spinner"></div>';

    if (btn.dataset.videoId) {
      playVideo(btn.dataset.videoId);
      btn.disabled  = false;
      btn.innerHTML = originalHTML;
      return;
    }

    var params = new URLSearchParams();
    params.set('track_id', trackId);
    params.set('artist', artist);
    params.set('title', title);

    fetch(API_ENDPOINT + '?' + params.toString())
      .then(function(res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.json();
      })
      .then(function(data) {
        btn.disabled  = false;
        btn.innerHTML = originalHTML;
        if (data.error) { showError(data.error); return; }
        btn.dataset.videoId = data.video_id;
        playVideo(data.video_id);
      })
      .catch(function(err) {
        btn.disabled  = false;
        btn.innerHTML = originalHTML;
        showError('Errore: ' + err.message);
      });
  }

  function openLightbox(label) {
    if (trackLabel) { trackLabel.textContent = label || ''; }
    lightbox.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    isOpen = true;
  }

  function playVideo(videoId) {
    showSpinner(false);
    iframe.src = 'https://www.youtube.com/embed/' + videoId + '?' + EMBED_PARAMS;
  }

  function closeLightbox() {
    iframe.src = '';
    lightbox.classList.remove('is-open');
    document.body.style.overflow = '';
    isOpen = false;
  }

  function showSpinner(show) {
    if (!spinner || !iframe) { return; }
    spinner.style.display = show ? 'flex' : 'none';
    iframe.style.display  = show ? 'none' : 'block';
  }

  function showError(msg) {
    showSpinner(false);
    iframe.style.display  = 'none';
    spinner.style.display = 'flex';
    spinner.innerHTML = '<div style="text-align:center;padding:1rem;"><p style="margin:.5rem 0 0;font-size:.85rem;color:#ccc;">' + msg + '</p></div>';
  }

  window.YTPlayer = {
    rebind:    bindTrackButtons,
    stopVideo: function() { if (isOpen) { closeLightbox(); } }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();