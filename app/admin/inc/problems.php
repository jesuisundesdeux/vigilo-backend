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

<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-diagram-3"></i> Problèmes</h2>
    <a class="btn btn-sm btn-primary" href="<?= h($self_url) ?>&amp;edit=new"><i class="bi bi-plus-lg"></i> Nouveau problème</a>
  </div>
  <div class="card-body pb-0">
    <p class="small text-body-secondary">
      Un problème regroupe les observations publiées d'une zone (cercle ou polygone), de catégories et d'une période.
      Les problèmes ne sont visibles que dans l'admin.
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
            <div class="fw-semibold"><?= h($problem['problem_title']) ?></div>
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
