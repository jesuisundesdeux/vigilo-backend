// Vigilo admin: finds the French communes whose center is in the territory of a scope
// (geo.api.gouv.fr), shows them on a map and in a list, imports the checked ones.
(function () {
  'use strict';

  var form = document.querySelector('[data-cities-import]');
  if (!form) {
    return;
  }
  var data = JSON.parse(document.getElementById('cities_import_data').textContent);
  var API = 'https://geo.api.gouv.fr';
  var status = form.querySelector('[data-import-status]');
  var results = form.querySelector('[data-import-results]');
  var rows = form.querySelector('[data-import-rows]');
  var map = null, layer = null;
  var found = [];

  function say(text) { status.textContent = text; }

  function getJson(url) {
    return fetch(url).then(function (r) {
      if (!r.ok) {
        throw new Error('HTTP ' + r.status);
      }
      return r.json();
    });
  }

  function scopeById(id) {
    for (var i = 0; i < data.scopes.length; i++) {
      if (String(data.scopes[i].id) === String(id)) {
        return data.scopes[i];
      }
    }
    return null;
  }

  // Departments covered by the rectangle: the commune under each point of a grid
  function departments(b) {
    var points = [], steps = 6;
    for (var i = 0; i <= steps; i++) {
      for (var j = 0; j <= steps; j++) {
        points.push([b[0] + (b[2] - b[0]) * i / steps, b[1] + (b[3] - b[1]) * j / steps]);
      }
    }
    return Promise.all(points.map(function (p) {
      return getJson(API + '/communes?lat=' + p[0] + '&lon=' + p[1] + '&fields=codeDepartement&format=json')
        .catch(function () { return []; });
    })).then(function (lists) {
      var codes = {};
      lists.forEach(function (list) {
        list.forEach(function (c) { if (c.codeDepartement) { codes[c.codeDepartement] = true; } });
      });
      return Object.keys(codes);
    });
  }

  function render(scope) {
    var existing = {};
    (data.cities[scope.id] || []).forEach(function (name) { existing[name.trim().toLowerCase()] = true; });
    rows.innerHTML = '';
    found.forEach(function (c, index) {
      var present = existing[c.name.toLowerCase()] === true;
      var tr = document.createElement('tr');
      var cells = [c.name + (present ? ' (déjà présente)' : ''), c.postcode, c.area.toLocaleString('fr-FR'), c.population.toLocaleString('fr-FR')];
      var td = document.createElement('td');
      var box = document.createElement('input');
      box.type = 'checkbox';
      box.className = 'form-check-input';
      box.checked = !present;
      box.disabled = present;
      box.setAttribute('data-index', index);
      box.setAttribute('aria-label', c.name);
      td.appendChild(box);
      tr.appendChild(td);
      cells.forEach(function (text) {
        var cell = document.createElement('td');
        cell.textContent = text;
        tr.appendChild(cell);
      });
      if (present) {
        tr.className = 'text-body-secondary';
      }
      rows.appendChild(tr);
    });

    results.hidden = false;
    var bounds = L.latLngBounds([scope.bounds[0], scope.bounds[1]], [scope.bounds[2], scope.bounds[3]]);
    if (!map) {
      map = L.map(form.querySelector('[data-import-map]'));
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        // The admin sends no Referer (Referrer-Policy: same-origin): the OSM tile servers block such requests
        referrerPolicy: 'strict-origin-when-cross-origin'
      }).addTo(map);
    }
    if (layer) {
      map.removeLayer(layer);
    }
    layer = L.layerGroup().addTo(map);
    L.rectangle(bounds, { color: '#0d6efd', weight: 2, fillOpacity: 0.05 }).addTo(layer);
    found.forEach(function (c) {
      L.circleMarker([c.lat, c.lon], { radius: 5, color: '#198754', fillOpacity: 0.8 }).bindTooltip(c.name).addTo(layer);
    });
    map.invalidateSize();
    map.fitBounds(bounds.pad(0.05));
  }

  form.querySelector('[data-import-action="search"]').addEventListener('click', function () {
    var scope = scopeById(form.elements['import_scope'].value);
    if (!scope) {
      say('Aucun scope : créer d\'abord un scope.');
      return;
    }
    var b = scope.bounds; // [lat min, lon min, lat max, lon max]
    if (!(b[2] > b[0] && b[3] > b[1])) {
      say('Le territoire de ce scope n\'est pas défini : le tracer sur la page Scopes.');
      return;
    }
    say('Recherche des départements du territoire…');
    results.hidden = true;
    departments(b).then(function (codes) {
      if (!codes.length) {
        throw new Error('aucune commune française dans ce territoire');
      }
      say('Recherche des communes (départements ' + codes.join(', ') + ')…');
      return Promise.all(codes.map(function (code) {
        return getJson(API + '/departements/' + encodeURIComponent(code) + '/communes?fields=nom,code,codesPostaux,surface,population,centre&format=json');
      }));
    }).then(function (lists) {
      found = [];
      lists.forEach(function (list) {
        list.forEach(function (c) {
          if (!c.centre || !c.centre.coordinates) {
            return;
          }
          var lon = c.centre.coordinates[0], lat = c.centre.coordinates[1];
          if (lat >= b[0] && lat <= b[2] && lon >= b[1] && lon <= b[3]) {
            found.push({
              name: c.nom,
              postcode: (c.codesPostaux && c.codesPostaux.length) ? c.codesPostaux[0] : '',
              area: c.surface ? Math.round(c.surface) / 100 : 0, // hectares -> km²
              population: c.population || 0,
              lat: lat, lon: lon
            });
          }
        });
      });
      found.sort(function (a, b2) { return a.name.localeCompare(b2.name, 'fr'); });
      say(found.length + ' commune' + (found.length > 1 ? 's' : '') + ' dans le territoire de ' + scope.display + '.');
      render(scope);
    }).catch(function (e) {
      say('Recherche impossible : ' + e.message + '. Seules les communes françaises sont proposées.');
    });
  });

  form.querySelector('[data-import-action="all"]').addEventListener('click', function () {
    rows.querySelectorAll('input[type=checkbox]:not(:disabled)').forEach(function (box) { box.checked = true; });
  });
  form.querySelector('[data-import-action="none"]').addEventListener('click', function () {
    rows.querySelectorAll('input[type=checkbox]').forEach(function (box) { box.checked = false; });
  });

  form.addEventListener('submit', function (event) {
    var selected = [];
    rows.querySelectorAll('input[type=checkbox]:checked').forEach(function (box) {
      var c = found[parseInt(box.getAttribute('data-index'), 10)];
      selected.push({ name: c.name, postcode: c.postcode, area: c.area, population: c.population });
    });
    if (!selected.length) {
      event.preventDefault();
      say('Aucune commune cochée.');
      return;
    }
    form.elements['cities_json'].value = JSON.stringify(selected);
  });

  // Coming from the Scopes page: search right away
  if (window.location.hash === '#import' && /[?&]import_scope=/.test(window.location.search)) {
    form.querySelector('[data-import-action="search"]').click();
  }
})();
