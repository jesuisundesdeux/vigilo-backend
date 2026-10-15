<?php
/*
Copyright (C) 2026 Velocité Montpellier

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

require_once(__DIR__ . '/../../includes/problems.php');

$messages   = array();
$self_url   = '?page=' . urlencode($page_name);
$statuses   = problem_statuses();
$priorities = problem_priorities();
$login      = isset($_SESSION['login']) ? (string) $_SESSION['login'] : '';

/* Categories that can be chosen: id => array(name, disabled) */
$problem_categories = array();
foreach ((array) getCategoriesList() as $category) {
    if (isset($category['catid'], $category['catname'])) {
        $problem_categories[intval($category['catid'])] = array((string) $category['catname'], !empty($category['catdisable']));
    }
}

function problem_admin_category_names($problem, $known)
{
    $names = array();
    foreach (problem_category_ids($problem) as $id) {
        $names[] = isset($known[$id]) ? $known[$id][0] : '#' . $id;
    }
    return $names;
}

function problem_admin_period($problem)
{
    $text = 'Depuis le ' . date('d/m/Y', intval($problem['problem_start']));
    if ($problem['problem_end'] !== null && $problem['problem_end'] !== '') {
        $text = 'Du ' . date('d/m/Y', intval($problem['problem_start'])) . ' au ' . date('d/m/Y', intval($problem['problem_end']));
    }
    return $text;
}

/* Fields of the form for a problem of the database */
function problem_admin_form_values($problem)
{
    $problem['start_day'] = intval($problem['problem_start']) ? date('Y-m-d', intval($problem['problem_start'])) : '';
    $problem['end_day']   = ($problem['problem_end'] !== null && $problem['problem_end'] !== '') ? date('Y-m-d', intval($problem['problem_end'])) : '';
    return $problem;
}

$empty_problem = array('problem_id' => 0, 'problem_title' => '', 'problem_description' => '', 'problem_categories' => '',
                       'problem_zone' => 'circle', 'problem_lat' => 0, 'problem_lon' => 0, 'problem_radius' => 100,
                       'problem_polygon' => '', 'problem_start' => 0, 'problem_end' => null, 'problem_priority' => 2,
                       'problem_status' => 'open', 'start_day' => date('Y-m-d'), 'end_day' => '');

$edit = null;

/* Delete: the observations are detached, never deleted */
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['problemid'])) {
    $problem = problem_load($db, $_GET['problemid']);
    if ($problem && mysqli_query($db, "DELETE FROM obs_problems WHERE problem_id = " . intval($problem['problem_id']))) {
        mysqli_query($db, "DELETE FROM obs_problem_observations WHERE po_problemid = " . intval($problem['problem_id']));
        audit_log('problem_delete', 'problem:' . intval($problem['problem_id']), array('title' => $problem['problem_title'], 'observations' => mysqli_affected_rows($db)));
        $messages[] = array('success', 'Problème <strong>' . h($problem['problem_title']) . '</strong> supprimé (ses observations sont conservées).');
    }
}

/* Create / update */
if (isset($_POST['problem_save'])) {
    list($edit, $errors) = problem_from_post($_POST, $problem_categories);
    $existing = $edit['problem_id'] > 0 ? problem_load($db, $edit['problem_id']) : null;
    if ($edit['problem_id'] > 0 && !$existing) {
        $errors[] = 'Problème introuvable.';
    }
    if ($errors) {
        $messages[] = array('danger', 'Problème non enregistré :<ul class="mb-0"><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul>');
    } else {
        $fields = array(
            'problem_title'       => "'" . mysqli_real_escape_string($db, $edit['problem_title']) . "'",
            'problem_description' => "'" . mysqli_real_escape_string($db, $edit['problem_description']) . "'",
            'problem_categories'  => "'" . mysqli_real_escape_string($db, $edit['problem_categories']) . "'",
            'problem_zone'        => "'" . ($edit['problem_zone'] == 'polygon' ? 'polygon' : 'circle') . "'",
            'problem_lat'         => sprintf('%.6F', $edit['problem_lat']),
            'problem_lon'         => sprintf('%.6F', $edit['problem_lon']),
            'problem_radius'      => intval($edit['problem_radius']),
            'problem_polygon'     => "'" . mysqli_real_escape_string($db, $edit['problem_polygon']) . "'",
            'problem_start'       => intval($edit['problem_start']),
            'problem_end'         => $edit['problem_end'] === null ? 'NULL' : intval($edit['problem_end']),
            'problem_priority'    => intval($edit['problem_priority']),
            'problem_status'      => "'" . mysqli_real_escape_string($db, $edit['problem_status']) . "'",
            'problem_updated_time'  => time(),
            'problem_updated_login' => "'" . mysqli_real_escape_string($db, $login) . "'",
        );
        $details = array('title' => $edit['problem_title'], 'categories' => $edit['problem_categories'], 'zone' => problem_zone_label($edit),
                         'period' => problem_admin_period($edit), 'priority' => $priorities[$edit['problem_priority']][0],
                         'status' => $statuses[$edit['problem_status']][0]);
        if ($existing) {
            $sets = array();
            foreach ($fields as $field => $value) {
                $sets[] = $field . ' = ' . $value;
            }
            $ok = mysqli_query($db, "UPDATE obs_problems SET " . implode(', ', $sets) . " WHERE problem_id = " . intval($edit['problem_id']));
            // Only the changed values are logged
            $before = array('title' => $existing['problem_title'], 'categories' => $existing['problem_categories'], 'zone' => problem_zone_label($existing),
                            'period' => problem_admin_period($existing),
                            'priority' => isset($priorities[intval($existing['problem_priority'])]) ? $priorities[intval($existing['problem_priority'])][0] : '',
                            'status' => isset($statuses[$existing['problem_status']]) ? $statuses[$existing['problem_status']][0] : $existing['problem_status']);
            $changes = array('title' => $edit['problem_title']);
            foreach ($details as $key => $value) {
                if ($key != 'title' && $before[$key] !== $value) {
                    $changes[$key] = array($before[$key], $value);
                }
            }
            if ($existing['problem_description'] !== $edit['problem_description']) {
                $changes['description'] = 'modifiée';
            }
            if ($existing['problem_zone'] == $edit['problem_zone'] && ($existing['problem_polygon'] !== $edit['problem_polygon']
                || abs($existing['problem_lat'] - $edit['problem_lat']) > 1e-6 || abs($existing['problem_lon'] - $edit['problem_lon']) > 1e-6)) {
                $changes['zone_moved'] = true;
            }
            audit_log('problem_edit', 'problem:' . intval($edit['problem_id']), $changes);
        } else {
            $fields['problem_created_time']  = time();
            $fields['problem_created_login'] = "'" . mysqli_real_escape_string($db, $login) . "'";
            $ok = mysqli_query($db, "INSERT INTO obs_problems (" . implode(', ', array_keys($fields)) . ") VALUES (" . implode(', ', $fields) . ")");
            $edit['problem_id'] = intval(mysqli_insert_id($db));
            audit_log('problem_create', 'problem:' . $edit['problem_id'], $details);
        }
        if ($ok) {
            $messages[] = array('success', 'Problème <strong>' . h($edit['problem_title']) . '</strong> enregistré.');
            $edit = null;
        } else {
            $messages[] = array('danger', 'Impossible d\'enregistrer le problème.');
        }
    }
}

/* Form to show */
if ($edit === null && isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $edit = $empty_problem;
    } else {
        $edit = problem_load($db, $_GET['edit']);
        $edit = $edit ? problem_admin_form_values($edit) : null;
    }
}

/* Observation matching several active problems: attached to the one chosen (#327) */
if (isset($_POST['problem_arbitrate']) && isset($_POST['obs_id'])) {
    $chosen = problem_load($db, $_POST['problem_arbitrate']);
    $query  = mysqli_query($db, "SELECT obs_token FROM obs_list WHERE obs_id = " . intval($_POST['obs_id']));
    $row    = $query ? mysqli_fetch_assoc($query) : null;
    $state  = ($chosen && $row) ? problem_attach($db, $chosen['problem_id'], $_POST['obs_id'], 'manual', $login) : 'error';
    if ($state == 'attached') {
        audit_log('problem_attach', 'problem:' . intval($chosen['problem_id']), array('title' => $chosen['problem_title'], 'token' => $row['obs_token'], 'arbitration' => true));
        $messages[] = array('success', 'Observation <strong>' . h($row['obs_token']) . '</strong> rattachée au problème <strong>' . h($chosen['problem_title']) . '</strong>.');
    } else {
        $messages[] = array('warning', 'Observation non rattachée (déjà dans un problème, ou plus publiée).');
    }
}

/* Detail of a problem: count, attribution, manual attach / detach */
$view   = isset($_GET['view']) ? problem_load($db, $_GET['view']) : null;
$count  = null;
if ($view) {
    $view_id  = intval($view['problem_id']);
    $view_url = $self_url . '&view=' . $view_id;
    $titles   = problem_titles($db);
    // Observations shown as a list of tokens, linked to the problem they belong to
    $conflict_list = function ($conflicts) use ($titles, $self_url) {
        $items = array();
        foreach ($conflicts as $observation) {
            $title   = isset($titles[$observation['problem']]) ? $titles[$observation['problem']] : '#' . $observation['problem'];
            $items[] = '<li><strong>' . h($observation['token']) . '</strong> : problème <a href="' . h($self_url . '&view=' . intval($observation['problem'])) . '">' . h($title) . '</a></li>';
        }
        return $items ? '<ul class="mb-0 mt-1">' . implode('', $items) . '</ul>' : '';
    };
    if (isset($_POST['problem_count'])) {
        $count = problem_count($db, $view);
    }
    if (isset($_POST['problem_attach_retro'])) {
        $result = problem_attach_retro($db, $view, $login);
        audit_log('problem_attach_retro', 'problem:' . $view_id, array('title' => $view['problem_title'], 'attached' => count($result['attached']),
                  'tokens' => implode(',', array_slice($result['attached'], 0, 100)), 'already' => $result['already'],
                  'conflicts' => count($result['conflicts'])));
        $messages[] = array(count($result['conflicts']) ? 'warning' : 'success', '<strong>' . count($result['attached']) . '</strong> observation(s) rattachée(s), '
            . intval($result['already']) . ' déjà rattachée(s) à ce problème'
            . (count($result['conflicts']) ? ', <strong>' . count($result['conflicts']) . '</strong> laissée(s) dans un autre problème (à arbitrer) :' . $conflict_list($result['conflicts']) : '.'));
    }
    if (isset($_POST['problem_attach_token'])) {
        $token = isset($_POST['obs_token']) && is_scalar($_POST['obs_token']) ? strtoupper(trim((string) $_POST['obs_token'])) : '';
        $query = mysqli_query($db, "SELECT obs_id FROM obs_list WHERE obs_token = '" . mysqli_real_escape_string($db, $token) . "' LIMIT 1");
        $row   = $query ? mysqli_fetch_assoc($query) : null;
        $state = $row ? problem_attach($db, $view_id, $row['obs_id'], 'manual', $login) : 'unknown';
        if ($state == 'attached') {
            audit_log('problem_attach', 'problem:' . $view_id, array('title' => $view['problem_title'], 'token' => $token));
            $messages[] = array('success', 'Observation <strong>' . h($token) . '</strong> rattachée.');
        } elseif ($state == 'conflict') {
            $other = mysqli_query($db, "SELECT po_problemid FROM obs_problem_observations WHERE po_obsid = " . intval($row['obs_id']));
            $other = $other ? mysqli_fetch_assoc($other) : null;
            $messages[] = array('warning', 'L\'observation <strong>' . h($token) . '</strong> appartient déjà à un autre problème : la détacher d\'abord de celui-ci.'
                . $conflict_list(array(array('token' => $token, 'problem' => $other ? intval($other['po_problemid']) : 0))));
        } else {
            $texts = array('already' => 'est déjà rattachée à ce problème', 'unpublished' => 'n\'est pas publiée (seules les observations publiées peuvent être rattachées)',
                           'unknown' => 'est introuvable', 'error' => 'n\'a pas pu être rattachée');
            $messages[] = array($state == 'already' ? 'info' : 'danger', 'L\'observation <strong>' . h($token) . '</strong> ' . $texts[$state] . '.');
        }
    }
    if (isset($_GET['action']) && $_GET['action'] == 'detach' && isset($_GET['obsid'])) {
        $query = mysqli_query($db, "SELECT obs_token FROM obs_list WHERE obs_id = " . intval($_GET['obsid']));
        $row   = $query ? mysqli_fetch_assoc($query) : null;
        if (problem_detach($db, $view_id, $_GET['obsid'])) {
            audit_log('problem_detach', 'problem:' . $view_id, array('title' => $view['problem_title'], 'token' => $row ? $row['obs_token'] : intval($_GET['obsid'])));
            $messages[] = array('success', 'Observation <strong>' . h($row ? $row['obs_token'] : '') . '</strong> détachée du problème.');
        }
    }
}

/* Filters of the list */
$f_q        = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$f_status   = isset($_GET['status']) && is_scalar($_GET['status']) && ($_GET['status'] === 'active' || isset($statuses[$_GET['status']])) ? (string) $_GET['status'] : '';
$f_priority = isset($_GET['priority']) && is_scalar($_GET['priority']) && isset($priorities[intval($_GET['priority'])]) ? intval($_GET['priority']) : 0;
$f_category = isset($_GET['category']) && is_scalar($_GET['category']) && isset($problem_categories[intval($_GET['category'])]) ? intval($_GET['category']) : 0;

$where = array('1');
if ($f_q !== '') {
    $like    = mysqli_real_escape_string($db, addcslashes($f_q, '%_\\'));
    $where[] = "(problem_title LIKE '%" . $like . "%' OR problem_description LIKE '%" . $like . "%')";
}
if ($f_status === 'active') {
    $where[] = "problem_status IN ('" . implode("','", problem_active_statuses()) . "')";
} elseif ($f_status !== '') {
    $where[] = "problem_status = '" . mysqli_real_escape_string($db, $f_status) . "'";
}
if ($f_priority) {
    $where[] = "problem_priority = " . intval($f_priority);
}
if ($f_category) {
    $where[] = "FIND_IN_SET(" . intval($f_category) . ", problem_categories)";
}
$problems = array();
$query    = mysqli_query($db, "SELECT * FROM obs_problems WHERE " . implode(' AND ', $where) . " ORDER BY problem_priority DESC, problem_updated_time DESC, problem_id DESC");
while ($query && ($row = mysqli_fetch_assoc($query))) {
    $problems[] = $row;
}
$counts   = problem_observation_counts($db);
$filtered = $f_q !== '' || $f_status !== '' || $f_priority || $f_category;

/* Initial view of the map of a new problem: center of the first scope */
$map_center = '46.6,2.4';
$map_zoom   = 5;
$query = mysqli_query($db, "SELECT scope_map_center_string, scope_map_zoom FROM obs_scopes ORDER BY scope_id LIMIT 1");
if ($query && ($row = mysqli_fetch_assoc($query)) && preg_match('/^\s*(-?[0-9.]+)\s*,\s*(-?[0-9.]+)\s*$/', (string) $row['scope_map_center_string'], $m)) {
    $map_center = floatval($m[1]) . ',' . floatval($m[2]);
    $map_zoom   = max(3, min(18, intval($row['scope_map_zoom']) ?: 13));
}

foreach ($messages as $message) {
    // Messages are built above from escaped / integer values only
    echo '<div class="alert alert-' . h($message[0]) . '" role="alert">' . $message[1] . '</div>';
}

if ($edit !== null) {
    $selected = problem_category_ids($edit);
    $polygon  = $edit['problem_zone'] == 'polygon' ? (string) $edit['problem_polygon'] : '';
?>
<form class="card shadow-sm mb-4" method="POST" action="<?= h($self_url) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="problem_id" value="<?= intval($edit['problem_id']) ?>">
  <div class="card-header fw-semibold"><?= $edit['problem_id'] ? 'Modifier le problème' : 'Nouveau problème' ?></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label" for="problem_title">Titre</label>
        <input class="form-control" id="problem_title" name="problem_title" maxlength="200" required value="<?= h($edit['problem_title']) ?>" placeholder="Stationnement sur la piste cyclable de l'avenue…">
      </div>
      <div class="col-12">
        <label class="form-label" for="problem_description">Description</label>
        <textarea class="form-control" id="problem_description" name="problem_description" rows="3" maxlength="10000"><?= h($edit['problem_description']) ?></textarea>
      </div>
      <div class="col-md-6">
        <fieldset>
          <legend class="form-label fs-6 mb-1">Catégories <span class="text-body-secondary small">(au moins une)</span></legend>
          <div class="border rounded px-2 py-1 overflow-auto" style="max-height: 12rem;">
          <?php foreach ($problem_categories as $id => $category) {
              $checked = in_array($id, $selected, true);
              // Disabled categories: only listed when already chosen
              if ($category[1] && !$checked) { continue; } ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="problem_categories[]" id="problem_categories_<?= intval($id) ?>" value="<?= intval($id) ?>"<?= $checked ? ' checked' : '' ?>>
              <label class="form-check-label" for="problem_categories_<?= intval($id) ?>"><?= h($category[0]) ?><?= $category[1] ? ' <span class="text-body-secondary">(désactivée)</span>' : '' ?></label>
            </div>
          <?php } ?>
          </div>
          <div class="form-text">Une observation de l'une des catégories cochées peut être rattachée.</div>
        </fieldset>
      </div>
      <div class="col-md-6">
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label" for="problem_start">Début</label>
            <input class="form-control" type="date" id="problem_start" name="problem_start" required value="<?= h($edit['start_day']) ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="problem_end">Fin <span class="text-body-secondary small">(facultative)</span></label>
            <input class="form-control" type="date" id="problem_end" name="problem_end" value="<?= h($edit['end_day']) ?>">
          </div>
          <div class="col-12 form-text mt-1">Période sur la date des observations.</div>
          <div class="col-6">
            <label class="form-label" for="problem_priority">Priorité</label>
            <select class="form-select" id="problem_priority" name="problem_priority">
              <?php foreach ($priorities as $key => $priority) { ?><option value="<?= intval($key) ?>"<?= intval($edit['problem_priority']) === $key ? ' selected' : '' ?>><?= h($priority[0]) ?></option><?php } ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label" for="problem_status">Statut</label>
            <select class="form-select" id="problem_status" name="problem_status">
              <?php foreach ($statuses as $key => $status) { ?><option value="<?= h($key) ?>"<?= $edit['problem_status'] === $key ? ' selected' : '' ?>><?= h($status[0]) ?></option><?php } ?>
            </select>
          </div>
        </div>
      </div>
      <div class="col-12 problem-map-block">
        <fieldset>
          <legend class="form-label fs-6 mb-1">Zone</legend>
          <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
            <div class="form-check form-check-inline me-0">
              <input class="form-check-input" type="radio" name="problem_zone" id="problem_zone_circle" value="circle"<?= $edit['problem_zone'] != 'polygon' ? ' checked' : '' ?>>
              <label class="form-check-label" for="problem_zone_circle">Cercle</label>
            </div>
            <div class="form-check form-check-inline me-0">
              <input class="form-check-input" type="radio" name="problem_zone" id="problem_zone_polygon" value="polygon"<?= $edit['problem_zone'] == 'polygon' ? ' checked' : '' ?>>
              <label class="form-check-label" for="problem_zone_polygon">Polygone</label>
            </div>
            <div class="input-group input-group-sm" style="max-width: 20rem;">
              <input type="search" class="form-control" placeholder="Aller à une ville, un lieu…" data-map-search aria-label="Rechercher un lieu">
              <button class="btn btn-outline-secondary" type="button" data-map-action="search" aria-label="Rechercher"><i class="bi bi-search"></i></button>
            </div>
            <span data-zone-tools="polygon">
              <button class="btn btn-sm btn-outline-secondary" type="button" data-map-action="undo"><i class="bi bi-arrow-counterclockwise"></i> Retirer le dernier point</button>
              <button class="btn btn-sm btn-outline-danger" type="button" data-map-action="clear"><i class="bi bi-x-lg"></i> Effacer</button>
            </span>
          </div>
          <div class="problem-map rounded border" data-problem-map data-center="<?= h($map_center) ?>" data-zoom="<?= intval($map_zoom) ?>" style="height: 380px;"></div>
          <div class="form-text" data-map-status aria-live="polite"></div>
          <div class="form-text" data-zone-help="circle">Cliquer sur la carte pour placer le centre du cercle (marqueur déplaçable), puis régler le rayon.</div>
          <div class="form-text" data-zone-help="polygon">Cliquer sur la carte pour ajouter les points du polygone (3 au moins).</div>
          <div class="row g-3 mt-0" data-zone-tools="circle">
            <div class="col-6 col-md-3">
              <label class="form-label" for="problem_lat">Latitude du centre</label>
              <input class="form-control" id="problem_lat" name="problem_lat" inputmode="decimal" value="<?= $edit['problem_zone'] != 'polygon' && floatval($edit['problem_lat']) ? h($edit['problem_lat']) : '' ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="problem_lon">Longitude du centre</label>
              <input class="form-control" id="problem_lon" name="problem_lon" inputmode="decimal" value="<?= $edit['problem_zone'] != 'polygon' && floatval($edit['problem_lon']) ? h($edit['problem_lon']) : '' ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="problem_radius">Rayon (m)</label>
              <input class="form-control" type="number" id="problem_radius" name="problem_radius" min="<?= VIGILO_PROBLEM_RADIUS_MIN ?>" max="<?= VIGILO_PROBLEM_RADIUS_MAX ?>" step="1" value="<?= intval($edit['problem_radius']) ?: 100 ?>">
            </div>
          </div>
          <input type="hidden" id="problem_polygon" name="problem_polygon" value="<?= h($polygon) ?>">
        </fieldset>
      </div>
    </div>
  </div>
  <div class="card-footer d-flex flex-wrap gap-2 justify-content-end">
    <a class="btn btn-outline-secondary" href="<?= h($self_url) ?>">Annuler</a>
    <button class="btn btn-primary" type="submit" name="problem_save" value="1"><i class="bi bi-check-lg"></i> Enregistrer</button>
  </div>
</form>
<link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="js/problem-map.js?v=<?= intval(@filemtime(__DIR__ . '/../js/problem-map.js')) ?>"></script>
<?php } ?>

<?php if ($view && $edit === null) {
    $attached = problem_observations($db, $view_id);
    $priority = isset($priorities[intval($view['problem_priority'])]) ? $priorities[intval($view['problem_priority'])] : array('?', 'secondary');
    $status   = isset($statuses[$view['problem_status']]) ? $statuses[$view['problem_status']] : array($view['problem_status'], 'secondary');
    $modes    = array('retro' => 'Rétroactif', 'auto' => 'Automatique', 'manual' => 'Manuel');
    // Map: zone, attached observations, and those of the count
    $points = array();
    foreach ($attached as $row) {
        $points[] = array('lat' => (float) $row['obs_coordinates_lat'], 'lon' => (float) $row['obs_coordinates_lon'], 'token' => $row['obs_token'], 'kind' => 'attached');
    }
    if ($count) {
        foreach (array('to_attach' => 'to_attach', 'conflicts' => 'conflict') as $key => $kind) {
            foreach ($count[$key] as $observation) {
                $points[] = array('lat' => $observation['lat'], 'lon' => $observation['lon'], 'token' => $observation['token'], 'kind' => $kind);
            }
        }
    }
    $map_data = array('zone' => $view['problem_zone'], 'lat' => (float) $view['problem_lat'], 'lon' => (float) $view['problem_lon'],
                      'radius' => intval($view['problem_radius']), 'polygon' => problem_polygon_points($view['problem_polygon']), 'points' => $points);
?>
<p><a href="<?= h($self_url) ?>"><i class="bi bi-arrow-left"></i> Tous les problèmes</a></p>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0 text-break"><?= h($view['problem_title']) ?></h2>
    <div class="d-flex gap-2 align-items-center">
      <span class="badge text-bg-<?= h($priority[1]) ?>"><?= h($priority[0]) ?></span>
      <span class="badge text-bg-<?= h($status[1]) ?>"><?= h($status[0]) ?></span>
      <a class="btn btn-sm btn-outline-primary" href="<?= h($self_url) ?>&amp;edit=<?= $view_id ?>"><i class="bi bi-pencil"></i> Modifier</a>
    </div>
  </div>
  <div class="card-body">
    <?php if ($view['problem_description'] !== '') { ?><p class="text-break" style="white-space: pre-line;"><?= h($view['problem_description']) ?></p><?php } ?>
    <dl class="row small mb-3">
      <dt class="col-sm-3">Catégories</dt><dd class="col-sm-9"><?= h(implode(', ', problem_admin_category_names($view, $problem_categories))) ?></dd>
      <dt class="col-sm-3">Zone</dt><dd class="col-sm-9"><?= h(problem_zone_label($view)) ?></dd>
      <dt class="col-sm-3">Période</dt><dd class="col-sm-9"><?= h(problem_admin_period($view)) ?></dd>
      <dt class="col-sm-3">Création</dt><dd class="col-sm-9"><?= h(date('d/m/Y H:i', intval($view['problem_created_time']))) ?> par <?= h($view['problem_created_login']) ?></dd>
    </dl>
    <div class="problem-view-map rounded border" data-problem-view="<?= h(json_encode($map_data)) ?>" style="height: 320px;"></div>
    <div class="form-text">
      <span class="text-success"><i class="bi bi-circle-fill"></i> rattachées</span>
      <?php if ($count) { ?> · <span class="text-primary"><i class="bi bi-circle-fill"></i> à rattacher</span> · <span><i class="bi bi-circle-fill" style="color: #fd7e14;"></i> dans un autre problème</span><?php } ?>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">Rattachement des observations existantes</div>
  <div class="card-body">
    <p class="small text-body-secondary">
      Les observations publiées de la zone, des catégories et de la période du problème. <strong>Compter</strong> ne modifie
      rien ; <strong>Rattacher</strong> refait le calcul et rattache celles qui n'appartiennent à aucun problème. Une
      observation déjà dans un autre problème n'est jamais déplacée : elle est signalée, à arbitrer.
    </p>
    <form method="POST" action="<?= h($view_url) ?>" class="d-flex flex-wrap gap-2">
      <?= csrf_field() ?>
      <button class="btn btn-outline-primary" type="submit" name="problem_count" value="1"><i class="bi bi-calculator"></i> Compter</button>
      <?php if ($count && count($count['to_attach'])) { ?>
      <button class="btn btn-primary" type="submit" name="problem_attach_retro" value="1" data-confirm="Rattacher <?= count($count['to_attach']) ?> observation(s) au problème « <?= h($view['problem_title']) ?> » ?"><i class="bi bi-link-45deg"></i> Rattacher <?= count($count['to_attach']) ?> observation(s)</button>
      <?php } ?>
    </form>
    <?php if ($count) { ?>
    <ul class="mt-3 mb-0" data-problem-count>
      <li><strong><?= intval($count['matching']) ?></strong> observation(s) publiée(s) correspondent au problème ;</li>
      <li><strong><?= count($count['here']) ?></strong> déjà rattachée(s) à ce problème ;</li>
      <li><strong><?= count($count['to_attach']) ?></strong> à rattacher<?= count($count['to_attach']) ? ' : ' . h(implode(', ', array_map(function ($o) { return $o['token']; }, array_slice($count['to_attach'], 0, 50)))) . (count($count['to_attach']) > 50 ? '…' : '') : '' ?> ;</li>
      <li><strong><?= count($count['conflicts']) ?></strong> dans un autre problème (non déplacées)<?= count($count['conflicts']) ? ' :' . $conflict_list($count['conflicts']) : '.' ?></li>
    </ul>
    <?php } ?>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="fw-semibold">Observations rattachées (<?= count($attached) ?>)</span>
    <form method="POST" action="<?= h($view_url) ?>" class="d-flex gap-2">
      <?= csrf_field() ?>
      <label class="visually-hidden" for="obs_token">Token de l'observation</label>
      <input class="form-control form-control-sm" id="obs_token" name="obs_token" placeholder="Token" maxlength="20" required style="max-width: 9rem;">
      <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit" name="problem_attach_token" value="1"><i class="bi bi-plus-lg"></i> Rattacher</button>
    </form>
  </div>
  <?php if (empty($attached)) { ?>
    <div class="card-body"><p class="text-body-secondary mb-0">Aucune observation rattachée.</p></div>
  <?php } else { ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin table-stack-md mb-0">
      <thead><tr><th scope="col">Photo</th><th scope="col">Observation</th><th scope="col">Catégorie</th><th scope="col">Rattachement</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($attached as $row) {
          $token = (string) $row['obs_token'];
          $catid = intval($row['obs_categorie']); ?>
        <tr>
          <td><a href="<?= h('photo.php?s=800&token=' . urlencode($token)) ?>" data-photo data-photo-title="Observation <?= h($token) ?>"><img class="obs-thumb" loading="lazy" alt="Observation <?= h($token) ?>" src="<?= h('photo.php?s=200&token=' . urlencode($token)) ?>"></a></td>
          <td>
            <a class="fw-semibold" href="?page=observations&amp;approved=<?= intval($row['obs_approved']) ?>&amp;filtertype=uniq&amp;filtertoken=<?= h(urlencode($token)) ?>&amp;searcharchived=all"><?= h($token) ?></a>
            <?php if (intval($row['obs_approved']) != 1) { ?><span class="badge text-bg-warning">Plus publiée</span><?php } ?>
            <?php if (intval($row['obs_archived'])) { ?><span class="badge text-bg-secondary">Archivée</span><?php } ?>
            <div class="small text-body-secondary"><?= h(date('d/m/Y H:i', intval($row['obs_time']))) ?> · <?= h($row['obs_address_string']) ?></div>
            <?php if ($row['obs_comment'] !== '') { ?><div class="small text-break"><?= h($row['obs_comment']) ?></div><?php } ?>
          </td>
          <td class="small" data-label="Catégorie"><?= h(isset($problem_categories[$catid]) ? $problem_categories[$catid][0] : '#' . $catid) ?></td>
          <td class="small" data-label="Rattachement"><?= h(isset($modes[$row['po_mode']]) ? $modes[$row['po_mode']] : $row['po_mode']) ?>, <?= h(date('d/m/Y', intval($row['po_time']))) ?><?= $row['po_login'] !== '' ? ' (' . h($row['po_login']) . ')' : '' ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-danger text-nowrap" href="<?= h($view_url) ?>&amp;action=detach&amp;obsid=<?= intval($row['obs_id']) ?><?= h(csrf_query()) ?>" data-confirm="Détacher l'observation <?= h($token) ?> du problème ?"><i class="bi bi-x-lg"></i> Détacher</a></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>
</div>
<link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="js/problem-map.js?v=<?= intval(@filemtime(__DIR__ . '/../js/problem-map.js')) ?>"></script>
<?php } else { ?>
<?php
$ambiguous = $edit === null ? problems_ambiguous($db) : array();
if ($ambiguous) {
    $titles = problem_titles($db); ?>
<div class="card shadow-sm mb-4 border-warning" id="arbitrate">
  <div class="card-header fw-semibold"><i class="bi bi-signpost-split"></i> À arbitrer (<?= count($ambiguous) ?>)</div>
  <div class="card-body pb-0">
    <p class="small text-body-secondary">
      Observations publiées qui correspondent à plusieurs problèmes actifs : elles ne sont pas rattachées
      automatiquement. Choisir leur problème.
    </p>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($ambiguous as $observation) { ?>
    <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
      <a class="fw-semibold" href="?page=observations&amp;approved=1&amp;filtertype=uniq&amp;filtertoken=<?= h(urlencode($observation['token'])) ?>&amp;searcharchived=all"><?= h($observation['token']) ?></a>
      <span class="small text-body-secondary me-auto"><?= h(date('d/m/Y', $observation['time'])) ?> · <?= h($observation['address']) ?></span>
      <form method="POST" action="<?= h($self_url) ?>" class="d-flex flex-wrap gap-1">
        <?= csrf_field() ?>
        <input type="hidden" name="obs_id" value="<?= intval($observation['id']) ?>">
        <?php foreach ($observation['problems'] as $problem_id) { ?>
        <button class="btn btn-sm btn-outline-primary" type="submit" name="problem_arbitrate" value="<?= intval($problem_id) ?>"><i class="bi bi-link-45deg"></i> <?= h(isset($titles[$problem_id]) ? $titles[$problem_id] : '#' . $problem_id) ?></button>
        <?php } ?>
      </form>
    </li>
    <?php } ?>
  </ul>
</div>
<?php } ?>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-diagram-3"></i> Problèmes</h2>
    <a class="btn btn-sm btn-primary" href="<?= h($self_url) ?>&amp;edit=new"><i class="bi bi-plus-lg"></i> Nouveau problème</a>
  </div>
  <div class="card-body pb-0">
    <p class="small text-body-secondary">
      Un problème regroupe les observations publiées d'une zone (cercle ou polygone), de catégories et d'une période.
      À sa publication, une observation est rattachée automatiquement au problème actif (ouvert, en cours, en attente)
      qui lui correspond, s'il n'y en a qu'un. Les problèmes ne sont visibles que dans l'admin.
    </p>
    <form class="row g-2 align-items-end mb-3" method="GET" action="">
      <input type="hidden" name="page" value="<?= h($page_name) ?>">
      <div class="col-12 col-sm">
        <label class="form-label small mb-1" for="f_q">Recherche</label>
        <input class="form-control form-control-sm" type="search" id="f_q" name="q" value="<?= h($f_q) ?>" placeholder="Titre, description">
      </div>
      <div class="col-6 col-sm-auto">
        <label class="form-label small mb-1" for="f_status">Statut</label>
        <select class="form-select form-select-sm" id="f_status" name="status">
          <option value="">Tous</option>
          <option value="active"<?= $f_status === 'active' ? ' selected' : '' ?>>Actifs (ouvert, en cours, en attente)</option>
          <?php foreach ($statuses as $key => $status) { ?><option value="<?= h($key) ?>"<?= $f_status === $key ? ' selected' : '' ?>><?= h($status[0]) ?></option><?php } ?>
        </select>
      </div>
      <div class="col-6 col-sm-auto">
        <label class="form-label small mb-1" for="f_priority">Priorité</label>
        <select class="form-select form-select-sm" id="f_priority" name="priority">
          <option value="">Toutes</option>
          <?php foreach ($priorities as $key => $priority) { ?><option value="<?= intval($key) ?>"<?= $f_priority === $key ? ' selected' : '' ?>><?= h($priority[0]) ?></option><?php } ?>
        </select>
      </div>
      <div class="col-12 col-sm-auto">
        <label class="form-label small mb-1" for="f_category">Catégorie</label>
        <select class="form-select form-select-sm" id="f_category" name="category">
          <option value="">Toutes</option>
          <?php foreach ($problem_categories as $id => $category) { ?><option value="<?= intval($id) ?>"<?= $f_category === $id ? ' selected' : '' ?>><?= h($category[0]) ?></option><?php } ?>
        </select>
      </div>
      <div class="col-12 col-sm-auto d-flex gap-2">
        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtrer</button>
        <?php if ($filtered) { ?><a class="btn btn-outline-secondary btn-sm" href="<?= h($self_url) ?>"><i class="bi bi-x-lg"></i> Réinitialiser</a><?php } ?>
      </div>
    </form>
  </div>
  <?php if (empty($problems)) { ?>
    <div class="card-body pt-0"><p class="text-body-secondary mb-0"><?= $filtered ? 'Aucun problème ne correspond aux filtres.' : 'Aucun problème.' ?></p></div>
  <?php } else { ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin table-stack-md mb-0">
      <thead>
        <tr><th scope="col">Problème</th><th scope="col">Catégories</th><th scope="col">Zone et période</th><th scope="col">Priorité</th><th scope="col">Statut</th><th scope="col">Observations</th><th scope="col"></th></tr>
      </thead>
      <tbody>
      <?php foreach ($problems as $problem) {
        $id       = intval($problem['problem_id']);
        $priority = isset($priorities[intval($problem['problem_priority'])]) ? $priorities[intval($problem['problem_priority'])] : array('?', 'secondary');
        $status   = isset($statuses[$problem['problem_status']]) ? $statuses[$problem['problem_status']] : array($problem['problem_status'], 'secondary'); ?>
        <tr>
          <td>
            <a class="fw-semibold" href="<?= h($self_url) ?>&amp;view=<?= $id ?>"><?= h($problem['problem_title']) ?></a>
            <?php if ($problem['problem_description'] !== '') { ?><div class="small text-body-secondary text-break"><?= h(vigilo_truncate($problem['problem_description'], 160)) ?></div><?php } ?>
          </td>
          <td class="small" data-label="Catégories"><?= h(implode(', ', problem_admin_category_names($problem, $problem_categories))) ?></td>
          <td class="small"><?= h(problem_zone_label($problem)) ?><br><span class="text-body-secondary"><?= h(problem_admin_period($problem)) ?></span></td>
          <td><span class="badge text-bg-<?= h($priority[1]) ?>"><?= h($priority[0]) ?></span></td>
          <td><span class="badge text-bg-<?= h($status[1]) ?>"><?= h($status[0]) ?></span></td>
          <td data-label="Observations"><?= isset($counts[$id]) ? intval($counts[$id]) : 0 ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= h($self_url) ?>&amp;edit=<?= $id ?>"><i class="bi bi-pencil"></i> Modifier</a>
            <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;problemid=<?= $id ?><?= h(csrf_query()) ?>" data-confirm="Supprimer le problème « <?= h($problem['problem_title']) ?> » ? Ses observations sont conservées." aria-label="Supprimer"><i class="bi bi-trash"></i></a>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>
</div>
<?php } ?>
