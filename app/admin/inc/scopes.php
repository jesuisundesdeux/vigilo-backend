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
$reopen    = null;
$created   = 0;

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
    'scope_association_url'      => array('type' => 'url', 'max' => 255),
    'scope_umap_url'             => array('type' => 'string', 'max' => 255),
    'scope_nominatim_urlbase'    => array('type' => 'string', 'max' => 255)
);

/*
 * Identifier of a scope: department number (01 to 95, 2A, 2B, 971 to 976) or country
 * code (be, ch...), "_", then the name of the territory without space or special
 * character (e.g. 34_montpellier). Only checked when it changes: the identifier of an
 * existing scope is used by the applications (vigilo-conf).
 */
if (!function_exists('scope_name_valid')) {
    function scope_name_valid($name)
    {
        return (bool) preg_match('/^(0[1-9]|[1-8][0-9]|9[0-5]|2[AB]|97[1-6]|[a-z]{2})_[A-Za-z0-9]+$/', $name);
    }
}

if (isset($_GET['action']) && !isset($_POST['scope_id'])) {
    if ($_GET['action'] == 'delete' && isset($_GET['scopeid']) && is_numeric($_GET['scopeid']) && !$saas_mode) {
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

/* Creation window: identifier, name, department and contact; the map is set afterwards on the card of the scope */
if (isset($_POST['scope_create']) && !$saas_mode) {
    $errors = array();
    $new    = array();
    foreach (array('scope_name', 'scope_display_name', 'scope_contact_email') as $column) {
        $new[$column] = isset($_POST[$column]) && is_string($_POST[$column]) ? trim($_POST[$column]) : '';
        if (strlen($new[$column]) > 255) {
            $errors[] = 'Le champ <strong>' . h($column) . '</strong> est trop long (255 caractères maximum).';
        }
    }
    $new['scope_department'] = isset($_POST['scope_department']) && is_scalar($_POST['scope_department']) ? intval($_POST['scope_department']) : 0;
    if (!scope_name_valid($new['scope_name'])) {
        $errors[] = 'Identifiant invalide : numéro de département (ex. 34, 2A, 974) ou code pays, « _ », puis le nom du territoire sans espace, accent ni caractère spécial (ex. 34_montpellier).';
    } else {
        $query_dup = mysqli_query($db, "SELECT scope_id FROM obs_scopes WHERE scope_name = '" . mysqli_real_escape_string($db, $new['scope_name']) . "' LIMIT 1");
        if ($query_dup && mysqli_fetch_array($query_dup)) {
            $errors[] = 'L\'identifiant <strong>' . h($new['scope_name']) . '</strong> est déjà utilisé.';
        }
    }
    if ($new['scope_display_name'] === '') {
        $errors[] = 'Le nom affiché est obligatoire.';
    }
    if ($new['scope_department'] < 0 || $new['scope_department'] > 127) {
        $errors[] = 'Département invalide.';
    }
    if (!empty($errors)) {
        foreach ($errors as $error) {
            $messages[] = array('danger', $error);
        }
        $messages[] = array('warning', 'Le scope n\'a pas été ajouté.');
        $reopen = $new;
    } elseif (mysqli_query($db, "INSERT INTO obs_scopes (scope_name, scope_display_name, scope_department, scope_coordinate_lat_min,
                                     scope_coordinate_lat_max, scope_coordinate_lon_min, scope_coordinate_lon_max, scope_map_center_string,
                                     scope_map_zoom, scope_contact_email, scope_sharing_content_text, scope_twitter, scope_umap_url, scope_nominatim_urlbase)
                                 VALUES ('" . mysqli_real_escape_string($db, $new['scope_name']) . "', '" . mysqli_real_escape_string($db, $new['scope_display_name']) . "', "
                                 . $new['scope_department'] . ", '0', '0', '0', '0', '0.00,0.00', 13, '" . mysqli_real_escape_string($db, $new['scope_contact_email']) . "',
                                 '', '', '', 'https://nominatim.openstreetmap.org')")) {
        $created = intval(mysqli_insert_id($db));
        audit_log('scope_create', 'scope:' . $created, $new);
        $messages[] = array('success', 'Scope <strong>' . h($new['scope_name']) . '</strong> ajouté : <a href="#scope' . $created . '">tracer maintenant son territoire et le centre de ses cartes</a>, puis enregistrer.');
    } else {
        $messages[] = array('danger', 'Impossible d\'ajouter le scope.');
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
                case 'url':
                    // website of the association: empty, or an absolute http(s) address
                    $value = $raw;
                    if ($value !== '' && (strlen($value) > $field['max'] || !preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false)) {
                        $errors[] = 'Site de l\'association : adresse invalide (ex. https://association.example).';
                        continue 2;
                    }
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
                    if ($column == 'scope_name' && $value !== (string) $old['scope_name'] && !scope_name_valid($value)) {
                        $errors[] = 'Identifiant invalide : numéro de département (ex. 34, 2A, 974) ou code pays, « _ », puis le nom du territoire sans espace, accent ni caractère spécial (ex. 34_montpellier).';
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
  <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#scopeModal"><i class="bi bi-plus-lg"></i> Ajouter un scope</button>
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
          <input type="text" class="form-control font-monospace" id="<?= $prefix ?>name" name="scope_name" value="<?= h($result_scopes['scope_name']) ?>" maxlength="255" required
                 pattern="(0[1-9]|[1-8][0-9]|9[0-5]|2[AB]|97[1-6]|[a-z]{2})_[A-Za-z0-9]+" data-scope-name="<?= $prefix ?>department" aria-describedby="<?= $prefix ?>name_help" />
          <div class="form-text" id="<?= $prefix ?>name_help">N° de département (ou code pays), « _ », nom sans espace ni accent. Ex. : <code>34_montpellier</code></div>
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
          <label class="form-label" for="<?= $prefix ?>association_url">Site de l'association</label>
          <input type="url" class="form-control" id="<?= $prefix ?>association_url" name="scope_association_url" value="<?= h(isset($result_scopes['scope_association_url']) ? $result_scopes['scope_association_url'] : '') ?>" maxlength="255" placeholder="https://" aria-describedby="<?= $prefix ?>association_url_help" />
          <div class="form-text" id="<?= $prefix ?>association_url_help">Affiché sur la page du territoire sur vigilo.city.</div>
        </div>
      </div>

      <h4 class="h6 text-body-secondary">Carte</h4>
      <div class="scope-map-block mb-3">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <div class="input-group input-group-sm" style="max-width: 22rem;">
            <input type="search" class="form-control" placeholder="Aller à une ville, un lieu…" data-map-search aria-label="Rechercher un lieu" />
            <button class="btn btn-outline-secondary" type="button" data-map-action="search"><i class="bi bi-search"></i></button>
          </div>
          <button class="btn btn-sm btn-outline-primary" type="button" data-map-action="draw"><i class="bi bi-bounding-box"></i> Tracer le territoire</button>
          <button class="btn btn-sm btn-outline-primary" type="button" data-map-action="view-bounds"><i class="bi bi-aspect-ratio"></i> Territoire = vue de la carte</button>
          <button class="btn btn-sm btn-outline-primary" type="button" data-map-action="view-center"><i class="bi bi-crosshair"></i> Centre et zoom = vue de la carte</button>
          <a class="btn btn-sm btn-outline-secondary" href="?page=cities&amp;import_scope=<?= $scope_id ?>#import"><i class="bi bi-buildings"></i> Importer les villes du territoire</a>
        </div>
        <div class="scope-map rounded border" data-scope-map="<?= $prefix ?>" style="height: 380px;"></div>
        <div class="form-text" data-map-status aria-live="polite"></div>
        <div class="form-text">Le rectangle bleu limite le territoire (observations acceptées) ; le marqueur, déplaçable, est le centre des cartes des applications. Les champs ci-dessous se mettent à jour : enregistrer pour valider.</div>
      </div>
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

<?php if (!$saas_mode) { ?>
<div class="modal fade" id="scopeModal" tabindex="-1" aria-labelledby="scopeModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="POST" action="<?= h($self_url) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="scope_create" value="1" />
      <div class="modal-header">
        <h2 class="modal-title h5" id="scopeModalTitle">Ajouter un scope</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="new_scope_name">Identifiant</label>
            <input type="text" class="form-control font-monospace" id="new_scope_name" name="scope_name" maxlength="255" required placeholder="34_montpellier"
                   pattern="(0[1-9]|[1-8][0-9]|9[0-5]|2[AB]|97[1-6]|[a-z]{2})_[A-Za-z0-9]+" data-scope-name="new_scope_department" aria-describedby="new_scope_name_help" />
            <div class="form-text" id="new_scope_name_help">N° de département (ou code pays), « _ », nom sans espace ni accent. Il ne doit plus changer ensuite : les applications l'utilisent.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="new_scope_display_name">Nom affiché</label>
            <input type="text" class="form-control" id="new_scope_display_name" name="scope_display_name" maxlength="255" required placeholder="Montpellier Métropole" />
          </div>
          <div class="col-md-4">
            <label class="form-label" for="new_scope_department">Département</label>
            <input type="number" class="form-control" id="new_scope_department" name="scope_department" min="0" max="127" />
          </div>
          <div class="col-md-8">
            <label class="form-label" for="new_scope_contact_email">Email contact</label>
            <input type="email" class="form-control" id="new_scope_contact_email" name="scope_contact_email" maxlength="255" />
          </div>
        </div>
        <p class="small text-body-secondary mt-3 mb-0">Le territoire et le centre des cartes se choisissent ensuite sur la carte du scope.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Créer le scope</button>
      </div>
    </form>
  </div>
</div>
<?php if ($reopen !== null) { ?>
<div hidden data-reopen-modal="#scopeModal" data-fill="<?= h(json_encode($reopen)) ?>"></div>
<?php } ?>
<?php if ($created) { ?>
<script>document.addEventListener('DOMContentLoaded', function () { var c = document.getElementById('scope<?= intval($created) ?>'); if (c) { c.scrollIntoView(); } });</script>
<?php } ?>
<?php } ?>

<link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="js/scope-map.js"></script>
