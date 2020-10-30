class SPARQLQueryDispatcher {
	constructor( endpoint ) {
		this.endpoint = endpoint;
	}

	query( sparqlQuery ) {
		const fullUrl = this.endpoint + '?query=' + encodeURIComponent( sparqlQuery );
		const headers = { 'Accept': 'application/sparql-results+json' };

		return fetch( fullUrl, { headers } ).then( body => body.json() );
	}
}
/**
 * getWikidata
 *
 * Récupère les données des villes sur Wikidata
 *
 * @param obj frm
 *      objet HTMLCollection identifiant un formulaire sur le modèle document.forms['ville_forme']
 * @return bool
 *	    true
**/
function getWikidata( frm ) {
    var city_name = frm.elements['city_name'].value ;
    const endpointUrl = 'https://query.wikidata.org/sparql';
    const sparqlQuery = `SELECT DISTINCT ?ville ?name ?villeLabel ?CP ?Population ?Area ?Sitewww WHERE {
          VALUES ?name { "` + city_name + `"@fr }
          ?ville wdt:P31 wd:Q484170;
            rdfs:label ?name;
            OPTIONAL {?ville wdt:P281 ?CP.}
            OPTIONAL {?ville wdt:P856 ?Sitewww. }
            OPTIONAL {?ville wdt:P1082 ?Population. }
            OPTIONAL {?ville wdt:P2046 ?Area.}
          SERVICE wikibase:label { bd:serviceParam wikibase:language "fr". }
        }
        LIMIT 10
        `;
    const queryDispatcher = new SPARQLQueryDispatcher( endpointUrl );
    queryDispatcher.query( sparqlQuery ).then( console.log );
    queryDispatcher.query( sparqlQuery )
              .then( (ret) => {
                var nb = ret.results.bindings.length ;
                if ( nb == 0 ) {
                  alert('Pas de résultats :( ') ;
                  return true ;
                }
                else {
                    var bien = ( nb == 1 ) ? " bien " : " " ;
                    var txt, ordre ;
                    // on fait une boucle pour proposer chaque résultat
                    for ( var k = 0 ; k < nb ; k++ ) {
                        txt = "" ;
                        ordre = k+1 ;
                        txt += "Est-ce"+bien+"(proposition "+ ordre +"/"+nb+") :" ;
                        if(typeof ret.results.bindings[k].name != 'undefined')
                            txt += "\nNom de la ville: " + ret.results.bindings[0].name.value ;
                        if(typeof ret.results.bindings[k].Area != 'undefined')
                            txt += "\nAire: " + ret.results.bindings[k].Area.value ;
                        if(typeof ret.results.bindings[k].Population != 'undefined')
                            txt += "\nPopulation: " + ret.results.bindings[k].Population.value ;
                        if(typeof ret.results.bindings[k].CP != 'undefined')
                            txt += "\nCode Postal: " + ret.results.bindings[k].CP.value ;
                        if(typeof ret.results.bindings[k].Sitewww != 'undefined')
                            txt += "\nSite Internet: " + ret.results.bindings[k].Sitewww.value ;

                        if ( confirm(txt) ) {
                            if(typeof ret.results.bindings[k].name != 'undefined')
                                frm.elements['city_postcode'].value = ret.results.bindings[k].CP.value ;
                            if(typeof ret.results.bindings[k].Area != 'undefined')
                                frm.elements['city_area'].value = ret.results.bindings[k].Area.value ;
                            if(typeof ret.results.bindings[k].Population != 'undefined')
                                frm.elements['city_population'].value = ret.results.bindings[k].Population.value ;
                            if(typeof ret.results.bindings[k].Sitewww != 'undefined')
                                frm.elements['city_website'].value = ret.results.bindings[k].Sitewww.value ;
                        }
                    }
                }
                return true ;
              } );
    return true ;
}