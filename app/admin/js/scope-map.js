// Vigilo admin: map of a scope, to choose its territory (rectangle) and the
// center / zoom of the maps of the applications. Keeps the form fields in sync.
(function () {
  'use strict';

  if (!window.L) {
    return;
  }

  var TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  var ATTRIBUTION = '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

  function num(input) {
    var value = parseFloat(String(input.value).replace(',', '.'));
    return isNaN(value) ? null : value;
  }

  function fixed(value) {
    return (Math.round(value * 1e6) / 1e6).toString();
  }

  function setup(container) {
    var prefix = container.getAttribute('data-scope-map');
    function field(name) { return document.getElementById(prefix + name); }
    var f = {
      latMin: field('lat_min'), latMax: field('lat_max'), lonMin: field('lon_min'), lonMax: field('lon_max'),
      center: field('center'), zoom: field('zoom')
    };
    var card = container.closest('.scope-map-block');
    function button(action) { return card.querySelector('[data-map-action="' + action + '"]'); }
    var status = card.querySelector('[data-map-status]');

    var map = L.map(container, { zoomControl: true }).setView([46.6, 2.4], 5);
    L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION }).addTo(map);
    var rect = null;
    var centerMarker = null;

    function say(text) { status.textContent = text; }

    function boundsFromFields() {
      var s = num(f.latMin), n = num(f.latMax), w = num(f.lonMin), e = num(f.lonMax);
      if (s === null || n === null || w === null || e === null || (s === n && w === e)) {
        return null;
      }
      return L.latLngBounds([s, w], [n, e]);
    }

    function centerFromFields() {
      var parts = String(f.center.value).split(',');
      if (parts.length !== 2) {
        return null;
      }
      var lat = parseFloat(parts[0]), lon = parseFloat(parts[1]);
      return (isNaN(lat) || isNaN(lon) || (lat === 0 && lon === 0)) ? null : L.latLng(lat, lon);
    }

    function drawRect(bounds) {
      if (rect) {
        rect.setBounds(bounds);
      } else {
        rect = L.rectangle(bounds, { color: '#0d6efd', weight: 2, fillOpacity: 0.08 }).addTo(map);
      }
    }

    function setBounds(bounds) {
      var s = Math.min(bounds.getSouth(), bounds.getNorth()), n = Math.max(bounds.getSouth(), bounds.getNorth());
      var w = Math.min(bounds.getWest(), bounds.getEast()), e = Math.max(bounds.getWest(), bounds.getEast());
      f.latMin.value = fixed(s);
      f.latMax.value = fixed(n);
      f.lonMin.value = fixed(w);
      f.lonMax.value = fixed(e);
      drawRect(L.latLngBounds([s, w], [n, e]));
    }

    function placeCenter(latlng) {
      if (centerMarker) {
        centerMarker.setLatLng(latlng);
      } else {
        centerMarker = L.marker(latlng, { draggable: true, title: 'Centre des cartes (déplaçable)' }).addTo(map);
        centerMarker.on('dragend', function () {
          var p = centerMarker.getLatLng();
          f.center.value = fixed(p.lat) + ',' + fixed(p.lng);
        });
      }
    }

    function fromFields(fit) {
      var bounds = boundsFromFields();
      if (bounds) {
        drawRect(bounds);
      } else if (rect) {
        map.removeLayer(rect);
        rect = null;
      }
      var center = centerFromFields();
      if (center) {
        placeCenter(center);
      }
      if (fit) {
        if (bounds) {
          map.fitBounds(bounds.pad(0.1));
        } else if (center) {
          map.setView(center, parseInt(f.zoom.value, 10) || 13);
        }
      }
    }

    // Draw the territory: press, drag and release on the map
    var drawing = false, start = null;
    button('draw').addEventListener('click', function () {
      drawing = !drawing;
      button('draw').classList.toggle('active', drawing);
      container.style.cursor = drawing ? 'crosshair' : '';
      if (drawing) {
        map.dragging.disable();
        say('Cliquer-glisser sur la carte pour tracer le rectangle du territoire.');
      } else {
        map.dragging.enable();
        say('');
      }
    });
    map.on('mousedown', function (e) {
      if (drawing) {
        start = e.latlng;
        drawRect(L.latLngBounds(start, start));
      }
    });
    map.on('mousemove', function (e) {
      if (drawing && start) {
        drawRect(L.latLngBounds(start, e.latlng));
      }
    });
    map.on('mouseup', function (e) {
      if (drawing && start) {
        if (!start.equals(e.latlng)) {
          setBounds(L.latLngBounds(start, e.latlng));
          say('Territoire tracé : penser à enregistrer.');
        }
        start = null;
        drawing = false;
        button('draw').classList.remove('active');
        container.style.cursor = '';
        map.dragging.enable();
      }
    });

    button('view-bounds').addEventListener('click', function () {
      setBounds(map.getBounds());
      say('Territoire = vue actuelle de la carte : penser à enregistrer.');
    });

    button('view-center').addEventListener('click', function () {
      var c = map.getCenter();
      f.center.value = fixed(c.lat) + ',' + fixed(c.lng);
      var zoom = Math.max(1, Math.min(20, map.getZoom()));
      f.zoom.value = String(zoom);
      placeCenter(c);
      say('Centre et zoom (' + zoom + ') = vue actuelle de la carte : penser à enregistrer.');
    });

    // Search a place (Nominatim) to move the map
    var search = card.querySelector('[data-map-search]');
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
    card.querySelector('[data-map-action="search"]').addEventListener('click', runSearch);
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        runSearch();
      }
    });

    [f.latMin, f.latMax, f.lonMin, f.lonMax, f.center].forEach(function (input) {
      input.addEventListener('change', function () { fromFields(false); });
    });

    fromFields(true);
    // The map may be created in a hidden or resized block
    setTimeout(function () { map.invalidateSize(); }, 200);
  }

  document.querySelectorAll('[data-scope-map]').forEach(setup);

  // Identifier: department number (01-95, 2A, 2B, 971-976) or country code, "_", name
  // without space or special character; the department field follows the identifier
  document.querySelectorAll('[data-scope-name]').forEach(function (input) {
    var department = document.getElementById(input.getAttribute('data-scope-name'));
    input.addEventListener('input', function () {
      var m = /^([0-9]{2})_/.exec(input.value);
      if (m && department && (department.value === '' || department.value === '0')) {
        department.value = String(parseInt(m[1], 10));
      }
    });
  });
})();
