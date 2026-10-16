// Vigilo admin: streets of a problem (zone "Rues") drawn on a Leaflet map, from
// OpenStreetMap (Overpass API). Names compared without case, accents, house number or
// punctuation, as problem_street_key() (PHP). Used by problem-map.js and report.js.
// window.vigiloStreetLines(map, streets, options) -> Promise of an L.featureGroup (empty
// when nothing is found or Overpass can not be reached).
//   streets: [{street, city}]; options: {bbox: L.LatLngBounds, style, onEach(layer, name)}
// Without bbox, a street is looked for in the area of its city.
// Answers are kept in the browser (localStorage, 7 days, 1 day when nothing is found) to spare
// Overpass: the same streets are asked again by every map and report.
(function () {
  'use strict';

  if (!window.L) {
    return;
  }

  var ENDPOINT = 'https://overpass-api.de/api/interpreter';
  var cache = {};
  var STORE = 'vigilo-overpass:';
  var TTL = 7 * 86400000;
  var TTL_EMPTY = 86400000;
  var MAX_STORED = 60;

  // Short key of a query (FNV-1a), for localStorage
  function hash(text) {
    var h = 0x811c9dc5;
    for (var k = 0; k < text.length; k++) {
      h ^= text.charCodeAt(k);
      h = Math.imul(h, 0x01000193) >>> 0;
    }
    return h.toString(36) + text.length.toString(36);
  }

  // Stored answer {t: time, w: [[name, [[lat, lon], ...]], ...]}, null if absent, expired or unreadable
  function stored(q) {
    try {
      var item = JSON.parse(window.localStorage.getItem(STORE + hash(q)) || 'null');
      if (item && Array.isArray(item.w) && Date.now() - item.t < (item.w.length ? TTL : TTL_EMPTY)) {
        return item.w;
      }
    } catch (e) {
      // storage disabled or full: no cache
    }
    return null;
  }

  // Keeps an answer; the oldest ones are removed beyond MAX_STORED or when the storage is full
  function store(q, ways) {
    try {
      var keys = [];
      for (var k = 0; k < window.localStorage.length; k++) {
        var key = window.localStorage.key(k);
        if (key && key.indexOf(STORE) === 0) {
          var item = null;
          try { item = JSON.parse(window.localStorage.getItem(key)); } catch (e) { item = null; }
          keys.push([key, item && item.t ? item.t : 0]);
        }
      }
      keys.sort(function (a, b) { return a[1] - b[1]; });
      while (keys.length >= MAX_STORED) {
        window.localStorage.removeItem(keys.shift()[0]);
      }
      var value = JSON.stringify({ t: Date.now(), w: ways });
      for (;;) {
        try {
          window.localStorage.setItem(STORE + hash(q), value);
          return;
        } catch (e) {
          if (!keys.length) {
            return;
          }
          window.localStorage.removeItem(keys.shift()[0]);
        }
      }
    } catch (e) {
      // storage disabled: no cache
    }
  }

  // Ways of an Overpass answer: [[name, [[lat, lon], ...]], ...], rounded to about 1 m
  function ways(data) {
    var list = [];
    (data.elements || []).forEach(function (el) {
      if (el.type === 'way' && el.geometry) {
        list.push([el.tags && el.tags.name ? String(el.tags.name) : '', el.geometry.map(function (p) {
          return [Math.round(p.lat * 1e5) / 1e5, Math.round(p.lon * 1e5) / 1e5];
        })]);
      }
    });
    return list;
  }
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
    var known = cache[q] ? null : stored(q);
    var request = cache[q] || (cache[q] = known ? Promise.resolve(known) : fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'data=' + encodeURIComponent(q)
    }).then(function (r) {
      // Failures (429 too many requests, 504 timeout...) are never kept
      if (!r.ok) {
        throw new Error('HTTP ' + r.status);
      }
      return r.json();
    }).then(function (data) {
      var list = ways(data);
      store(q, list);
      return list;
    }));
    return request.then(function (list) {
      list.forEach(function (way) {
        var line = L.polyline(way[1], style);
        // Name of OpenStreetMap: text, never HTML
        var label = document.createElement('span');
        label.textContent = way[0];
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
