// Vigilo admin: zone of a problem (circle or polygon), drawn on a map. Keeps the
// form fields in sync (center and radius, or JSON list of [lat, lon] points). Also the
// map of the detail of a problem (data-problem-view).
(function () {
  'use strict';

  if (!window.L) {
    return;
  }

  // Tiles requested with the origin as Referer (see scope-map.js)
  var TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  var ATTRIBUTION = '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
  var STYLE = { color: '#0d6efd', weight: 2, fillOpacity: 0.12 };

  function fixed(value) {
    return (Math.round(value * 1e6) / 1e6).toString();
  }

  function num(input) {
    var value = parseFloat(String(input.value).replace(',', '.'));
    return isNaN(value) ? null : value;
  }

  function setup(container) {
    var block = container.closest('.problem-map-block');
    var form = container.closest('form');
    var f = {
      lat: form.querySelector('#problem_lat'), lon: form.querySelector('#problem_lon'),
      radius: form.querySelector('#problem_radius'), polygon: form.querySelector('#problem_polygon')
    };
    var status = block.querySelector('[data-map-status]');
    function say(text) { status.textContent = text; }
    function zone() {
      var checked = form.querySelector('input[name="problem_zone"]:checked');
      return checked ? checked.value : 'circle';
    }

    var center = String(container.getAttribute('data-center')).split(',');
    var map = L.map(container).setView([parseFloat(center[0]), parseFloat(center[1])], parseInt(container.getAttribute('data-zoom'), 10) || 13);
    L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION, referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);

    var marker = null, circle = null, polygon = null, vertices = L.layerGroup().addTo(map);
    var points = [];
    try {
      var stored = JSON.parse(f.polygon.value || '[]');
      if (Array.isArray(stored)) {
        points = stored.map(function (p) { return L.latLng(p[0], p[1]); });
      }
    } catch (e) {
      points = [];
    }

    function drawCircle() {
      var lat = num(f.lat), lon = num(f.lon), radius = num(f.radius);
      if (lat === null || lon === null) {
        if (marker) { map.removeLayer(marker); marker = null; }
        if (circle) { map.removeLayer(circle); circle = null; }
        return null;
      }
      var latlng = L.latLng(lat, lon);
      if (!marker) {
        marker = L.marker(latlng, { draggable: true, title: 'Centre du cercle (déplaçable)' }).addTo(map);
        marker.on('drag dragend', function () {
          var p = marker.getLatLng();
          f.lat.value = fixed(p.lat);
          f.lon.value = fixed(p.lng);
          drawCircle();
        });
      } else {
        marker.setLatLng(latlng);
      }
      if (!circle) {
        circle = L.circle(latlng, L.extend({ radius: radius || 0 }, STYLE)).addTo(map);
      } else {
        circle.setLatLng(latlng);
        circle.setRadius(radius || 0);
      }
      return circle;
    }

    function drawPolygon() {
      vertices.clearLayers();
      points.forEach(function (p) {
        L.circleMarker(p, { radius: 4, color: STYLE.color, weight: 2, fillOpacity: 1 }).addTo(vertices);
      });
      if (polygon) { map.removeLayer(polygon); polygon = null; }
      if (points.length >= 2) {
        polygon = (points.length >= 3 ? L.polygon(points, STYLE) : L.polyline(points, STYLE)).addTo(map);
      }
      f.polygon.value = points.length ? JSON.stringify(points.map(function (p) { return [Number(fixed(p.lat)), Number(fixed(p.lng))]; })) : '';
      say(points.length ? points.length + ' point' + (points.length > 1 ? 's' : '') + (points.length < 3 ? ' (3 au moins)' : '') : '');
      return polygon;
    }

    // Only the tools and the shape of the chosen zone are shown
    function showZone(fit) {
      var current = zone();
      block.querySelectorAll('[data-zone-tools], [data-zone-help]').forEach(function (el) {
        el.hidden = (el.getAttribute('data-zone-tools') || el.getAttribute('data-zone-help')) !== current;
      });
      [marker, circle].forEach(function (layer) {
        if (layer) { current === 'circle' ? layer.addTo(map) : map.removeLayer(layer); }
      });
      if (current === 'polygon') {
        vertices.addTo(map);
        if (polygon) { polygon.addTo(map); }
      } else {
        map.removeLayer(vertices);
        if (polygon) { map.removeLayer(polygon); }
      }
      var shape = current === 'circle' ? drawCircle() : drawPolygon();
      if (current === 'circle') { say(''); }
      if (fit && shape && shape.getBounds && shape.getBounds().isValid()) {
        map.fitBounds(shape.getBounds().pad(0.3));
      }
    }

    map.on('click', function (e) {
      if (zone() === 'circle') {
        f.lat.value = fixed(e.latlng.lat);
        f.lon.value = fixed(e.latlng.lng);
        drawCircle();
      } else {
        points.push(e.latlng);
        drawPolygon();
      }
    });
    block.querySelector('[data-map-action="undo"]').addEventListener('click', function () {
      points.pop();
      drawPolygon();
    });
    block.querySelector('[data-map-action="clear"]').addEventListener('click', function () {
      points = [];
      drawPolygon();
    });
    form.querySelectorAll('input[name="problem_zone"]').forEach(function (radio) {
      radio.addEventListener('change', function () { showZone(true); });
    });
    [f.lat, f.lon, f.radius].forEach(function (input) {
      input.addEventListener('input', drawCircle);
    });

    // Search a place (Nominatim) to move the map
    var search = block.querySelector('[data-map-search]');
    function runSearch() {
      var q = search.value.trim();
      if (q === '') {
        return;
      }
      say('Recherche…');
      fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&accept-language=fr&q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (results) {
          if (!results.length) {
            say('Aucun lieu trouvé pour « ' + q + ' ».');
            return;
          }
          var bb = results[0].boundingbox; // [south, north, west, east]
          map.fitBounds([[parseFloat(bb[0]), parseFloat(bb[2])], [parseFloat(bb[1]), parseFloat(bb[3])]]);
          say(results[0].display_name);
        })
        .catch(function () { say('Recherche impossible (réseau).'); });
    }
    block.querySelector('[data-map-action="search"]').addEventListener('click', runSearch);
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        runSearch();
      }
    });

    showZone(true);
    setTimeout(function () { map.invalidateSize(); }, 200);
  }

  document.querySelectorAll('[data-problem-map]').forEach(setup);

  // Detail of a problem: zone and observations (attached, to attach, in another problem)
  var COLORS = { attached: '#198754', to_attach: '#0d6efd', conflict: '#fd7e14' };
  document.querySelectorAll('[data-problem-view]').forEach(function (container) {
    var data;
    try {
      data = JSON.parse(container.getAttribute('data-problem-view'));
    } catch (e) {
      return;
    }
    var map = L.map(container);
    L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION, referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
    var zone = data.zone === 'polygon'
      ? L.polygon(data.polygon, STYLE)
      : L.circle([data.lat, data.lon], L.extend({ radius: data.radius }, STYLE));
    // A circle has bounds only once the map has a view
    var bounds = data.zone === 'polygon' ? zone.getBounds() : L.latLng(data.lat, data.lon).toBounds(data.radius * 2);
    map.fitBounds(bounds);
    zone.addTo(map);
    data.points.forEach(function (p) {
      var marker = L.circleMarker([p.lat, p.lon], { radius: 6, color: '#fff', weight: 1, fillColor: COLORS[p.kind] || '#6c757d', fillOpacity: 0.9 }).addTo(map);
      // Token as text (not HTML)
      var label = document.createElement('span');
      label.textContent = p.token;
      marker.bindTooltip(label);
      bounds.extend([p.lat, p.lon]);
    });
    map.fitBounds(bounds.pad(0.15));
    setTimeout(function () { map.invalidateSize(); }, 200);
  });
})();
