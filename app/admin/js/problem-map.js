// Vigilo admin: zone of a problem (circle, polygon or streets), drawn on a map. Keeps the
// form fields in sync (center and radius, JSON list of [lat, lon] points, or streets),
// with the observations of reference. Also the map of the detail of a problem (data-problem-view)
// and the map tab of the list (data-problems-map).
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

  // Same comparison as problem_street_key() (PHP): lowercase, no accents, house number or punctuation
  function streetKey(name) {
    return String(name || '').replace(/^\s*\d+\s*(bis|ter)?\b\s*,?\s*/i, '').toLowerCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/ß/g, 'ss')
      .replace(/[^a-z0-9]+/g, '');
  }

  // "12 Rue X, Ville" -> street "Rue X", city "Ville" (as problem_address_parts())
  function addressParts(address) {
    var parts = String(address || '').split(',');
    var city = parts.length > 1 ? parts.pop().trim() : '';
    parts = parts.map(function (p) { return p.trim(); }).filter(function (p) { return p !== '' && !/^\d+\s*(bis|ter)?$/i.test(p); });
    var street = parts.join(', ').replace(/^\s*\d+\s*(bis|ter)?\b\s*,?\s*/i, '').trim();
    return { street: street, city: city };
  }

  function streetText(text) {
    var value = String(text || '').trim(), cut = value.lastIndexOf(',');
    return cut < 0 ? { street: value, city: '' } : { street: value.slice(0, cut).trim(), city: value.slice(cut + 1).trim() };
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

    // Observations of reference (of the suggestion, or attached to the problem)
    var references = [];
    try {
      references = JSON.parse(container.getAttribute('data-points') || '[]');
    } catch (e) {
      references = [];
    }
    var referenceLayer = L.featureGroup().addTo(map);

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

    // Shape and field of the polygon (without the handles, kept while one is dragged)
    function drawShape() {
      if (polygon) { map.removeLayer(polygon); polygon = null; }
      if (points.length >= 2) {
        polygon = (points.length >= 3 ? L.polygon(points, STYLE) : L.polyline(points, STYLE)).addTo(map);
        polygon.bringToBack();
      }
      f.polygon.value = points.length ? JSON.stringify(points.map(function (p) { return [Number(fixed(p.lat)), Number(fixed(p.lng))]; })) : '';
      say(points.length ? points.length + ' point' + (points.length > 1 ? 's' : '') + (points.length < 3 ? ' (3 au moins)' : '') : '');
    }

    var VERTEX = L.divIcon({ className: 'problem-vertex', iconSize: [14, 14] });
    var MIDPOINT = L.divIcon({ className: 'problem-midpoint', iconSize: [10, 10] });

    // Polygon with its handles: a vertex is dragged to move it, removed by a right click
    // (long press); a midpoint is clicked or dragged to insert a vertex
    function drawPolygon() {
      vertices.clearLayers();
      points.forEach(function (p, i) {
        var handle = L.marker(p, { icon: VERTEX, draggable: true, title: 'Point ' + (i + 1) + ' (déplaçable, clic droit pour le retirer)', keyboard: false });
        handle.on('drag', function () { points[i] = handle.getLatLng(); drawShape(); });
        handle.on('dragend', drawPolygon);
        handle.on('contextmenu', function (e) {
          if (e.originalEvent) { L.DomEvent.preventDefault(e.originalEvent); }
          points.splice(i, 1);
          drawPolygon();
        });
        handle.addTo(vertices);
      });
      var edges = points.length >= 3 ? points.length : points.length - 1;
      for (var i = 0; i < edges; i++) {
        (function (i) {
          var a = points[i], b = points[(i + 1) % points.length];
          var middle = L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2);
          var handle = L.marker(middle, { icon: MIDPOINT, draggable: true, title: 'Insérer un point', keyboard: false });
          var inserted = false;
          function insert() {
            if (!inserted) { points.splice(i + 1, 0, handle.getLatLng()); inserted = true; }
          }
          handle.on('dragstart', insert);
          handle.on('drag', function () { points[i + 1] = handle.getLatLng(); drawShape(); });
          handle.on('dragend', drawPolygon);
          handle.on('click', function () { insert(); drawPolygon(); });
          handle.addTo(vertices);
        })(i);
      }
      drawShape();
      return polygon;
    }

    // Streets: one field per street, with suggestions of the streets of the observations
    var streetList = block.querySelector('[data-street-list]');
    function streetInputs() {
      return Array.prototype.slice.call(streetList.querySelectorAll('input[name="problem_streets[]"]'));
    }
    function streets() {
      return streetInputs().map(function (input) { return streetText(input.value); }).filter(function (s) { return streetKey(s.street) !== ''; });
    }
    function inStreets(address) {
      var parts = addressParts(address), key = streetKey(parts.street);
      return key !== '' && streets().some(function (s) {
        return streetKey(s.street) === key && (s.city === '' || streetKey(s.city) === streetKey(parts.city));
      });
    }
    function addStreetRow(value) {
      var rows = streetList.querySelectorAll('[data-street-row]');
      var row = rows[rows.length - 1].cloneNode(true);
      var input = row.querySelector('input');
      input.value = value || '';
      input.id = 'problem_street_' + Date.now();
      streetList.appendChild(row);
      return input;
    }
    function addStreet(value) {
      var empty = streetInputs().filter(function (input) { return input.value.trim() === ''; })[0];
      if (empty) {
        empty.value = value;
      } else {
        addStreetRow(value);
      }
      refreshStreets();
    }
    block.querySelector('[data-street-add]').addEventListener('click', function () {
      addStreetRow('').focus();
    });
    streetList.addEventListener('click', function (e) {
      var button = e.target.closest('[data-street-remove]');
      if (!button) { return; }
      var row = button.closest('[data-street-row]');
      if (streetList.querySelectorAll('[data-street-row]').length > 1) {
        row.remove();
      } else {
        row.querySelector('input').value = '';
      }
      refreshStreets();
    });
    streetList.addEventListener('input', function () { refreshStreets(); });

    // Observations of reference; in the zone "Rues", those of the chosen streets are highlighted
    function drawReferences() {
      referenceLayer.clearLayers();
      var streetsZone = zone() === 'streets', matching = 0;
      references.forEach(function (p) {
        var match = streetsZone && inStreets(p.address);
        if (match) { matching++; }
        var color = streetsZone ? (match ? '#198754' : '#6c757d') : (p.kind === 'attached' ? '#198754' : '#fd7e14');
        var dot = L.circleMarker([p.lat, p.lon], { radius: 6, color: '#fff', weight: 1, fillColor: color, fillOpacity: 0.9 }).addTo(referenceLayer);
        // Token and address as text (not HTML)
        var label = document.createElement('span');
        label.textContent = p.token + (p.address ? ' – ' + p.address : '');
        dot.bindTooltip(label);
        dot.on('click', function (e) {
          if (zone() !== 'streets') { return; }
          L.DomEvent.stopPropagation(e);
          var parts = addressParts(p.address);
          if (parts.street !== '' && !inStreets(p.address)) {
            addStreet(parts.street + (parts.city ? ', ' + parts.city : ''));
          }
        });
      });
      if (streetsZone) {
        var count = streets().length;
        say(count + ' rue' + (count > 1 ? 's' : '') + (references.length ? ' · ' + matching + ' observation' + (matching > 1 ? 's' : '') + ' sur ' + references.length + ' dans ces rues' : ''));
      }
    }
    // Streets of the zone drawn from OpenStreetMap (street-lines.js), after typing stops
    var streetLines = null, streetTimer = null, streetRequest = 0;
    function drawStreetLines() {
      clearTimeout(streetTimer);
      if (streetLines) { map.removeLayer(streetLines); streetLines = null; }
      if (zone() !== 'streets' || !window.vigiloStreetLines || !streets().length) {
        return;
      }
      streetTimer = setTimeout(function () {
        var request = ++streetRequest;
        // Area: around the observations of reference, else the view if zoomed in, else the city of each street
        var bbox = references.length ? referenceLayer.getBounds() : (map.getZoom() >= 12 ? map.getBounds() : null);
        window.vigiloStreetLines(map, streets(), { bbox: bbox }).then(function (group) {
          if (request !== streetRequest || zone() !== 'streets') {
            return;
          }
          streetLines = group.addTo(map);
          streetLines.bringToBack();
          if (!group.getLayers().length) {
            status.textContent += ' · rues non trouvées sur OpenStreetMap';
          } else if (!references.length) {
            map.fitBounds(group.getBounds().pad(0.15), { maxZoom: 17 });
          }
        });
      }, 700);
    }
    function refreshStreets() {
      if (zone() === 'streets') { drawReferences(); drawStreetLines(); }
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
      var shape = current === 'circle' ? drawCircle() : (current === 'polygon' ? drawPolygon() : null);
      if (current === 'circle') { say(''); }
      drawReferences();
      drawStreetLines();
      if (!fit) {
        return;
      }
      var bounds = shape && shape.getBounds && shape.getBounds().isValid() ? L.latLngBounds(shape.getBounds()) : null;
      if (current === 'circle' && circle) {
        bounds = L.latLng(circle.getLatLng()).toBounds(circle.getRadius() * 2);
      }
      if (references.length) {
        var all = referenceLayer.getBounds();
        bounds = bounds ? bounds.extend(all) : all;
      }
      if (bounds && bounds.isValid()) {
        map.fitBounds(bounds.pad(0.3), { maxZoom: 18 });
      }
    }

    map.on('click', function (e) {
      if (zone() === 'circle') {
        f.lat.value = fixed(e.latlng.lat);
        f.lon.value = fixed(e.latlng.lng);
        drawCircle();
      } else if (zone() === 'polygon') {
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
    var bounds = null;
    if (data.zone === 'polygon') {
      bounds = L.polygon(data.polygon, STYLE).addTo(map).getBounds();
    } else if (data.zone !== 'streets') {
      // A circle has bounds only once the map has a view
      bounds = L.latLng(data.lat, data.lon).toBounds(data.radius * 2);
      map.fitBounds(bounds);
      L.circle([data.lat, data.lon], L.extend({ radius: data.radius }, STYLE)).addTo(map);
    }
    data.points.forEach(function (p) {
      var marker = L.circleMarker([p.lat, p.lon], { radius: 6, color: '#fff', weight: 1, fillColor: COLORS[p.kind] || '#6c757d', fillOpacity: 0.9 }).addTo(map);
      // Token as text (not HTML)
      var label = document.createElement('span');
      label.textContent = p.token;
      marker.bindTooltip(label);
      bounds = bounds ? bounds.extend([p.lat, p.lon]) : L.latLngBounds([p.lat, p.lon], [p.lat, p.lon]);
    });
    if (bounds) {
      map.fitBounds(bounds.pad(0.15), { maxZoom: 18 });
    } else {
      // Zone of streets without observation: view of the scope
      var center = String(data.center || '46.6,2.4').split(',');
      map.setView([parseFloat(center[0]), parseFloat(center[1])], data.zoom || 13);
    }
    // Zone of streets: the streets highlighted (OpenStreetMap), around the observations or in their city
    if (data.zone === 'streets' && data.streets && data.streets.length && window.vigiloStreetLines) {
      window.vigiloStreetLines(map, data.streets, { bbox: bounds, style: { color: STYLE.color } }).then(function (group) {
        if (!group.getLayers().length) {
          return;
        }
        group.addTo(map).bringToBack();
        var all = L.latLngBounds(group.getBounds().getSouthWest(), group.getBounds().getNorthEast());
        if (bounds) { all.extend(bounds); }
        map.fitBounds(all.pad(0.1), { maxZoom: 18 });
      });
    }
    setTimeout(function () { map.invalidateSize(); }, 200);
  });

  // Map tab of the list: zones of the problems, colored by status, with a link to their detail
  document.querySelectorAll('[data-problems-map]').forEach(function (container) {
    var data;
    try {
      data = JSON.parse(container.getAttribute('data-problems-map'));
    } catch (e) {
      return;
    }
    var map = L.map(container);
    L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION, referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
    var bounds = null;
    function extend(b) {
      bounds = bounds ? bounds.extend(b) : L.latLngBounds(b.getSouthWest(), b.getNorthEast());
    }
    // Layers built first: the view is set before they are added (Leaflet fails on circles
    // added together to a map without a view)
    var items = data.problems.map(function (p) {
      // One color per problem; resolved or closed: dashed and lighter
      var style = { color: p.color, weight: 2, fillColor: p.color, fillOpacity: p.active ? 0.25 : 0.1, dashArray: p.active ? null : '6 4' };
      var layer;
      if (p.zone === 'polygon') {
        layer = L.polygon(p.polygon, style);
        extend(layer.getBounds());
      } else if (p.zone === 'streets') {
        // Observations: area of the streets, replaced by the streets once found
        layer = L.featureGroup(p.points.map(function (pt) {
          return L.circleMarker(pt, L.extend({ radius: 5 }, style, { fillOpacity: 0.8 }));
        }));
        if (p.points.length) {
          extend(layer.getBounds());
        }
      } else {
        layer = L.circle([p.lat, p.lon], L.extend({ radius: p.radius }, style));
        extend(L.latLng(p.lat, p.lon).toBounds(p.radius * 2));
      }
      // Popup built as DOM (titles are text, not HTML)
      var box = document.createElement('div');
      var link = document.createElement('a');
      link.href = '?page=problems&view=' + encodeURIComponent(p.id);
      link.className = 'fw-semibold';
      link.textContent = p.title;
      var info = document.createElement('div');
      info.className = 'small';
      info.textContent = p.status + ' · ' + p.priority + ' · ' + p.count + ' observation' + (p.count > 1 ? 's' : '');
      box.appendChild(link);
      box.appendChild(info);
      layer.bindPopup(box);
      var label = document.createElement('span');
      label.textContent = p.title;
      layer.bindTooltip(label, { sticky: true });
      return { p: p, layer: layer, box: box };
    });
    var fitted = bounds && bounds.isValid();
    if (fitted) {
      map.fitBounds(bounds.pad(0.1), { maxZoom: 17 });
    } else {
      var center = String(data.center || '46.6,2.4').split(',');
      map.setView([parseFloat(center[0]), parseFloat(center[1])], data.zoom || 13);
    }
    var pending = Promise.resolve();
    items.forEach(function (item) {
      var p = item.p, layer = item.layer;
      layer.addTo(map);
      if (p.zone !== 'streets' || !p.streets || !p.streets.length || !window.vigiloStreetLines) {
        return;
      }
      // Zone of streets: its streets highlighted (OpenStreetMap), one request after the other; they
      // replace the observations, kept when no street is found (Overpass unreachable, unknown name)
      pending = pending.then(function () {
        var area = p.points.length ? layer.getBounds() : null;
        return window.vigiloStreetLines(map, p.streets, { bbox: area, style: { color: p.color, weight: 5, dashArray: p.active ? null : '8 6' } }).then(function (group) {
          if (!group.getLayers().length) {
            return;
          }
          map.removeLayer(layer);
          group.eachLayer(function (line) { line.bindPopup(item.box); });
          group.addTo(map).bringToBack();
          // Problem without observation (streets looked for in their city): view extended if nothing else was shown
          if (!area && !fitted) {
            bounds = null;
            fitted = true;
            extend(group.getBounds());
            map.fitBounds(bounds.pad(0.1), { maxZoom: 17 });
          }
        });
      });
    });
    setTimeout(function () { map.invalidateSize(); }, 200);
  });
})();
