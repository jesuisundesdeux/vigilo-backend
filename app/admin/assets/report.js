// Report for a local authority (admin/report.php): map, print / PDF, slides presentation
(function () {
  'use strict';

  // Slides are printed on 16:9 landscape pages, the document on A4 portrait
  var style = document.createElement('style');
  style.textContent = document.body.classList.contains('report-slides')
    ? '@page { size: 297mm 167mm; margin: 0; }'
    : '@page { size: A4 portrait; }';
  document.head.appendChild(style);

  var map = null;
  var fitMap = function () {};
  var container = document.getElementById('report-map');
  if (container && window.L) {
    var groups = [];
    try { groups = JSON.parse(container.getAttribute('data-groups') || '[]'); } catch (e) {}
    map = L.map(container, { zoomControl: true, attributionControl: true });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      referrerPolicy: 'strict-origin-when-cross-origin'
    }).addTo(map);
    var bounds = [];
    var fit = function () {
      if (bounds.length) {
        map.fitBounds(bounds, { padding: [24, 24], maxZoom: 17 });
      } else {
        map.setView([46.6, 2.4], 5);
      }
    };
    groups.forEach(function (g) {
      L.circleMarker([g.lat, g.lon], {
        radius: Math.min(26, 5 + Math.sqrt(g.count) * 4),
        color: '#1f2328', weight: 1, fillColor: g.color, fillOpacity: 0.75
      }).bindTooltip(g.label).addTo(map);
      bounds.push([g.lat, g.lon]);
    });
    fitMap = fit;
    fit();
    // the map changes size when printed or presented: centered again on the places
    if (window.ResizeObserver) {
      new ResizeObserver(function () {
        if (!printing) {
          map.invalidateSize();
          fit();
        }
      }).observe(container);
    }
  }

  function refreshMap() {
    if (map) {
      map.invalidateSize();
    }
  }
  // Printing: the map gets its printed size (see report.css) and is centered for it, then back
  var printing = false;
  window.addEventListener('beforeprint', function () {
    if (!map) {
      return;
    }
    printing = true;
    var slides = document.body.classList.contains('report-slides');
    container.style.width = slides ? '273mm' : '182mm';
    container.style.height = slides ? '100mm' : '380px';
    map.invalidateSize();
    fitMap();
  });
  window.addEventListener('afterprint', function () {
    if (!map) {
      return;
    }
    printing = false;
    container.style.width = '';
    container.style.height = '';
    map.invalidateSize();
    fitMap();
  });

  var print = document.querySelector('[data-print]');
  if (print) {
    print.addEventListener('click', function () { window.print(); });
  }

  // Presentation: one slide at a time (arrows, space, Escape)
  var slides = Array.prototype.slice.call(document.querySelectorAll('.slide'));
  var current = 0;
  function show(i) {
    current = Math.max(0, Math.min(slides.length - 1, i));
    slides.forEach(function (s, k) { s.classList.toggle('current', k === current); });
    setTimeout(refreshMap, 50);
  }
  function stop() {
    document.body.classList.remove('presenting');
    slides.forEach(function (s) { s.classList.remove('current'); });
    if (document.fullscreenElement && document.exitFullscreen) {
      document.exitFullscreen().catch(function () {});
    }
    setTimeout(refreshMap, 50);
  }
  var present = document.querySelector('[data-present]');
  if (present) {
    present.addEventListener('click', function () {
      document.body.classList.add('presenting');
      show(0);
      if (document.documentElement.requestFullscreen) {
        document.documentElement.requestFullscreen().catch(function () {});
      }
    });
    document.addEventListener('keydown', function (e) {
      if (!document.body.classList.contains('presenting')) {
        return;
      }
      if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
        e.preventDefault();
        show(current + 1);
      } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
        e.preventDefault();
        show(current - 1);
      } else if (e.key === 'Escape') {
        stop();
      }
    });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('presenting') && !e.target.closest('.leaflet-container')) {
        show(current + 1);
      }
    });
    document.addEventListener('fullscreenchange', function () {
      if (!document.fullscreenElement && document.body.classList.contains('presenting')) {
        stop();
      }
    });
  }
})();
