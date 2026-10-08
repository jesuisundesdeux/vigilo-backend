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

$scopelist    = array();
$query_scopes = mysqli_query($db, "SELECT scope_id, scope_name, scope_display_name FROM obs_scopes ORDER BY scope_id");
while ($query_scopes && ($result_scopes = mysqli_fetch_array($query_scopes))) {
    $scopelist[intval($result_scopes['scope_id'])] = (string) $result_scopes['scope_name'];
}

if (isset($_GET['action']) && !isset($_POST['city_id'])) {
    if ($_GET['action'] == 'add') {
        $ok = mysqli_query($db, "INSERT INTO obs_cities (city_scope,
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
        if ($ok) {
            $new_id = intval(mysqli_insert_id($db));
            audit_log('city_create', 'city:' . $new_id);
            $messages[] = array('success', 'Ville <strong>#' . $new_id . '</strong> ajoutée, merci de remplir les champs correspondants.');
        } else {
            $messages[] = array('danger', 'Impossible d\'ajouter la ville.');
        }
    }
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
    $query_check = mysqli_query($db, "SELECT * FROM obs_cities WHERE city_id = " . $cityid . " LIMIT 1");
    $old         = $query_check ? mysqli_fetch_array($query_check) : null;
    $errors      = array();
    $values      = array();

    if (!$old) {
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
        $messages[] = array('warning', 'La ville <strong>#' . $cityid . '</strong> n\'a pas été modifiée.');
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
?>
<div class="card shadow-sm">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-buildings"></i> Villes</h2>
    <a class="btn btn-sm btn-primary" href="<?= h($self_url) ?>&amp;action=add<?= h(csrf_query()) ?>"><i class="bi bi-plus-lg"></i> Ajouter une ville</a>
  </div>
  <div class="card-body pb-0">
    <p class="small text-body-secondary mb-2">
      Le bouton <i class="bi bi-cloud-download"></i> Wikidata propose les données de la commune (code postal, surface, population, site) à partir de son nom ; vérifier puis enregistrer.
    </p>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin mb-0">
      <thead>
        <tr>
          <th scope="col">#</th>
          <th scope="col">Nom</th>
          <th scope="col">Scope</th>
          <th scope="col">Code postal</th>
          <th scope="col">Surface (km²)</th>
          <th scope="col">Population</th>
          <th scope="col">Site</th>
          <th scope="col" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
<?php
$forms = '';
while ($query_cities && ($result_cities = mysqli_fetch_array($query_cities))) {
    $city_id = intval($result_cities['city_id']);
    $form_id = 'city' . $city_id . 'form';
    $forms  .= '<form method="POST" action="' . h($self_url) . '" id="' . h($form_id) . '" name="' . h($form_id) . '">'
             . csrf_field()
             . '<input type="hidden" name="city_id" value="' . $city_id . '" />'
             . '<input type="hidden" name="wikidata_import" value="0" /></form>';
?>
        <tr id="city<?= $city_id ?>">
          <td>#<?= $city_id ?></td>
          <td>
            <input type="text" class="form-control form-control-sm" name="city_name" form="<?= h($form_id) ?>" value="<?= h($result_cities['city_name']) ?>" maxlength="255" required aria-label="Nom" />
          </td>
          <td>
            <select name="city_scope" class="form-select form-select-sm" form="<?= h($form_id) ?>" aria-label="Scope">
<?php
    if (!isset($scopelist[intval($result_cities['city_scope'])])) {
        echo '<option value="0" selected>-- Aucun scope --</option>';
    }
    foreach ($scopelist as $scopeid => $scopename) {
        $selected = ($scopeid == $result_cities['city_scope']) ? ' selected' : '';
        echo '<option value="' . intval($scopeid) . '"' . $selected . '>' . h($scopename) . '</option>';
    }
?>
            </select>
          </td>
          <td>
            <input type="text" class="form-control form-control-sm" name="city_postcode" form="<?= h($form_id) ?>" value="<?= h($result_cities['city_postcode']) ?>" inputmode="numeric" required aria-label="Code postal" />
          </td>
          <td>
            <input type="text" class="form-control form-control-sm" name="city_area" form="<?= h($form_id) ?>" value="<?= h($result_cities['city_area']) ?>" inputmode="decimal" required aria-label="Surface" />
          </td>
          <td>
            <input type="text" class="form-control form-control-sm" name="city_population" form="<?= h($form_id) ?>" value="<?= h($result_cities['city_population']) ?>" inputmode="numeric" required aria-label="Population" />
          </td>
          <td>
            <input type="text" class="form-control form-control-sm" name="city_website" form="<?= h($form_id) ?>" value="<?= h($result_cities['city_website']) ?>" maxlength="255" aria-label="Site" />
          </td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-primary" type="submit" form="<?= h($form_id) ?>"><i class="bi bi-check-lg"></i> Enregistrer</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-wikidata-form="<?= h($form_id) ?>" title="Compléter avec Wikidata"><i class="bi bi-cloud-download"></i> Wikidata</button>
            <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;cityid=<?= $city_id ?><?= h(csrf_query()) ?>" data-confirm="Merci de valider la suppression de la ville #<?= $city_id ?>"><i class="bi bi-trash"></i> Supprimer</a>
          </td>
        </tr>
<?php
}
?>
      </tbody>
    </table>
  </div>
</div>
<?= $forms ?>

<script src="js/wikidata.js"></script>
