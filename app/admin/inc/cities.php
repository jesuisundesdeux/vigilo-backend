<?php
/*
Copyright (C) 2020 Velocité Montpellier

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'], $menu[$page_name]['access']))) {
    exit('Not allowed');
}


if (isset($_GET['action']) && !isset($_POST['city_id'])) {
    if ($_GET['action'] == 'add') {
        mysqli_query($db, "INSERT INTO obs_cities (city_scope,
                                              city_name,
                                              city_postcode,
                                              city_area,
                                              city_population,
                                              city_website)
                                     VALUES (0,
                                             'Ville',
                                             '00000',
                                             '0',
                                             '0',
                                             '')");
        echo '<div class="alert alert-success" role="alert">Ville ajoutée, merci de remplir les champs correspondants</div>';
    }
    if (isset($_GET['cityid']) && is_numeric($_GET['cityid'])) {
        if ($_GET['action'] == 'delete') {
            $cityid = mysqli_real_escape_string($db, $_GET['cityid']);
            mysqli_query($db, "DELETE FROM obs_cities WHERE city_id = '" . $cityid . "'");
            echo '<div class="alert alert-success" role="alert">Ville <strong>' . $cityid . '</strong> supprimée</div>';
        }
    }
}

if (isset($_POST['city_id'])) {
    $update = "";
    foreach ($_POST as $key => $value) {
        if (preg_match('/city_(?:.*)$/', $key)) {
            $key   = mysqli_real_escape_string($db, $key);
            $value = mysqli_real_escape_string($db, $value);
            $update .= $key . "='" . $value . "',";
        }
    }
    $update = rtrim($update, ',');
    $cityid = mysqli_real_escape_string($db, $_POST['city_id']);
    mysqli_query($db, "UPDATE obs_cities SET " . $update . " WHERE city_id='" . $cityid . "'");
    
    echo '<div class="alert alert-success" role="alert">Ville <strong>' . $cityid . '</strong> mise à jour</div>';
}

$query_cities = mysqli_query($db, "SELECT * FROM obs_cities");

?>

<h2>Liste</h2>
<p><a href="?page=<?= $page_name ?>&action=add">Ajouter une ville</a></p>

<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th># ID</th>
        <th>Nom</th>
        <th>Scope</th>
        <th>Code postal</th>
        <th>Surface (km2)</th>
        <th>Population</th>
        <th>Site</th>
        <th> </th>
        <th> </th>
      </tr>
    </thead>
    <tbody>
<?php

$scopelist    = array();
$query_scopes = mysqli_query($db, "SELECT * FROM obs_scopes");
while ($result_scopes = mysqli_fetch_array($query_scopes)) {
    $scopeid             = $result_scopes['scope_id'];
    $scopelist[$scopeid] = $result_scopes['scope_name'];
}

while ($result_cities = mysqli_fetch_array($query_cities)) {
?>

      <form action="" method="POST" name="<?= $result_cities['city_name']."form" ?>" >
      <tr>
        <td>#<?= $result_cities['city_id'] ?></td>
        <td>
          <input type="text" class="form-control-plaintext" name="city_name" value="<?= $result_cities['city_name'] ?>" required />
        </td>
        <td>
          <select name="city_scope" class="custom-select">
<?php
    foreach ($scopelist as $scopeid => $scopename) {
        if ($scopeid == $result_cities['city_scope']) {
            $selected = "selected";
        } else {
            $selected = "";
        }
        echo '<option value="' . $scopeid . '" ' . $selected . ' >' . $scopename . '</option>';
    }
    
?>
         </select>
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="city_postcode" value="<?= $result_cities['city_postcode'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="city_area" value="<?= $result_cities['city_area'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="city_population" value="<?= $result_cities['city_population'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="city_website" value="<?= $result_cities['city_website'] ?>" />
        </td>

        <td>
          <input type="hidden" name="city_id" value="<?= $result_cities['city_id'] ?>" />
          <button class="btn btn-primary" type="submit">Valider édition</button>
        </td>
        <td>
          <a href="?page=<?= $page_name ?>&action=delete&cityid=<?= $result_cities['city_id'] ?>" onclick="return confirm('Merci de valider la suppression')"><span data-feather="delete"></span> Supprimer</a>
          <br/>
          <a href="#" onclick="getWikidata( document.forms['<?= $result_cities['city_name']."form" ; ?>'] )" ><span data-feather="help-circle"></span> Wikidata</a>
        </td>
      </tr>
      </form>
<?php
}
?>
   </tbody>
  </table>
</div>
<br />

<script>
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

                if ( nb > 0 ){
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
</script>
