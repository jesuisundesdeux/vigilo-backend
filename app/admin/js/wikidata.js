// Vigilo admin: fills a city form with the data of Wikidata (no dependency)
(function () {
  'use strict';

  var ENDPOINT = 'https://query.wikidata.org/sparql';

  function sparqlQuery(query) {
    var url = ENDPOINT + '?format=json&query=' + encodeURIComponent(query);
    return fetch(url, { headers: { 'Accept': 'application/sparql-results+json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      });
  }

  // Escapes a value put inside a SPARQL string literal
  function sparqlString(value) {
    return String(value)
      .replace(/\\/g, '\\\\')
      .replace(/"/g, '\\"')
      .replace(/\r/g, '\\r')
      .replace(/\n/g, '\\n');
  }

  function value(binding, key) {
    return (binding && binding[key] && typeof binding[key].value !== 'undefined') ? String(binding[key].value) : null;
  }

  function setField(frm, name, val) {
    if (val !== null && frm.elements[name]) {
      frm.elements[name].value = val;
    }
  }

  /**
   * getWikidata
   *
   * Récupère les données d'une ville sur Wikidata et propose de remplir le formulaire.
   *
   * @param frm HTMLFormElement (ex. document.forms['city12form'])
   * @return bool true
   */
  function getWikidata(frm, button) {
    if (!frm || !frm.elements['city_name']) {
      return true;
    }
    var cityName = frm.elements['city_name'].value.trim();
    if (cityName === '') {
      window.alert('Merci de renseigner le nom de la ville.');
      return true;
    }

    var query = 'SELECT DISTINCT ?ville ?name ?villeLabel ?CP ?Population ?Area ?Sitewww WHERE {\n' +
      '  VALUES ?name { "' + sparqlString(cityName) + '"@fr }\n' +
      '  ?ville wdt:P31 wd:Q484170;\n' +
      '    rdfs:label ?name;\n' +
      '  OPTIONAL {?ville wdt:P281 ?CP.}\n' +
      '  OPTIONAL {?ville wdt:P856 ?Sitewww. }\n' +
      '  OPTIONAL {?ville wdt:P1082 ?Population. }\n' +
      '  OPTIONAL {?ville wdt:P2046 ?Area.}\n' +
      '  SERVICE wikibase:label { bd:serviceParam wikibase:language "fr". }\n' +
      '}\n' +
      'ORDER BY ?CP\n' +
      'LIMIT 10';

    if (button) {
      button.disabled = true;
    }

    sparqlQuery(query)
      .then(function (ret) {
        var bindings = (ret && ret.results && ret.results.bindings) ? ret.results.bindings : [];
        var nb = bindings.length;
        if (nb === 0) {
          window.alert('Pas de résultats :(');
          return;
        }
        var bien = (nb === 1) ? ' bien ' : ' ';
        for (var k = 0; k < nb; k++) {
          var b = bindings[k];
          var txt = 'Est-ce' + bien + '(proposition ' + (k + 1) + '/' + nb + ') :';
          if (value(b, 'name') !== null) { txt += '\nNom de la ville : ' + value(b, 'name'); }
          if (value(b, 'Area') !== null) { txt += '\nAire : ' + value(b, 'Area'); }
          if (value(b, 'Population') !== null) { txt += '\nPopulation : ' + value(b, 'Population'); }
          if (value(b, 'CP') !== null) { txt += '\nCode postal : ' + value(b, 'CP'); }
          if (value(b, 'Sitewww') !== null) { txt += '\nSite Internet : ' + value(b, 'Sitewww'); }

          if (window.confirm(txt)) {
            // Values are only put in the form: the server casts / escapes them on save
            setField(frm, 'city_postcode', value(b, 'CP'));
            setField(frm, 'city_area', value(b, 'Area'));
            setField(frm, 'city_population', value(b, 'Population'));
            setField(frm, 'city_website', value(b, 'Sitewww'));
            setField(frm, 'wikidata_import', '1');
            window.alert('Formulaire complété : vérifier les valeurs puis cliquer sur « Enregistrer ».');
            return;
          }
        }
      })
      .catch(function (error) {
        window.alert('Erreur lors de l\'interrogation de Wikidata : ' + error.message);
      })
      .then(function () {
        if (button) {
          button.disabled = false;
        }
      });
    return true;
  }

  window.getWikidata = getWikidata;

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-wikidata-form]');
    if (!button) {
      return;
    }
    event.preventDefault();
    getWikidata(document.forms[button.getAttribute('data-wikidata-form')], button);
  });
})();
