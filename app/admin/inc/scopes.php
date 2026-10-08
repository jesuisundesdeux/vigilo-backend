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

$saas_mode = isset($config['SAAS_MODE']) && $config['SAAS_MODE'];
$messages  = array();

/*
 * Editable columns (allowlist): type and maximum length.
 * The POST keys are never used as SQL identifiers.
 */
$scope_fields = array(
    'scope_name'                 => array('type' => 'string', 'max' => 255, 'required' => true),
    'scope_display_name'         => array('type' => 'string', 'max' => 255, 'required' => true),
    'scope_department'           => array('type' => 'int'),
    'scope_coordinate_lat_min'   => array('type' => 'string', 'max' => 255),
    'scope_coordinate_lat_max'   => array('type' => 'string', 'max' => 255),
    'scope_coordinate_lon_min'   => array('type' => 'string', 'max' => 255),
    'scope_coordinate_lon_max'   => array('type' => 'string', 'max' => 255),
    'scope_map_center_string'    => array('type' => 'string', 'max' => 255),
    'scope_map_zoom'             => array('type' => 'zoom'),
    'scope_contact_email'        => array('type' => 'string', 'max' => 255),
    'scope_sharing_content_text' => array('type' => 'string', 'max' => 255),
    'scope_umap_url'             => array('type' => 'string', 'max' => 255),
    'scope_nominatim_urlbase'    => array('type' => 'string', 'max' => 255)
);

if (isset($_GET['action']) && !isset($_POST['scope_id'])) {
    if ($_GET['action'] == 'add' && !$saas_mode) {
        $ok = mysqli_query($db, "INSERT INTO obs_scopes (scope_name,
                                              scope_display_name,
                                              scope_department,
                                              scope_coordinate_lat_min,
                                              scope_coordinate_lat_max,
                                              scope_coordinate_lon_min,
                                              scope_coordinate_lon_max,
                                              scope_map_center_string,
                                              scope_map_zoom,
                                              scope_contact_email,
                                              scope_sharing_content_text,
                                              scope_twitter,
                                              scope_umap_url,
                                              scope_nominatim_urlbase)
                                     VALUES ('xx_scope',
                                             'Nouveau Scope',
                                             '00',
                                             '0.00',
                                             '0.00',
                                             '0.00',
                                             '0.00',
                                             '0.00,0.00',
                                             '15',
                                             'email@domaine.com',
                                             '',
                                             '',
                                             '',
                                             'https://nominatim.openstreetmap.org')");
        if ($ok) {
            $new_id = intval(mysqli_insert_id($db));
            audit_log('scope_create', 'scope:' . $new_id);
            $messages[] = array('success', 'Scope <strong>#' . $new_id . '</strong> ajouté, merci de remplir les champs correspondants.');
        } else {
            $messages[] = array('danger', 'Impossible d\'ajouter le scope.');
        }
    } elseif ($_GET['action'] == 'delete' && isset($_GET['scopeid']) && is_numeric($_GET['scopeid']) && !$saas_mode) {
        $scopeid     = intval($_GET['scopeid']);
        $query_check = mysqli_query($db, "SELECT scope_name FROM obs_scopes WHERE scope_id = " . $scopeid . " LIMIT 1");
        $check       = $query_check ? mysqli_fetch_array($query_check) : null;
        if (!$check) {
            $messages[] = array('warning', 'Scope <strong>#' . $scopeid . '</strong> introuvable.');
        } elseif (mysqli_query($db, "DELETE FROM obs_scopes WHERE scope_id = " . $scopeid)) {
            audit_log('scope_delete', 'scope:' . $scopeid, array('scope_name' => $check['scope_name']));
            $messages[] = array('success', 'Scope <strong>#' . $scopeid . '</strong> supprimé.');
        } else {
            $messages[] = array('danger', 'Impossible de supprimer le scope <strong>#' . $scopeid . '</strong>.');
        }
    } elseif ($saas_mode) {
        $messages[] = array('warning', 'Cette fonctionnalité n\'est pas accessible en SaaS.');
    }
}

if (isset($_POST['scope_id'])) {
    $scopeid     = intval($_POST['scope_id']);
    $query_check = mysqli_query($db, "SELECT * FROM obs_scopes WHERE scope_id = " . $scopeid . " LIMIT 1");
    $old         = $query_check ? mysqli_fetch_array($query_check) : null;
    $errors      = array();
    $parts       = array();
    $changes     = array();

    if (!$old) {
        $errors[] = 'Scope <strong>#' . $scopeid . '</strong> introuvable.';
    } else {
        foreach ($scope_fields as $column => $field) {
            if (!isset($_POST[$column]) || !is_scalar($_POST[$column])) {
                continue;
            }
            $raw = trim((string) $_POST[$column]);
            switch ($field['type']) {
                case 'int':
                    $value = intval($raw);
                    break;
                case 'zoom':
                    $value = intval($raw);
                    if ($value < 1 || $value > 20) {
                        $errors[] = 'Le zoom doit être compris entre 1 et 20.';
                        continue 2;
                    }
                    break;
                default:
                    $value = $raw;
                    if (!empty($field['required']) && $value === '') {
                        $errors[] = 'Le champ <strong>' . h($column) . '</strong> est obligatoire.';
                        continue 2;
                    }
                    if (strlen($value) > $field['max']) {
                        $errors[] = 'Le champ <strong>' . h($column) . '</strong> est trop long (' . intval($field['max']) . ' caractères maximum).';
                        continue 2;
                    }
            }
            // $column comes from the allowlist above, never from the POST keys
            if (is_int($value)) {
                $parts[] = $column . " = " . $value;
            } else {
                $parts[] = $column . " = '" . mysqli_real_escape_string($db, $value) . "'";
            }
            if ((string) $old[$column] !== (string) $value) {
                $changes[$column] = $value;
            }
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            $messages[] = array('danger', $error);
        }
        $messages[] = array('warning', 'Le scope <strong>#' . $scopeid . '</strong> n\'a pas été modifié.');
    } elseif (empty($changes)) {
        $messages[] = array('info', 'Aucune modification pour le scope <strong>#' . $scopeid . '</strong>.');
    } elseif (mysqli_query($db, "UPDATE obs_scopes SET " . implode(', ', $parts) . " WHERE scope_id = " . $scopeid)) {
        audit_log('scope_edit', 'scope:' . $scopeid, $changes);
        $messages[] = array('success', 'Scope <strong>#' . $scopeid . '</strong> mis à jour.');
    } else {
        $messages[] = array('danger', 'Impossible de mettre à jour le scope <strong>#' . $scopeid . '</strong>.');
    }
}

foreach ($messages as $message) {
    // Messages are built above from escaped / integer values only
    echo '<div class="alert alert-' . h($message[0]) . '" role="alert">' . $message[1] . '</div>';
}

$query_scopes = mysqli_query($db, "SELECT * FROM obs_scopes ORDER BY scope_id");
$self_url     = '?page=' . urlencode($page_name);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h2 class="h5 mb-0"><i class="bi bi-compass"></i> Scopes</h2>
<?php if (!$saas_mode) { ?>
  <a class="btn btn-sm btn-primary" href="<?= h($self_url) ?>&amp;action=add<?= h(csrf_query()) ?>"><i class="bi bi-plus-lg"></i> Ajouter un scope</a>
<?php } ?>
</div>

<?php
while ($query_scopes && ($result_scopes = mysqli_fetch_array($query_scopes))) {
    $scope_id = intval($result_scopes['scope_id']);
    $prefix   = 'scope' . $scope_id . '_';
?>
<div class="card shadow-sm mb-4" id="scope<?= $scope_id ?>">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h3 class="h6 mb-0">
      <?= h($result_scopes['scope_display_name']) ?>
      <span class="badge text-bg-secondary ms-1"><?= h($result_scopes['scope_name']) ?></span>
      <span class="text-body-secondary small ms-1">#<?= $scope_id ?></span>
    </h3>
<?php if (!$saas_mode) { ?>
    <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;scopeid=<?= $scope_id ?><?= h(csrf_query()) ?>" data-confirm="Merci de valider la suppression du scope #<?= $scope_id ?>"><i class="bi bi-trash"></i> Supprimer</a>
<?php } ?>
  </div>
  <div class="card-body">
    <form method="POST" action="<?= h($self_url) ?>#scope<?= $scope_id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="scope_id" value="<?= $scope_id ?>" />

      <h4 class="h6 text-body-secondary">Général</h4>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label" for="<?= $prefix ?>name">Identifiant</label>
          <input type="text" class="form-control" id="<?= $prefix ?>name" name="scope_name" value="<?= h($result_scopes['scope_name']) ?>" maxlength="255" required />
        </div>
        <div class="col-md-5">
          <label class="form-label" for="<?= $prefix ?>display_name">Nom affiché</label>
          <input type="text" class="form-control" id="<?= $prefix ?>display_name" name="scope_display_name" value="<?= h($result_scopes['scope_display_name']) ?>" maxlength="255" required />
        </div>
        <div class="col-md-3">
          <label class="form-label" for="<?= $prefix ?>department">Département</label>
          <input type="number" class="form-control" id="<?= $prefix ?>department" name="scope_department" value="<?= h($result_scopes['scope_department']) ?>" min="0" max="127" />
        </div>
        <div class="col-md-6">
          <label class="form-label" for="<?= $prefix ?>contact_email">Email contact</label>
          <input type="text" class="form-control" id="<?= $prefix ?>contact_email" name="scope_contact_email" value="<?= h($result_scopes['scope_contact_email']) ?>" maxlength="255" />
        </div>
        <div class="col-md-6">
          <label class="form-label" for="<?= $prefix ?>sharing">Texte de partage par défaut</label>
          <textarea class="form-control" id="<?= $prefix ?>sharing" name="scope_sharing_content_text" rows="3" maxlength="255"><?= h($result_scopes['scope_sharing_content_text']) ?></textarea>
        </div>
      </div>

      <h4 class="h6 text-body-secondary">Carte</h4>
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <label class="form-label" for="<?= $prefix ?>lat_min">Latitude minimale (DD)</label>
          <input type="text" class="form-control" id="<?= $prefix ?>lat_min" name="scope_coordinate_lat_min" value="<?= h($result_scopes['scope_coordinate_lat_min']) ?>" inputmode="decimal" maxlength="255" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="<?= $prefix ?>lat_max">Latitude maximale (DD)</label>
          <input type="text" class="form-control" id="<?= $prefix ?>lat_max" name="scope_coordinate_lat_max" value="<?= h($result_scopes['scope_coordinate_lat_max']) ?>" inputmode="decimal" maxlength="255" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="<?= $prefix ?>lon_min">Longitude minimale (DD)</label>
          <input type="text" class="form-control" id="<?= $prefix ?>lon_min" name="scope_coordinate_lon_min" value="<?= h($result_scopes['scope_coordinate_lon_min']) ?>" inputmode="decimal" maxlength="255" />
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="<?= $prefix ?>lon_max">Longitude maximale (DD)</label>
          <input type="text" class="form-control" id="<?= $prefix ?>lon_max" name="scope_coordinate_lon_max" value="<?= h($result_scopes['scope_coordinate_lon_max']) ?>" inputmode="decimal" maxlength="255" />
        </div>
        <div class="col-md-6">
          <label class="form-label" for="<?= $prefix ?>center">Coordonnées du centre du scope</label>
          <input type="text" class="form-control" id="<?= $prefix ?>center" name="scope_map_center_string" value="<?= h($result_scopes['scope_map_center_string']) ?>" maxlength="255" placeholder="lat,lon" />
        </div>
        <div class="col-md-2">
          <label class="form-label" for="<?= $prefix ?>zoom">Zoom cartes</label>
          <select class="form-select" id="<?= $prefix ?>zoom" name="scope_map_zoom">
<?php
    for ($i = 1; $i <= 20; $i++) {
        $selected = ($i == $result_scopes['scope_map_zoom']) ? ' selected' : '';
        echo '<option value="' . $i . '"' . $selected . '>' . $i . '</option>';
    }
?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="<?= $prefix ?>umap">URL carte externe</label>
          <input type="text" class="form-control" id="<?= $prefix ?>umap" name="scope_umap_url" value="<?= h($result_scopes['scope_umap_url']) ?>" maxlength="255" />
        </div>
        <div class="col-md-6">
          <label class="form-label" for="<?= $prefix ?>nominatim">URL de base Nominatim (reverse geocoding)</label>
          <input type="text" class="form-control" id="<?= $prefix ?>nominatim" name="scope_nominatim_urlbase" value="<?= h($result_scopes['scope_nominatim_urlbase']) ?>" maxlength="255" />
        </div>
      </div>


      <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Enregistrer</button>
    </form>
  </div>
</div>
<?php
}
?>
