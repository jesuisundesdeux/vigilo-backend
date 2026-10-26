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
    http_response_code(403);
    echo '<div class="alert alert-danger" role="alert">Accès refusé</div>';
    return;
}

$self_url = '?page=' . urlencode($page_name);
$messages = array();

$banner_type_labels    = array('info' => 'Information', 'success' => 'Succès', 'warning' => 'Attention', 'alert' => 'Alerte');
$banner_type_colors    = array('info' => '#1565c0', 'success' => '#2e7d32', 'warning' => '#ef6c00', 'alert' => '#c62828');
$banner_display_labels = array('banner' => 'Bandeau sous la barre', 'modal' => 'Fenêtre au lancement', 'both' => 'Les deux');

// Scopes of the instance, to target a banner (scope_id => display name)
$scopes = array();
$scope_query = mysqli_query($db, "SELECT scope_id, scope_display_name, scope_name FROM obs_scopes ORDER BY scope_display_name");
while ($scope_query && $scope_row = mysqli_fetch_assoc($scope_query)) {
    $scopes[intval($scope_row['scope_id'])] = $scope_row['scope_display_name'] !== '' ? $scope_row['scope_display_name'] : $scope_row['scope_name'];
}

// 'YYYY-MM-DDTHH:MM' (datetime-local) -> unix timestamp (instance timezone), 0 if empty/invalid
function banner_parse_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $ts = strtotime($value);
    return $ts === false ? 0 : $ts;
}

function banner_format_datetime($ts)
{
    $ts = intval($ts);
    return $ts > 0 ? date('Y-m-d\TH:i', $ts) : '';
}

// Clean a posted list of scope ids against the instance scopes, comma-separated ('' = all)
function banner_clean_scopes($posted, $scopes)
{
    $ids = array();
    if (is_array($posted)) {
        foreach ($posted as $value) {
            $id = intval($value);
            if (isset($scopes[$id]) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
    }
    return implode(',', $ids);
}

if (isset($_POST['banner_delete'])) {
    $id = isset($_POST['banner_id']) ? intval($_POST['banner_id']) : 0;
    if ($id > 0 && mysqli_query($db, "DELETE FROM obs_banners WHERE banner_id = " . $id)) {
        audit_log('banner_delete', 'banner:' . $id, array());
        $messages[] = array('success', 'Bannière supprimée.');
    } else {
        $messages[] = array('danger', 'Suppression impossible.');
    }
}

if (isset($_POST['banner_save'])) {
    $id      = isset($_POST['banner_id']) ? intval($_POST['banner_id']) : 0;
    $message = trim(isset($_POST['banner_message']) ? (string) $_POST['banner_message'] : '');
    $type    = isset($_POST['banner_type']) && in_array($_POST['banner_type'], vigilo_banner_types(), true) ? $_POST['banner_type'] : 'info';
    $display = isset($_POST['banner_display']) && in_array($_POST['banner_display'], vigilo_banner_displays(), true) ? $_POST['banner_display'] : 'banner';
    $scope_list = banner_clean_scopes(isset($_POST['banner_scopes']) ? $_POST['banner_scopes'] : array(), $scopes);
    $start   = banner_parse_datetime(isset($_POST['banner_start']) ? $_POST['banner_start'] : '');
    $end     = banner_parse_datetime(isset($_POST['banner_end']) ? $_POST['banner_end'] : '');
    $enabled = empty($_POST['banner_enabled']) ? 0 : 1;
    $persistent = empty($_POST['banner_persistent']) ? 0 : 1;

    $error = '';
    if ($message === '') {
        $error = 'Le message est obligatoire.';
    } elseif (strlen($message) > 2000) {
        $error = 'Message trop long (2000 caractères maximum).';
    } elseif ($start > 0 && $end > 0 && $end < $start) {
        $error = 'La date de fin est avant la date de début.';
    }

    if ($error !== '') {
        // Reopen the window with the submitted values (data-reopen-modal, admin.js)
        $reopen = array(
            'banner_id' => $id, 'banner_message' => $message, 'banner_type' => $type, 'banner_display' => $display,
            'banner_scopes' => $scope_list === '' ? array() : array_map('strval', explode(',', $scope_list)),
            'banner_start' => banner_format_datetime($start), 'banner_end' => banner_format_datetime($end),
            'banner_enabled' => $enabled, 'banner_persistent' => $persistent,
        );
        echo '<div class="alert alert-danger" role="alert" data-reopen-modal="#bannerModal" data-title="'
            . h($id > 0 ? 'Modifier la bannière' : 'Nouvelle bannière') . '" data-fill="' . h(json_encode($reopen)) . '">'
            . '<i class="bi bi-exclamation-triangle"></i> ' . h($error) . '</div>';
    } else {
        $m = mysqli_real_escape_string($db, $message);
        $tp = mysqli_real_escape_string($db, $type);
        $dp = mysqli_real_escape_string($db, $display);
        $sc = mysqli_real_escape_string($db, $scope_list);
        if ($id > 0) {
            $ok = mysqli_query($db, "UPDATE obs_banners SET banner_message='$m', banner_type='$tp', banner_display='$dp',
                banner_scopes='$sc', banner_start=" . intval($start) . ", banner_end=" . intval($end) . ", banner_enabled=" . intval($enabled) . ",
                banner_persistent=" . intval($persistent) . "
                WHERE banner_id=" . $id);
            if ($ok) {
                audit_log('banner_edit', 'banner:' . $id, array('type' => $type, 'enabled' => $enabled));
                $messages[] = array('success', 'Bannière enregistrée.');
            } else {
                $messages[] = array('danger', 'Enregistrement impossible.');
            }
        } else {
            $ok = mysqli_query($db, "INSERT INTO obs_banners (banner_message, banner_type, banner_display, banner_scopes, banner_start, banner_end, banner_enabled, banner_persistent, banner_created)
                VALUES ('$m', '$tp', '$dp', '$sc', " . intval($start) . ", " . intval($end) . ", " . intval($enabled) . ", " . intval($persistent) . ", " . time() . ")");
            if ($ok) {
                audit_log('banner_create', 'banner:' . mysqli_insert_id($db), array('type' => $type));
                $messages[] = array('success', 'Bannière ajoutée.');
            } else {
                $messages[] = array('danger', 'Ajout impossible.');
            }
        }
    }
}

foreach ($messages as $message) {
    echo '<div class="alert alert-' . h($message[0]) . ' alert-dismissible fade show" role="alert">' . h($message[1])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
}

$banners = vigilo_banners_all($db);
$now = time();

// Shared markup for the scope <select multiple> options
$scope_options = '';
foreach ($scopes as $sid => $sname) {
    $scope_options .= '<option value="' . intval($sid) . '">' . h($sname) . '</option>';
}
?>

<p class="text-body-secondary">Messages d'annonce affichés dans l'application web. Plusieurs bannières peuvent être
programmées dans le temps ; la plus récemment commencée parmi celles actives est affichée. Une bannière peut ne
concerner que certains scopes de l'instance.</p>

<button class="btn btn-sm btn-primary mb-3" type="button" data-bs-toggle="modal" data-bs-target="#bannerModal" data-title="Nouvelle bannière"
        data-fill="<?= h(json_encode(array('banner_id' => 0, 'banner_message' => '', 'banner_type' => 'info', 'banner_display' => 'banner', 'banner_scopes' => array(), 'banner_start' => '', 'banner_end' => '', 'banner_enabled' => 1, 'banner_persistent' => 0))) ?>">
  <i class="bi bi-plus-lg"></i> Nouvelle bannière</button>

<?php if (empty($banners)): ?>
  <div class="alert alert-info" role="alert"><i class="bi bi-info-circle"></i> Aucune bannière. Cliquez sur « Nouvelle bannière » pour en créer une.</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead><tr>
      <th>Message</th><th>Type</th><th>Scopes</th><th>Période</th><th>État</th><th class="text-end">Actions</th>
    </tr></thead>
    <tbody>
<?php foreach ($banners as $row):
      $id = intval($row['banner_id']);
      $type = isset($banner_type_labels[$row['banner_type']]) ? $row['banner_type'] : 'info';
      $scope_ids = $row['banner_scopes'] === '' ? array() : explode(',', $row['banner_scopes']);
      $scope_names = array();
      foreach ($scope_ids as $sid) {
          if (isset($scopes[intval($sid)])) { $scope_names[] = $scopes[intval($sid)]; }
      }
      $start = intval($row['banner_start']);
      $end   = intval($row['banner_end']);
      $enabled = intval($row['banner_enabled']) === 1;
      $active  = $enabled && ($start === 0 || $start <= $now) && ($end === 0 || $end >= $now);
      $period = '';
      if ($start > 0) { $period .= 'du ' . date('d/m/Y H:i', $start); }
      if ($end > 0) { $period .= ($period === '' ? 'jusqu\'au ' . date('d/m/Y H:i', $end) : ' au ' . date('d/m/Y H:i', $end)); }
      if ($period === '') { $period = 'toujours'; }
      $excerpt = mb_substr($row['banner_message'], 0, 80) . (mb_strlen($row['banner_message']) > 80 ? '…' : '');
      $fill = array(
          'banner_id' => $id, 'banner_message' => $row['banner_message'], 'banner_type' => $type,
          'banner_display' => isset($banner_display_labels[$row['banner_display']]) ? $row['banner_display'] : 'banner',
          'banner_scopes' => array_map('strval', $scope_ids),
          'banner_start' => banner_format_datetime($start), 'banner_end' => banner_format_datetime($end),
          'banner_enabled' => $enabled ? 1 : 0,
          'banner_persistent' => intval($row['banner_persistent']) ? 1 : 0,
      );
?>
      <tr>
        <td><?= h($excerpt) ?></td>
        <td><span class="badge" style="background: <?= h($banner_type_colors[$type]) ?>;"><?= h($banner_type_labels[$type]) ?></span></td>
        <td class="small"><?= $scope_names ? h(implode(', ', $scope_names)) : '<span class="text-body-secondary">Tous</span>' ?></td>
        <td class="small"><?= h($period) ?></td>
        <td>
          <?php if (!$enabled): ?><span class="badge text-bg-secondary">désactivée</span>
          <?php elseif ($active): ?><span class="badge text-bg-success">en cours</span>
          <?php else: ?><span class="badge text-bg-light border">programmée</span><?php endif ?>
          <?php if (intval($row['banner_persistent'])): ?><span class="badge text-bg-info" title="Réaffichée à chaque ouverture même si fermée">persistante</span><?php endif ?>
        </td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#bannerModal"
                  data-title="Modifier la bannière" data-fill="<?= h(json_encode($fill)) ?>"><i class="bi bi-pencil"></i><span class="d-none d-lg-inline"> Modifier</span></button>
          <form method="POST" action="<?= h($self_url) ?>" class="d-inline" onsubmit="return confirm('Supprimer cette bannière ?');">
            <?= csrf_field() ?>
            <input type="hidden" name="banner_delete" value="1" />
            <input type="hidden" name="banner_id" value="<?= $id ?>" />
            <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer"><i class="bi bi-trash"></i><span class="visually-hidden">Supprimer</span></button>
          </form>
        </td>
      </tr>
<?php endforeach ?>
    </tbody>
  </table>
</div>
<?php endif ?>

<div class="modal fade" id="bannerModal" tabindex="-1" aria-labelledby="bannerModalTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="<?= h($self_url) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="banner_save" value="1" />
      <input type="hidden" name="banner_id" value="0" />
      <div class="modal-header">
        <h2 class="modal-title h5" id="bannerModalTitle">Bannière</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="banner_message">Message</label>
          <textarea class="form-control" id="banner_message" name="banner_message" rows="3" maxlength="2000" required></textarea>
        </div>
        <div class="row">
          <div class="col-sm-6 mb-3">
            <label class="form-label" for="banner_type">Type</label>
            <select class="form-select" id="banner_type" name="banner_type">
<?php foreach ($banner_type_labels as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
<?php endforeach ?>
            </select>
          </div>
          <div class="col-sm-6 mb-3">
            <label class="form-label" for="banner_display">Affichage</label>
            <select class="form-select" id="banner_display" name="banner_display">
<?php foreach ($banner_display_labels as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
<?php endforeach ?>
            </select>
          </div>
        </div>
        <div class="row">
          <div class="col-sm-6 mb-3">
            <label class="form-label" for="banner_start">Début <span class="text-body-secondary small">(facultatif)</span></label>
            <input class="form-control" type="datetime-local" id="banner_start" name="banner_start" />
          </div>
          <div class="col-sm-6 mb-3">
            <label class="form-label" for="banner_end">Fin <span class="text-body-secondary small">(facultatif)</span></label>
            <input class="form-control" type="datetime-local" id="banner_end" name="banner_end" />
          </div>
        </div>
<?php if (!empty($scopes)): ?>
        <div class="mb-3">
          <label class="form-label" for="banner_scopes">Scopes concernés</label>
          <select class="form-select" id="banner_scopes" name="banner_scopes[]" multiple size="<?= min(count($scopes), 5) ?>">
            <?= $scope_options ?>
          </select>
          <div class="form-text">Aucune sélection = tous les scopes de l'instance.</div>
        </div>
<?php endif ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="banner_enabled" name="banner_enabled" value="1" />
          <label class="form-check-label" for="banner_enabled">Activée (affichée dans l'application, selon les dates)</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="banner_persistent" name="banner_persistent" value="1" />
          <label class="form-check-label" for="banner_persistent">Persistante : réaffichée à chaque ouverture de l'application, même si la personne l'a fermée (et la fenêtre se rouvre au lancement). Sinon, une fois fermée elle ne revient pas, même après redémarrage.</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
