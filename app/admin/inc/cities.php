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

if (!isset($page_name) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $menu[$page_name]['access'])) {
    exit('Not allowed');
}

/* Casts a number typed by hand or imported from Wikidata ("34,3", "1 234", "+350000") */
if (!function_exists('city_number')) {
    function city_number($value)
    {
        $value = str_replace(array(' ', "\xC2\xA0", ','), array('', '', '.'), trim((string) $value));
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

$messages = array();
$reopen   = null;

$scopelist    = array();
$scopebounds  = array();
$query_scopes = mysqli_query($db, "SELECT scope_id, scope_name, scope_display_name, scope_coordinate_lat_min, scope_coordinate_lat_max,
                                          scope_coordinate_lon_min, scope_coordinate_lon_max FROM obs_scopes ORDER BY scope_id");
while ($query_scopes && ($result_scopes = mysqli_fetch_array($query_scopes))) {
    $scopelist[intval($result_scopes['scope_id'])] = (string) $result_scopes['scope_name'];
    $scopebounds[] = array(
        'id'      => intval($result_scopes['scope_id']),
        'name'    => (string) $result_scopes['scope_name'],
        'display' => (string) $result_scopes['scope_display_name'],
        'bounds'  => array_map('floatval', array($result_scopes['scope_coordinate_lat_min'], $result_scopes['scope_coordinate_lon_min'],
                                                 $result_scopes['scope_coordinate_lat_max'], $result_scopes['scope_coordinate_lon_max'])),
    );
}

/* Import of communes found in the territory of a scope (js/cities-import.js, geo.api.gouv.fr) */
if (isset($_POST['cities_import'])) {
    $scope    = isset($_POST['import_scope']) ? intval($_POST['import_scope']) : 0;
    $incoming = isset($_POST['cities_json']) && is_string($_POST['cities_json']) ? json_decode($_POST['cities_json'], true) : null;
    if (!isset($scopelist[$scope])) {
        $messages[] = array('danger', 'Scope inconnu : aucune ville importée.');
    } elseif (!is_array($incoming) || count($incoming) == 0 || count($incoming) > 2000) {
        $messages[] = array('warning', 'Aucune ville sélectionnée.');
    } else {
        $existing = array();
        $query    = mysqli_query($db, "SELECT city_name FROM obs_cities WHERE city_scope = " . $scope);
        while ($query && ($row = mysqli_fetch_array($query))) {
            $existing[mb_strtolower(trim($row['city_name']), 'UTF-8')] = true;
        }
        $added = array();
        $skipped = 0;
        foreach ($incoming as $city) {
            // Every value is checked and cast: it comes from the browser (geo.api.gouv.fr)
            if (!is_array($city) || !isset($city['name']) || !is_string($city['name'])) {
                $skipped++;
                continue;
            }
            $name = trim($city['name']);
            $key  = mb_strtolower($name, 'UTF-8');
            if ($name === '' || strlen($name) > 255 || isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $postcode   = isset($city['postcode']) ? intval(preg_replace('/[^0-9]/', '', (string) $city['postcode'])) : 0;
            $area       = isset($city['area']) && is_numeric($city['area']) ? max(0, (float) $city['area']) : 0;
            $population = isset($city['population']) && is_numeric($city['population']) ? max(0, intval($city['population'])) : 0;
            if ($postcode < 0 || $postcode > 99999) {
                $postcode = 0;
            }
            $ok = mysqli_query($db, "INSERT INTO obs_cities (city_scope, city_name, city_postcode, city_area, city_population, city_website)
                                     VALUES (" . $scope . ", '" . mysqli_real_escape_string($db, $name) . "', " . $postcode . ", '"
                                     . mysqli_real_escape_string($db, sprintf('%F', $area)) . "', " . $population . ", '')");
            if ($ok) {
                $existing[$key] = true;
                $added[] = $name;
            } else {
                $skipped++;
            }
        }
        if ($added) {
            audit_log('city_import', 'scope:' . $scope, array('source' => 'geo.api.gouv.fr', 'count' => count($added), 'cities' => implode(', ', array_slice($added, 0, 50))));
        }
        $messages[] = array($added ? 'success' : 'warning', count($added) . ' ville' . (count($added) > 1 ? 's' : '') . ' importée' . (count($added) > 1 ? 's' : '')
            . ' dans le scope <strong>' . h($scopelist[$scope]) . '</strong>' . ($skipped ? ' (' . $skipped . ' ignorée' . ($skipped > 1 ? 's' : '') . ' : déjà présentes ou invalides)' : '') . '.');
    }
}

if (isset($_GET['action']) && !isset($_POST['city_id'])) {
    if ($_GET['action'] == 'delete' && isset($_GET['cityid']) && is_numeric($_GET['cityid'])) {
        $cityid      = intval($_GET['cityid']);
        $query_check = mysqli_query($db, "SELECT city_name FROM obs_cities WHERE city_id = " . $cityid . " LIMIT 1");
        $check       = $query_check ? mysqli_fetch_array($query_check) : null;
        if (!$check) {
            $messages[] = array('warning', 'Ville <strong>#' . $cityid . '</strong> introuvable.');
        } elseif (mysqli_query($db, "DELETE FROM obs_cities WHERE city_id = " . $cityid)) {
            audit_log('city_delete', 'city:' . $cityid, array('city_name' => $check['city_name']));
            $messages[] = array('success', 'Ville <strong>#' . $cityid . '</strong> supprimée.');
        } else {
            $messages[] = array('danger', 'Impossible de supprimer la ville <strong>#' . $cityid . '</strong>.');
        }
    }
}

if (isset($_POST['city_id'])) {
    /*
     * Only the columns below can be changed, and every value is cast or escaped:
     * they may come from a Wikidata import (second-order injection, H4).
     */
    $cityid      = intval($_POST['city_id']);
    $creating    = ($cityid === 0);
    if ($creating) {
        // Creation window: the same checks, then an INSERT with every column
        $old = array('city_name' => '', 'city_scope' => 0, 'city_postcode' => 0, 'city_area' => 0, 'city_population' => 0, 'city_website' => '');
    } else {
        $query_check = mysqli_query($db, "SELECT * FROM obs_cities WHERE city_id = " . $cityid . " LIMIT 1");
        $old         = $query_check ? mysqli_fetch_array($query_check) : null;
    }
    $errors      = array();
    $values      = array();

    if ($creating && (!isset($_POST['city_name']) || !is_string($_POST['city_name']))) {
        $errors[] = 'Le nom de la ville est obligatoire.';
    } elseif (!$old) {
        $errors[] = 'Ville <strong>#' . $cityid . '</strong> introuvable.';
    } else {
        if (isset($_POST['city_name']) && is_string($_POST['city_name'])) {
            $name = trim($_POST['city_name']);
            if ($name === '') {
                $errors[] = 'Le nom de la ville est obligatoire.';
            } elseif (strlen($name) > 255) {
                $errors[] = 'Le nom de la ville est trop long.';
            } else {
                $values['city_name'] = $name;
            }
        }
        if (isset($_POST['city_scope']) && is_scalar($_POST['city_scope'])) {
            $scope = intval($_POST['city_scope']);
            if ($scope != 0 && !isset($scopelist[$scope])) {
                $errors[] = 'Scope inconnu.';
            } else {
                $values['city_scope'] = $scope;
            }
        }
        if (isset($_POST['city_postcode']) && is_scalar($_POST['city_postcode'])) {
            $postcode = intval(city_number(preg_replace('/[^0-9].*$/s', '', trim((string) $_POST['city_postcode']))));
            if ($postcode < 0 || $postcode > 99999) {
                $errors[] = 'Code postal invalide.';
            } else {
                $values['city_postcode'] = $postcode;
            }
        }
        if (isset($_POST['city_area']) && is_scalar($_POST['city_area'])) {
            $area = city_number($_POST['city_area']);
            if ($area < 0) {
                $errors[] = 'Surface invalide.';
            } else {
                $values['city_area'] = $area;
            }
        }
        if (isset($_POST['city_population']) && is_scalar($_POST['city_population'])) {
            $population = intval(city_number($_POST['city_population']));
            if ($population < 0) {
                $errors[] = 'Population invalide.';
            } else {
                $values['city_population'] = $population;
            }
        }
        if (isset($_POST['city_website']) && is_string($_POST['city_website'])) {
            $website = trim($_POST['city_website']);
            if (strlen($website) > 255) {
                $errors[] = 'L\'adresse du site est trop longue.';
            } else {
                $values['city_website'] = $website;
            }
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            $messages[] = array('danger', $error);
        }
        $messages[] = array('warning', $creating ? 'La ville n\'a pas été ajoutée.' : 'La ville <strong>#' . $cityid . '</strong> n\'a pas été modifiée.');
        // Reopens the window with what was typed
        $reopen = array('city_id' => $cityid);
        foreach (array('city_name', 'city_scope', 'city_postcode', 'city_area', 'city_population', 'city_website') as $field) {
            $reopen[$field] = isset($_POST[$field]) && is_scalar($_POST[$field]) ? (string) $_POST[$field] : '';
        }
    } elseif ($creating) {
        $columns = array();
        $inserts = array();
        foreach (array_merge($old, $values) as $column => $value) {
            // $column comes from the allowlist above, never from the POST keys
            $columns[] = $column;
            if (is_int($value)) {
                $inserts[] = $value;
            } elseif (is_float($value)) {
                $inserts[] = "'" . mysqli_real_escape_string($db, sprintf('%F', $value)) . "'";
            } else {
                $inserts[] = "'" . mysqli_real_escape_string($db, $value) . "'";
            }
        }
        if (mysqli_query($db, "INSERT INTO obs_cities (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $inserts) . ")")) {
            $new_id = intval(mysqli_insert_id($db));
            audit_log('city_create', 'city:' . $new_id, $values);
            $messages[] = array('success', 'Ville <strong>' . h($values['city_name']) . '</strong> (#' . $new_id . ') ajoutée.');
        } else {
            $messages[] = array('danger', 'Impossible d\'ajouter la ville.');
        }
    } else {
        $parts   = array();
        $changes = array();
        foreach ($values as $column => $value) {
            // $column comes from the allowlist above, never from the POST keys
            if (is_int($value)) {
                $parts[] = $column . " = " . $value;
            } elseif (is_float($value)) {
                $parts[] = $column . " = '" . mysqli_real_escape_string($db, sprintf('%F', $value)) . "'";
            } else {
                $parts[] = $column . " = '" . mysqli_real_escape_string($db, $value) . "'";
            }
            if ((string) $old[$column] != (string) $value) {
                $changes[$column] = $value;
            }
        }
        if (empty($changes)) {
            $messages[] = array('info', 'Aucune modification pour la ville <strong>#' . $cityid . '</strong>.');
        } elseif (mysqli_query($db, "UPDATE obs_cities SET " . implode(', ', $parts) . " WHERE city_id = " . $cityid)) {
            $imported = isset($_POST['wikidata_import']) && $_POST['wikidata_import'] === '1';
            audit_log($imported ? 'city_import' : 'city_edit', 'city:' . $cityid, $imported ? array('source' => 'wikidata', 'changes' => $changes) : $changes);
            $messages[] = array('success', 'Ville <strong>#' . $cityid . '</strong> mise à jour' . ($imported ? ' (données Wikidata)' : '') . '.');
        } else {
            $messages[] = array('danger', 'Impossible de mettre à jour la ville <strong>#' . $cityid . '</strong>.');
        }
    }
}

foreach ($messages as $message) {
    // Messages are built above from escaped / integer values only
    echo '<div class="alert alert-' . h($message[0]) . '" role="alert">' . $message[1] . '</div>';
}

$query_cities = mysqli_query($db, "SELECT * FROM obs_cities ORDER BY city_name");
$self_url     = '?page=' . urlencode($page_name);
$cities_by_scope = array();
$query_names = mysqli_query($db, "SELECT city_scope, city_name FROM obs_cities");
while ($query_names && ($row = mysqli_fetch_array($query_names))) {
    $cities_by_scope[intval($row['city_scope'])][] = (string) $row['city_name'];
}
$import_scope = isset($_GET['import_scope']) ? intval($_GET['import_scope']) : 0;
$city_empty   = array('city_id' => 0, 'city_name' => '', 'city_scope' => $scopebounds ? $scopebounds[0]['id'] : 0, 'city_postcode' => '',
                      'city_area' => '', 'city_population' => '', 'city_website' => '', 'wikidata_import' => '0');
?>
<div class="card shadow-sm mb-4" id="import">
  <div class="card-header fw-semibold"><i class="bi bi-cloud-download"></i> Importer les communes d'un territoire</div>
  <div class="card-body">
    <p class="small text-body-secondary">
      Recherche les communes françaises dont le centre est dans le territoire (rectangle) d'un scope, avec leur code postal,
      leur surface et leur population (<a href="https://geo.api.gouv.fr" target="_blank" rel="noopener noreferrer">geo.api.gouv.fr</a>),
      puis importe celles qui sont cochées. Le territoire se trace sur la page <a href="?page=scopes">Scopes</a>.
    </p>
    <form method="POST" action="<?= h($self_url) ?>#import" data-cities-import>
      <?= csrf_field() ?>
      <input type="hidden" name="cities_import" value="1" />
      <input type="hidden" name="cities_json" value="" />
      <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div>
          <label class="form-label small mb-1" for="import_scope">Scope</label>
          <select class="form-select form-select-sm" id="import_scope" name="import_scope">
            <?php foreach ($scopebounds as $scope_info) { ?>
              <option value="<?= intval($scope_info['id']) ?>"<?= $scope_info['id'] == $import_scope ? ' selected' : '' ?>><?= h($scope_info['display'] . ' (' . $scope_info['name'] . ')') ?></option>
            <?php } ?>
          </select>
        </div>
        <button class="btn btn-sm btn-primary" type="button" data-import-action="search"><i class="bi bi-search"></i> Rechercher les communes du territoire</button>
      </div>
      <div class="text-body-secondary small mb-2" data-import-status aria-live="polite"></div>
      <div class="row g-3" data-import-results hidden>
        <div class="col-12 col-xl-5">
          <div class="rounded border" data-import-map style="height: 360px;"></div>
        </div>
        <div class="col-12 col-xl-7">
          <div class="d-flex flex-wrap gap-2 mb-2">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-import-action="all">Tout cocher</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-import-action="none">Tout décocher</button>
            <button class="btn btn-sm btn-success ms-auto" type="submit"><i class="bi bi-download"></i> Importer la sélection</button>
          </div>
          <div class="table-responsive border rounded" style="max-height: 320px;">
            <table class="table table-sm table-hover align-middle mb-0 small">
              <thead class="sticky-top"><tr><th scope="col"></th><th scope="col">Commune</th><th scope="col">Code postal</th><th scope="col">Surface (km²)</th><th scope="col">Population</th></tr></thead>
              <tbody data-import-rows></tbody>
            </table>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="cities_import_data"><?= json_encode(array('scopes' => $scopebounds, 'cities' => $cities_by_scope), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<div class="card shadow-sm">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-buildings"></i> Villes</h2>
    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#cityModal" data-title="Ajouter une ville"
            data-fill="<?= h(json_encode($city_empty)) ?>"><i class="bi bi-plus-lg"></i> Ajouter une ville</button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin mb-0">
      <thead>
        <tr>
          <th scope="col" class="d-none d-md-table-cell">#</th>
          <th scope="col">Nom</th>
          <th scope="col">Scope</th>
          <th scope="col" class="d-none d-sm-table-cell">Code postal</th>
          <th scope="col" class="d-none d-xl-table-cell text-end">Surface (km²)</th>
          <th scope="col" class="d-none d-lg-table-cell text-end">Population</th>
          <th scope="col" class="d-none d-xl-table-cell">Site</th>
          <th scope="col" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
<?php
while ($query_cities && ($result_cities = mysqli_fetch_array($query_cities))) {
    $city_id    = intval($result_cities['city_id']);
    $city_scope = intval($result_cities['city_scope']);
    $city_fill  = array('city_id' => $city_id, 'city_name' => (string) $result_cities['city_name'], 'city_scope' => $city_scope,
                        'city_postcode' => (string) $result_cities['city_postcode'], 'city_area' => (string) $result_cities['city_area'],
                        'city_population' => (string) $result_cities['city_population'], 'city_website' => (string) $result_cities['city_website'],
                        'wikidata_import' => '0');
    $website    = (string) $result_cities['city_website'];
?>
        <tr id="city<?= $city_id ?>">
          <td class="d-none d-md-table-cell text-body-secondary">#<?= $city_id ?></td>
          <td class="fw-semibold"><?= h($result_cities['city_name']) ?></td>
          <td><?= isset($scopelist[$city_scope]) ? h($scopelist[$city_scope]) : '<span class="badge text-bg-warning">Aucun scope</span>' ?></td>
          <td class="d-none d-sm-table-cell"><?= h(sprintf('%05d', intval($result_cities['city_postcode']))) ?></td>
          <td class="d-none d-xl-table-cell text-end"><?= h(number_format((float) $result_cities['city_area'], 2, ',', ' ')) ?></td>
          <td class="d-none d-lg-table-cell text-end"><?= h(number_format((float) $result_cities['city_population'], 0, ',', ' ')) ?></td>
          <td class="d-none d-xl-table-cell text-truncate" style="max-width: 16rem;">
            <?php if (preg_match('#^https?://#i', $website)) { ?><a href="<?= h($website) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https?://(www\.)?#i', '', $website)) ?></a><?php } else { echo h($website); } ?>
          </td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#cityModal"
                    data-title="Modifier la ville #<?= $city_id ?>" data-fill="<?= h(json_encode($city_fill)) ?>"><i class="bi bi-pencil"></i><span class="d-none d-lg-inline"> Modifier</span></button>
            <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;cityid=<?= $city_id ?><?= h(csrf_query()) ?>" data-confirm="Merci de valider la suppression de la ville #<?= $city_id ?>" title="Supprimer"><i class="bi bi-trash"></i><span class="d-none d-lg-inline"> Supprimer</span></a>
          </td>
        </tr>
<?php
}
?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="cityModal" tabindex="-1" aria-labelledby="cityModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="POST" action="<?= h($self_url) ?>" name="cityform">
      <?= csrf_field() ?>
      <input type="hidden" name="city_id" value="0" />
      <input type="hidden" name="wikidata_import" value="0" />
      <div class="modal-header">
        <h2 class="modal-title h5" id="cityModalTitle">Ville</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label" for="city_name">Nom</label>
            <div class="input-group">
              <input type="text" class="form-control" id="city_name" name="city_name" maxlength="255" required />
              <button class="btn btn-outline-secondary" type="button" data-wikidata-form="cityform" title="Compléter avec Wikidata"><i class="bi bi-cloud-download"></i> Wikidata</button>
            </div>
            <div class="form-text">Wikidata propose le code postal, la surface, la population et le site à partir du nom.</div>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="city_scope">Scope</label>
            <select class="form-select" id="city_scope" name="city_scope">
              <option value="0">-- Aucun scope --</option>
              <?php foreach ($scopelist as $scopeid => $scopename) { ?>
                <option value="<?= intval($scopeid) ?>"><?= h($scopename) ?></option>
              <?php } ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="city_postcode">Code postal</label>
            <input type="text" class="form-control" id="city_postcode" name="city_postcode" inputmode="numeric" pattern="[0-9]{1,5}" />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="city_area">Surface (km²)</label>
            <input type="text" class="form-control" id="city_area" name="city_area" inputmode="decimal" />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label" for="city_population">Population</label>
            <input type="text" class="form-control" id="city_population" name="city_population" inputmode="numeric" />
          </div>
          <div class="col-12">
            <label class="form-label" for="city_website">Site</label>
            <input type="text" class="form-control" id="city_website" name="city_website" maxlength="255" placeholder="https://" />
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php if ($reopen !== null) { ?>
<div hidden data-reopen-modal="#cityModal" data-title="<?= $reopen['city_id'] ? 'Modifier la ville #' . intval($reopen['city_id']) : 'Ajouter une ville' ?>" data-fill="<?= h(json_encode($reopen)) ?>"></div>
<?php } ?>

<script src="js/wikidata.js"></script>
<link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="js/cities-import.js"></script>
