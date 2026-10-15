// Vigilo admin: streets of a problem (zone "Rues") drawn on a Leaflet map, from
// OpenStreetMap (Overpass API). Names compared without case, accents, house number or
// punctuation, as problem_street_key() (PHP). Used by problem-map.js and report.js.
// window.vigiloStreetLines(map, streets, options) -> Promise of an L.featureGroup (empty
// when nothing is found or Overpass can not be reached).
//   streets: [{street, city}]; options: {bbox: L.LatLngBounds, style, onEach(layer, name)}
// Without bbox, a street is looked for in the area of its city.
(function () {
  'use strict';

  if (!window.L) {
    return;
  }

  var ENDPOINT = 'https://overpass-api.de/api/interpreter';
  var cache = {};
  var LETTERS = { a: '[aàâäáã]', e: '[eéèêë]', i: '[iîïíì]', o: '[oôöóò]', u: '[uùûüú]', c: '[cç]', y: '[yÿý]', n: '[nñ]' };

  // Case-insensitive Overpass regex of a name: accents and punctuation tolerated; only
  // letters, digits, classes and "." (never a quote or a backslash in the query)
  function pattern(name) {
    var base = String(name || '').replace(/^\s*\d+\s*(bis|ter)?\b\s*,?\s*/i, '').normalize('NFD')
      .replace(/[̀-ͯ]/g, '').toLowerCase().replace(/œ/g, 'oe').replace(/æ/g, 'ae').trim();
    var out = '';
    var gap = false;
    for (var k = 0; k < base.length; k++) {
      var ch = base.charAt(k);
      if (/[a-z0-9]/.test(ch)) {
        if (gap && out !== '') {
          out += '.{0,3}';
        }
        gap = false;
        out += LETTERS[ch] || ch;
      } else {
        gap = true;
      }
    }
    return out;
  }

  // Area searched: the bounds, 20 % larger and at least about 1 km around (a single observation)
  function bboxText(bounds) {
    var b = bounds.pad(0.2);
    return [b.getSouth() - 0.01, b.getWest() - 0.015, b.getNorth() + 0.01, b.getEast() + 0.015].map(function (v) { return v.toFixed(5); }).join(',');
  }

  function query(streets, bbox) {
    var areas = [];
    var ways = [];
    streets.forEach(function (s, index) {
      var name = pattern(s.street);
      if (name === '') {
        return;
      }
      if (bbox) {
        ways.push('way["highway"]["name"~"^' + name + '$",i](' + bboxText(bbox) + ');');
      } else if (pattern(s.city) !== '') {
        areas.push('area["boundary"="administrative"]["name"~"^' + pattern(s.city) + '$",i]->.c' + index + ';');
        ways.push('way["highway"]["name"~"^' + name + '$",i](area.c' + index + ');');
      }
    });
    return ways.length ? '[out:json][timeout:25];' + areas.join('') + '(' + ways.join('') + ');out geom;' : '';
  }

  window.vigiloStreetLines = function (map, streets, options) {
    options = options || {};
    var style = L.extend({ color: '#0d6efd', weight: 6, opacity: 0.75 }, options.style || {});
    var group = L.featureGroup();
    var q = query(streets || [], options.bbox && options.bbox.isValid() ? options.bbox : null);
    if (q === '' || !window.fetch) {
      return Promise.resolve(group);
    }
    var request = cache[q] || (cache[q] = fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'data=' + encodeURIComponent(q)
    }).then(function (r) {
      if (!r.ok) {
        throw new Error('HTTP ' + r.status);
      }
      return r.json();
    }));
    return request.then(function (data) {
      (data.elements || []).forEach(function (el) {
        if (el.type !== 'way' || !el.geometry) {
          return;
        }
        var line = L.polyline(el.geometry.map(function (p) { return [p.lat, p.lon]; }), style);
        // Name of OpenStreetMap: text, never HTML
        var label = document.createElement('span');
        label.textContent = el.tags && el.tags.name ? el.tags.name : '';
        line.bindTooltip(label, { sticky: true });
        if (options.onEach) {
          options.onEach(line, label.textContent);
        }
        line.addTo(group);
      });
      return group;
    }).catch(function () {
      delete cache[q];
      return group;
    });
  };
})();
