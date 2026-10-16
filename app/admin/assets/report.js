// Report for a local authority (admin/report.php): map, print / PDF, slides presentation
(function () {
  'use strict';

  // Slides are printed on 16:9 landscape pages, the document on A4 portrait
  var style = document.createElement('style');
  style.textContent = document.body.classList.contains('report-slides')
    ? '@page { size: 297mm 167mm; margin: 0; }'
    : '@page { size: A4 portrait; }';
  document.head.appendChild(style);

  // Photo that can not be loaded (removed meanwhile, refused): placeholder image, also for the
  // errors that happened before this script ran
  function placeholder(img) {
    if (/photo\.php/.test(img.src)) {
      img.src = 'assets/photo-missing.svg';
    }
  }
  document.addEventListener('error', function (event) {
    if (event.target && event.target.tagName === 'IMG') {
      placeholder(event.target);
    }
  }, true);
  Array.prototype.forEach.call(document.images, function (img) {
    if (img.complete && img.naturalWidth === 0) {
      placeholder(img);
    }
  });

  // Labels come from the observations: text, never HTML
  function textNode(text) {
    var span = document.createElement('span');
    span.textContent = text;
    return span;
  }

  /*
   * Markers made smaller when they overlap (many places close together at the current zoom):
   * the median distance to the nearest marker on screen is compared with their size.
   * items: [{marker: L.CircleMarker, r: radius wanted}]; called again after each zoom.
   */
  function scaleMarkers(m, items) {
    var scale = 1;
    if (items.length > 1) {
      var pts = items.map(function (it) { return m.latLngToContainerPoint(it.marker.getLatLng()); });
      var step = Math.max(1, Math.floor(items.length / 1500));
      var nearest = [];
      for (var i = 0; i < pts.length; i += step) {
        var best = Infinity;
        for (var j = 0; j < pts.length; j++) {
          if (j !== i) {
            var dx = pts[i].x - pts[j].x, dy = pts[i].y - pts[j].y;
            best = Math.min(best, dx * dx + dy * dy);
          }
        }
        nearest.push(Math.sqrt(best));
      }
      var radii = items.map(function (it) { return it.r; });
      var median = function (list) { list.sort(function (a, b) { return a - b; }); return list[Math.floor(list.length / 2)]; };
      scale = Math.max(0.3, Math.min(1, median(nearest) / (2 * median(radii)) * 1.2));
    }
    items.forEach(function (it) {
      it.marker.setRadius(Math.max(2.5, it.r * scale));
      it.marker.setStyle({ weight: scale < 0.6 ? 0.5 : 1 });
    });
  }

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
    var markers = [];
    groups.forEach(function (g) {
      var r = Math.min(26, 5 + Math.sqrt(g.count) * 4);
      var marker = L.circleMarker([g.lat, g.lon], {
        radius: r, color: '#1f2328', weight: 1, fillColor: g.color, fillOpacity: 0.75
      }).bindTooltip(textNode(g.label)).addTo(map);
      markers.push({ marker: marker, r: r });
      bounds.push([g.lat, g.lon]);
    });
    fitMap = fit;
    fit();
    // after the first view: Leaflet sends zoomend before the map is ready
    scaleMarkers(map, markers);
    map.on('zoomend', function () { scaleMarkers(map, markers); });
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

  // Maps of the problems (detail of each problem): zone and observations of the report
  var problemMaps = [];
  document.querySelectorAll('.report-problem-map').forEach(function (el) {
    var data;
    try { data = JSON.parse(el.getAttribute('data-problem') || '{}'); } catch (e) { return; }
    if (!window.L) {
      return;
    }
    var m = L.map(el, { zoomControl: false, attributionControl: true });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      referrerPolicy: 'strict-origin-when-cross-origin'
    }).addTo(m);
    var style = { color: '#3f6fb5', weight: 2, fillOpacity: 0.12 };
    var b = null;
    if (data.zone === 'polygon' && data.polygon && data.polygon.length) {
      b = L.polygon(data.polygon, style).addTo(m).getBounds();
    } else if (data.zone !== 'streets' && data.radius) {
      L.circle([data.lat, data.lon], L.extend({ radius: data.radius }, style)).addTo(m);
      b = L.latLng(data.lat, data.lon).toBounds(data.radius * 2);
    }
    var points = [];
    (data.points || []).forEach(function (p) {
      var marker = L.circleMarker([p.lat, p.lon], { radius: 5, color: '#1f2328', weight: 1, fillColor: '#f2c200', fillOpacity: 0.9 })
        .bindTooltip(textNode(p.label)).addTo(m);
      points.push({ marker: marker, r: 5 });
      b = b ? b.extend([p.lat, p.lon]) : L.latLngBounds([p.lat, p.lon], [p.lat, p.lon]);
    });

    var fitThis = function () {
      if (b && b.isValid()) {
        m.fitBounds(b.pad(0.15), { maxZoom: 17 });
      } else {
        m.setView([46.6, 2.4], 5);
      }
    };
    fitThis();
    scaleMarkers(m, points);
    m.on('zoomend', function () { scaleMarkers(m, points); });
    // Zone of streets: the streets highlighted (OpenStreetMap, js/street-lines.js)
    if (data.zone === 'streets' && data.streets && data.streets.length && window.vigiloStreetLines) {
      window.vigiloStreetLines(m, data.streets, { bbox: b, style: { color: '#3f6fb5' } }).then(function (group) {
        if (!group.getLayers().length) {
          return;
        }
        group.addTo(m).bringToBack();
        var gb = group.getBounds();
        b = b ? b.extend(gb) : L.latLngBounds(gb.getSouthWest(), gb.getNorthEast());
        fitThis();
      });
    }
    problemMaps.push({ map: m, fit: fitThis, el: el });
  });

  function refreshMap() {
    if (map) {
      map.invalidateSize();
    }
    problemMaps.forEach(function (p) { p.map.invalidateSize(); p.fit(); });
  }
  // Printing: the map gets its printed size (see report.css) and is centered for it, then back
  var printing = false;
  // Printed width of the maps of the problems (document): the page is narrower than the screen
  function printSize(p, on) {
    if (document.body.classList.contains('report-document')) {
      p.el.style.width = on ? (p.el.closest('.two-cols') ? '88mm' : '182mm') : '';
    }
    p.map.invalidateSize();
    p.fit();
  }
  window.addEventListener('beforeprint', function () {
    problemMaps.forEach(function (p) { printSize(p, true); });
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
    problemMaps.forEach(function (p) { printSize(p, false); });
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
