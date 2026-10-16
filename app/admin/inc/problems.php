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
require_once(__DIR__ . '/../../includes/report.php');

$messages   = array();
$self_url   = '?page=' . urlencode($page_name);
$statuses   = problem_statuses();
$priorities = problem_priorities();
$login      = isset($_SESSION['login']) ? (string) $_SESSION['login'] : '';

/*
 * Citystaff accounts: read only, limited to the problems having observations in their
 * cities (and to these observations). No change, no notes, no suggestions.
 */
$readonly     = $_SESSION['role'] !== 'admin';
$staff_cities = $readonly ? getRoleCityIds($db, $login) : null;
if ($readonly) {
    $_POST = array();
    unset($_GET['action'], $_GET['edit']);
}

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
    return $names ? $names : array('Toutes les catégories');
}

function problem_admin_period($problem)
{
    return problem_period_text($problem);
}

/* Fields of the form for a problem of the database */
function problem_admin_form_values($problem)
{
    $problem['start_day'] = intval($problem['problem_start']) ? date('Y-m-d', intval($problem['problem_start'])) : '';
    $problem['end_day']   = ($problem['problem_end'] !== null && $problem['problem_end'] !== '') ? date('Y-m-d', intval($problem['problem_end'])) : '';
    return $problem;
}

/*
 * Observations shown on the map of the form (by token, published only): those of a
 * suggestion, or those attached to the problem. List of array(lat, lon, token, address, date, photo, kind).
 */
function problem_admin_reference_points($db, $tokens, $problem_id)
{
    $points = array();
    if ($problem_id > 0) {
        foreach (problem_observations($db, $problem_id) as $row) {
            $points[] = array('lat' => (float) $row['obs_coordinates_lat'], 'lon' => (float) $row['obs_coordinates_lon'],
                              'token' => (string) $row['obs_token'], 'address' => problem_full_address($row['obs_address_string'], $row['city_label']),
                              'date' => report_date(intval($row['obs_time'])), 'photo' => report_photo_src($row['obs_token'], 240), 'kind' => 'attached');
        }
        return $points;
    }
    $list = array();
    foreach (array_slice($tokens, 0, 200) as $token) {
        if (is_string($token) && preg_match('/^[A-Za-z0-9]{1,32}$/', $token)) {
            $list[] = "'" . mysqli_real_escape_string($db, $token) . "'";
        }
    }
    if (!$list) {
        return $points;
    }
    $query = mysqli_query($db, "SELECT obs_list.obs_token, obs_list.obs_coordinates_lat, obs_list.obs_coordinates_lon, obs_list.obs_address_string,
                                       obs_list.obs_time, " . PROBLEM_CITY_SQL . " AS city_label
                                FROM obs_list " . PROBLEM_CITY_JOIN . "
                                WHERE obs_list.obs_token IN (" . implode(',', $list) . ") AND obs_list.obs_complete = 1 AND obs_list.obs_approved = 1");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $points[] = array('lat' => (float) $row['obs_coordinates_lat'], 'lon' => (float) $row['obs_coordinates_lon'],
                          'token' => (string) $row['obs_token'], 'address' => problem_full_address($row['obs_address_string'], $row['city_label']),
                          'date' => report_date(intval($row['obs_time'])), 'photo' => report_photo_src($row['obs_token'], 240), 'kind' => 'suggestion');
    }
    return $points;
}

/* Streets of the published observations ("Rue X, Ville" => number of observations), for the form */
function problem_admin_known_streets($db)
{
    $streets = array();
    $query   = mysqli_query($db, "SELECT obs_list.obs_address_string, " . PROBLEM_CITY_SQL . " AS city_label, COUNT(*) AS nb
                                  FROM obs_list " . PROBLEM_CITY_JOIN . "
                                  WHERE obs_list.obs_complete = 1 AND obs_list.obs_approved = 1 AND obs_list.obs_address_string <> ''
                                  GROUP BY obs_list.obs_address_string, city_label ORDER BY nb DESC LIMIT 5000");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        list($street, $city) = problem_address_parts(problem_full_address($row['obs_address_string'], $row['city_label']));
        $street = vigilo_street_name($street);
        $key    = problem_street_key($street) . '|' . problem_street_key($city);
        if (problem_street_key($street) === '') {
            continue;
        }
        if (!isset($streets[$key])) {
            $streets[$key] = array(problem_street_text(array('street' => $street, 'city' => $city)), 0);
        }
        $streets[$key][1] += intval($row['nb']);
    }
    usort($streets, function ($a, $b) {
        return $b[1] - $a[1];
    });
    $known = array();
    foreach (array_slice($streets, 0, 1000) as $street) {
        $known[$street[0]] = $street[1];
    }
    return $known;
}

/* Streets of the form: those of the problem, or of a suggestion (texts "Rue X, Ville") */
function problem_admin_street_texts($problem)
{
    return array_map('problem_street_text', problem_streets_list(isset($problem['problem_streets']) ? $problem['problem_streets'] : ''));
}

/*
 * Observations of a resolved problem not resolved yet (published ones only): 'free' (in no
 * resolution: obs_id => token), 'resolutions' (not resolved resolutions holding some of them:
 * resolution_id => token, status, time, number of observations of the problem and of others),
 * 'resolved' (already in a resolved resolution)
 */
function problem_admin_resolution_plan($db, $problem_id)
{
    $plan  = array('free' => array(), 'resolutions' => array(), 'resolved' => 0);
    $obs   = array();
    $query = mysqli_query($db, "SELECT obs_list.obs_id, obs_list.obs_token, obs_resolutions.resolution_id, obs_resolutions.resolution_token,
                                       obs_resolutions.resolution_status, obs_resolutions.resolution_time
                                FROM obs_problem_observations
                                INNER JOIN obs_list ON obs_list.obs_id = obs_problem_observations.po_obsid
                                LEFT JOIN obs_resolutions_tokens ON obs_resolutions_tokens.restok_observationid = obs_list.obs_id
                                LEFT JOIN obs_resolutions ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid
                                WHERE obs_problem_observations.po_problemid = " . intval($problem_id) . " AND obs_list.obs_approved = 1");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $id = intval($row['obs_id']);
        if (!isset($obs[$id])) {
            $obs[$id] = array('token' => (string) $row['obs_token'], 'resolutions' => array(), 'resolved' => false);
        }
        if ($row['resolution_id'] !== null) {
            $obs[$id]['resolved'] = $obs[$id]['resolved'] || intval($row['resolution_status']) == 1;
            $obs[$id]['resolutions'][intval($row['resolution_id'])] = $row;
        }
    }
    foreach ($obs as $id => $item) {
        if ($item['resolved']) {
            $plan['resolved']++;
        } elseif (!$item['resolutions']) {
            $plan['free'][$id] = $item['token'];
        } else {
            foreach ($item['resolutions'] as $rid => $row) {
                if (!isset($plan['resolutions'][$rid])) {
                    $plan['resolutions'][$rid] = array('token' => (string) $row['resolution_token'], 'status' => intval($row['resolution_status']),
                                                       'time' => intval($row['resolution_time']), 'here' => 0, 'others' => 0);
                }
                $plan['resolutions'][$rid]['here']++;
            }
        }
    }
    if ($plan['resolutions']) {
        $query = mysqli_query($db, "SELECT restok_resolutionid, COUNT(*) AS nb FROM obs_resolutions_tokens
                                    WHERE restok_resolutionid IN (" . implode(',', array_keys($plan['resolutions'])) . ") GROUP BY restok_resolutionid");
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            $rid = intval($row['restok_resolutionid']);
            $plan['resolutions'][$rid]['others'] = max(0, intval($row['nb']) - $plan['resolutions'][$rid]['here']);
        }
    }
    return $plan;
}

/*
 * Observations of a resolved problem set as resolved: those in no resolution are grouped in a
 * new resolution (status resolved, dated now), the resolutions holding the others are set to
 * resolved (dated now if they had no date). Same functions, journal and webhooks as the pages
 * Observations and Résolutions. Returns array(new resolution token or '', updated tokens, failures).
 */
function problem_admin_resolve_observations($db, $problem, $plan, &$messages)
{
    $now     = time();
    $created = '';
    $updated = array();
    $failed  = array();
    $webhook_failure = function ($deliveries, $label) use (&$messages) {
        foreach ($deliveries as $hook_id => $delivery) {
            if ($delivery[1] !== '') {
                $messages[] = array('warning', 'Webhook <strong>#' . intval($hook_id) . '</strong> en échec pour ' . h($label) . ' : ' . h($delivery[1]) . ' (voir la page Webhooks).');
            }
        }
    };
    if ($plan['free']) {
        $token  = 'R_' . tokenGenerator(4);
        $fields = array('resolution_token' => $token, 'resolution_secretid' => str_replace('.', '', uniqid('', true)),
                        'resolution_app_version' => 'admin',
                        'resolution_comment' => mysqli_real_escape_string($db, vigilo_truncate('Problème résolu : ' . $problem['problem_title'], 255)),
                        'resolution_time' => $now, 'resolution_status' => 1);
        if (addResolution($fields, array_keys($plan['free']))) {
            $created = $token;
            $rid     = getResolutionIdByResolutionToken($token);
            flushImagesCacheResolution($rid);
            audit_log('resolution_create', $token, array('obs_tokens' => array_values($plan['free']), 'problem' => intval($problem['problem_id'])));
            $webhook_failure(webhooks_for_resolution($db, VIGILO_WEBHOOK_EVENT_RESOLUTION_CREATED, $rid), 'la résolution ' . $token);
        }
    }
    foreach ($plan['resolutions'] as $rid => $resolution) {
        try {
            if ($resolution['time'] == 0) {
                updateResolution(array('resolution_time' => $now), $rid);
            }
            updateResolution(array('resolution_status' => 1), $rid);
            $updated[] = $resolution['token'];
            audit_log('resolution_status', 'resolution:' . intval($rid), 'new_status:1');
            $webhook_failure(webhooks_on_resolution_status($db, $rid, $resolution['status'], 1), 'la résolution ' . $resolution['token']);
        } catch (Exception $e) {
            $failed[] = $resolution['token'] . ' (' . $e->getMessage() . ')';
        }
    }
    audit_log('problem_resolve_observations', 'problem:' . intval($problem['problem_id']),
              array('created' => $created, 'observations' => count($plan['free']), 'resolutions' => $updated, 'failed' => $failed));
    return array($created, $updated, $failed);
}

/* Colors of the statuses on the maps (same as the badges) */
/* Observations of other problems as a list of tokens, linked to their problem */
function problem_admin_conflict_list($conflicts, $titles, $self_url)
{
    $items = array();
    foreach ($conflicts as $observation) {
        $title   = isset($titles[$observation['problem']]) ? $titles[$observation['problem']] : '#' . $observation['problem'];
        $items[] = '<li><strong>' . h($observation['token']) . '</strong> : problème <a href="' . h($self_url . '&view=' . intval($observation['problem'])) . '">' . h($title) . '</a></li>';
    }
    return $items ? '<ul class="mb-0 mt-1">' . implode('', $items) . '</ul>' : '';
}

/*
 * Attachment of the existing observations ("Compter" / "Rattacher"), posted to the detail
 * of the problem: on the detail ($count on request) and in the window shown after a creation
 * ($count computed at once). $id_prefix keeps the ids of the two places apart.
 */
function problem_admin_attach_panel($problem, $count, $include_archived, $titles, $self_url, $id_prefix)
{
    $view_url = $self_url . '&view=' . intval($problem['problem_id']);
    ob_start(); ?>
    <p class="small text-body-secondary">
      Les observations publiées de la zone, des catégories et de la période du problème (sans les observations archivées, sauf
      si la case est cochée). <strong>Compter</strong> ne modifie
      rien ; <strong>Rattacher</strong> refait le calcul et rattache celles qui n'appartiennent à aucun problème. Une
      observation déjà dans un autre problème n'est jamais déplacée : elle est signalée, à arbitrer.
    </p>
    <form method="POST" action="<?= h($view_url) ?>#attach" class="d-flex flex-wrap align-items-center gap-2">
      <?= csrf_field() ?>
      <div class="form-check me-2">
        <input class="form-check-input" type="checkbox" name="include_archived" value="1" id="<?= h($id_prefix) ?>include_archived"<?= $include_archived ? ' checked' : '' ?>>
        <label class="form-check-label" for="<?= h($id_prefix) ?>include_archived">Inclure les observations archivées</label>
      </div>
      <button class="btn btn-outline-primary" type="submit" name="problem_count" value="1"><i class="bi bi-calculator"></i> Compter</button>
      <?php if ($count && count($count['to_attach'])) { ?>
      <button class="btn btn-primary" type="submit" name="problem_attach_retro" value="1" data-confirm="Rattacher <?= count($count['to_attach']) ?> observation(s) au problème « <?= h($problem['problem_title']) ?> » ?"><i class="bi bi-link-45deg"></i> Rattacher <?= count($count['to_attach']) ?> observation(s)</button>
      <?php } ?>
    </form>
    <?php if ($count) { ?>
    <ul class="mt-3 mb-0" data-problem-count>
      <li><strong><?= intval($count['matching']) ?></strong> observation(s) publiée(s) correspondent au problème ;</li>
      <li><strong><?= count($count['here']) ?></strong> déjà rattachée(s) à ce problème ;</li>
      <li><strong><?= count($count['to_attach']) ?></strong> à rattacher<?= count($count['to_attach']) ? ' : ' . h(implode(', ', array_map(function ($o) { return $o['token']; }, array_slice($count['to_attach'], 0, 50)))) . (count($count['to_attach']) > 50 ? '…' : '') : '' ?> ;</li>
      <li><strong><?= count($count['conflicts']) ?></strong> dans un autre problème (non déplacées)<?= count($count['conflicts']) ? ' :' . problem_admin_conflict_list($count['conflicts'], $titles, $self_url) : '.' ?></li>
    </ul>
    <?php }
    return ob_get_clean();
}

/* Alerts of a list of messages (built from escaped / integer values only) */
function problem_admin_alerts($messages)
{
    $html = '';
    foreach ($messages as $message) {
        $html .= '<div class="alert alert-' . h($message[0]) . '" role="alert">' . $message[1] . '</div>';
    }
    return $html;
}

/* Color of the n-th problem of the map: hues spread by the golden angle, so that each problem has its own color */
function problem_admin_map_color($index)
{
    $hue = fmod($index * 137.508, 360);
    $lightness = array(42, 34, 50)[$index % 3];
    return 'hsl(' . round($hue) . ', 72%, ' . $lightness . '%)';
}

/*
 * Problems of the map tab: zone (circle, polygon; streets: positions of the attached observations,
 * of the cities $city_ids if not null), status, priority, number of observations.
 * Returns array('shown' => list, 'hidden' => titles of the problems without position).
 */
function problem_admin_map_data($db, $problems, $counts, $statuses, $priorities, $city_ids)
{
    $street_points = array();
    $street_ids    = array();
    foreach ($problems as $problem) {
        if ($problem['problem_zone'] == 'streets') {
            $street_ids[] = intval($problem['problem_id']);
        }
    }
    if ($street_ids) {
        $query = mysqli_query($db, "SELECT po_problemid, obs_list.obs_coordinates_lat, obs_list.obs_coordinates_lon FROM obs_problem_observations
                                    INNER JOIN obs_list ON obs_list.obs_id = obs_problem_observations.po_obsid
                                    WHERE po_problemid IN (" . implode(',', $street_ids) . ")"
                                    . ($city_ids === null ? "" : " AND " . problem_city_condition($city_ids)) . " LIMIT 20000");
        while ($query && ($row = mysqli_fetch_assoc($query))) {
            $street_points[intval($row['po_problemid'])][] = array(round((float) $row['obs_coordinates_lat'], 6), round((float) $row['obs_coordinates_lon'], 6));
        }
    }
    $shown  = array();
    $hidden = array();
    foreach ($problems as $problem) {
        $id   = intval($problem['problem_id']);
        $item = array('id' => $id, 'title' => (string) $problem['problem_title'], 'zone' => $problem['problem_zone'],
                      'status' => isset($statuses[$problem['problem_status']]) ? $statuses[$problem['problem_status']][0] : (string) $problem['problem_status'],
                      'active' => in_array($problem['problem_status'], problem_active_statuses(), true),
                      'priority' => isset($priorities[intval($problem['problem_priority'])]) ? $priorities[intval($problem['problem_priority'])][0] : '',
                      'count' => isset($counts[$id]) ? intval($counts[$id]) : 0);
        if ($problem['problem_zone'] == 'polygon') {
            $item['polygon'] = problem_polygon_points($problem['problem_polygon']);
            if (!$item['polygon']) {
                $hidden[] = $item['title'];
                continue;
            }
        } elseif ($problem['problem_zone'] == 'streets') {
            // Streets highlighted by the map; the observations give the area searched (else the city of the street)
            $item['points']  = isset($street_points[$id]) ? $street_points[$id] : array();
            $item['streets'] = problem_streets_list(isset($problem['problem_streets']) ? $problem['problem_streets'] : '');
            if (!$item['points'] && !$item['streets']) {
                $hidden[] = $item['title'];
                continue;
            }
        } else {
            $item['lat']    = (float) $problem['problem_lat'];
            $item['lon']    = (float) $problem['problem_lon'];
            $item['radius'] = intval($problem['problem_radius']);
        }
        $item['color'] = problem_admin_map_color(count($shown));
        $shown[] = $item;
    }
    return array('shown' => $shown, 'hidden' => $hidden);
}

$empty_problem = array('problem_id' => 0, 'problem_title' => '', 'problem_description' => '', 'problem_categories' => '',
                       'problem_zone' => 'circle', 'problem_lat' => 0, 'problem_lon' => 0, 'problem_radius' => 100,
                       'problem_polygon' => '', 'problem_streets' => '', 'problem_start' => 0, 'problem_end' => null, 'problem_priority' => 2,
                       'problem_status' => 'open', 'start_day' => date('Y-m-d'), 'end_day' => '');

$edit = null;

/* Delete: the observations are detached, never deleted */
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['problemid'])) {
    $problem = problem_load($db, $_GET['problemid']);
    if ($problem && mysqli_query($db, "DELETE FROM obs_problems WHERE problem_id = " . intval($problem['problem_id']))) {
        mysqli_query($db, "DELETE FROM obs_notes WHERE note_problemid = " . intval($problem['problem_id']));
        mysqli_query($db, "DELETE FROM obs_problem_observations WHERE po_problemid = " . intval($problem['problem_id']));
        audit_log('problem_delete', 'problem:' . intval($problem['problem_id']), array('title' => $problem['problem_title'], 'observations' => mysqli_affected_rows($db)));
        $messages[] = array('success', 'Problème <strong>' . h($problem['problem_title']) . '</strong> supprimé (ses observations sont conservées).');
    }
}

/* Broadcast: webhooks of the event problem.broadcast, then status "En attente" */
if (isset($_POST['problem_broadcast'])) {
    $problem = problem_load($db, $_POST['problem_broadcast']);
    if ($problem) {
        $id = intval($problem['problem_id']);
        $hooks = webhooks_for_event($db, VIGILO_WEBHOOK_EVENT_PROBLEM_BROADCAST);
    }
    if ($problem && !$hooks) {
        // Nothing to broadcast to: the status is not changed (the button is disabled)
        $messages[] = array('warning', 'Problème <strong>' . h($problem['problem_title']) . '</strong> non diffusé : aucun webhook actif ne suit
                                       l\'événement « Diffusion d\'un problème » (<a class="alert-link" href="?page=webhooks">Webhooks</a>).');
    } elseif ($problem) {
        mysqli_query($db, "UPDATE obs_problems SET problem_status = 'waiting', problem_updated_time = " . time() . ",
                           problem_updated_login = '" . mysqli_real_escape_string($db, $login) . "' WHERE problem_id = " . $id);
        $deliveries = webhooks_for_problem($db, $id);
        $failures   = array();
        foreach ($deliveries as $hook_id => $delivery) {
            if ($delivery[1] !== '') {
                $failures[] = '#' . intval($hook_id) . ' (' . $delivery[1] . ')';
            }
        }
        audit_log('problem_broadcast', 'problem:' . $id, array('title' => $problem['problem_title'],
                  'status' => array(isset($statuses[$problem['problem_status']]) ? $statuses[$problem['problem_status']][0] : $problem['problem_status'], $statuses['waiting'][0]),
                  'webhooks' => count($deliveries), 'failures' => $failures));
        $messages[] = array($failures ? 'warning' : 'success', 'Problème <strong>' . h($problem['problem_title']) . '</strong> diffusé (' . count($deliveries)
                            . ' webhook(s)) et passé « En attente »' . ($failures ? ' ; en échec : ' . h(implode(', ', $failures)) . ' (voir la page Webhooks)' : '') . '.');
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
            'problem_zone'        => "'" . (in_array($edit['problem_zone'], array('polygon', 'streets'), true) ? $edit['problem_zone'] : 'circle') . "'",
            'problem_lat'         => sprintf('%.6F', $edit['problem_lat']),
            'problem_lon'         => sprintf('%.6F', $edit['problem_lon']),
            'problem_radius'      => intval($edit['problem_radius']),
            'problem_polygon'     => "'" . mysqli_real_escape_string($db, $edit['problem_polygon']) . "'",
            'problem_streets'     => $edit['problem_zone'] == 'streets' ? "'" . mysqli_real_escape_string($db, $edit['problem_streets']) . "'" : 'NULL',
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
            if ($existing['problem_zone'] == $edit['problem_zone'] && $edit['problem_zone'] != 'streets' && ($existing['problem_polygon'] !== $edit['problem_polygon']
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
            // Window of the observations to attach, shown after the creation
            $created_problem = $ok ? problem_load($db, $edit['problem_id']) : null;
        }
        if ($ok) {
            $messages[] = array('success', 'Problème <strong>' . h($edit['problem_title']) . '</strong> enregistré.');
            // Problem now resolved: setting its observations as resolved is proposed on its detail
            if ($edit['problem_status'] == 'resolved' && (!$existing || $existing['problem_status'] != 'resolved')) {
                $plan = problem_admin_resolution_plan($db, $edit['problem_id']);
                if ($plan['free'] || $plan['resolutions']) {
                    $messages[] = array('info', 'Le problème est résolu : ses observations peuvent être passées en résolues depuis
                                                 <a class="alert-link" href="' . h($self_url . '&view=' . intval($edit['problem_id'])) . '#resolve">sa fiche</a>.');
                }
            }
            // Back to the detail of the problem, not to the list
            $_GET['view'] = $edit['problem_id'];
            $edit = null;
        } else {
            $messages[] = array('danger', 'Impossible d\'enregistrer le problème.');
        }
    }
}

/* Form to show; observations shown on its map (of the suggestion, or attached to the problem) */
$reference_tokens = array();
if ($edit !== null && isset($_POST['reference_tokens']) && is_string($_POST['reference_tokens'])) {
    $reference_tokens = explode(',', $_POST['reference_tokens']);
}
if ($edit === null && isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $edit = $empty_problem;
        // Created from a suggestion: fields prefilled (validated again when saved)
        if (isset($_GET['suggestion'])) {
            $get = function ($key) { return isset($_GET[$key]) && is_scalar($_GET[$key]) ? (string) $_GET[$key] : ''; };
            $edit['problem_title']      = vigilo_truncate($get('title'), 200);
            $edit['problem_categories'] = ctype_digit($get('catid')) ? $get('catid') : '';
            $edit['problem_lat']        = is_numeric($get('lat')) ? (float) $get('lat') : 0;
            $edit['problem_lon']        = is_numeric($get('lon')) ? (float) $get('lon') : 0;
            $edit['problem_radius']     = is_numeric($get('radius')) ? intval($get('radius')) : 100;
            $edit['start_day']          = preg_match('/^\d{4}-\d{2}-\d{2}$/', $get('start')) ? $get('start') : $edit['start_day'];
            $reference_tokens           = $get('tokens') !== '' ? explode(',', $get('tokens')) : array();
        }
    } else {
        $edit = problem_load($db, $_GET['edit']);
        $edit = $edit ? problem_admin_form_values($edit) : null;
    }
}

/* Suggestions: computed on request (can be long on a large instance), dismissable */
$suggest = null;
if (isset($_POST['problem_suggestion_dismiss']) && is_string($_POST['problem_suggestion_dismiss'])) {
    if (problem_suggestion_dismiss($db, $_POST['problem_suggestion_dismiss'], $login)) {
        audit_log('problem_suggestion_dismiss', 'suggestion:' . $_POST['problem_suggestion_dismiss']);
        $messages[] = array('success', 'Suggestion ignorée : elle ne sera plus proposée.');
    }
}
if (isset($_POST['problem_suggest']) || isset($_POST['problem_suggestion_dismiss'])) {
    $suggest = problem_suggestions($db, problem_suggest_settings($db));
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

/* Merge: the problem shown goes into another one, which is then shown */
if (isset($_POST['problem_merge']) && isset($_GET['view'])) {
    $source = problem_load($db, $_GET['view']);
    $target = problem_load($db, $_POST['problem_merge']);
    if (!$source || !$target || intval($source['problem_id']) == intval($target['problem_id'])) {
        $messages[] = array('danger', 'Fusion impossible : choisir un autre problème.');
    } else {
        $moved = problem_merge($db, $source, $target, $login);
        if ($moved < 0) {
            $messages[] = array('danger', 'Impossible de fusionner les problèmes.');
        } else {
            audit_log('problem_merge', 'problem:' . intval($target['problem_id']), array('from' => intval($source['problem_id']),
                      'from_title' => $source['problem_title'], 'title' => $target['problem_title'], 'observations' => $moved));
            $messages[] = array('success', 'Problème <strong>' . h($source['problem_title']) . '</strong> fusionné dans <a class="alert-link" href="'
                                           . h($self_url . '&view=' . intval($target['problem_id'])) . '">' . h($target['problem_title']) . '</a> : '
                                           . intval($moved) . ' observation(s) et ses notes déplacées. Vérifier la zone du problème si besoin.');
            $_GET['view'] = $target['problem_id'];
        }
    }
}

/* Detail of a problem: count, attribution, manual attach / detach */
$view   = isset($_GET['view']) ? problem_load($db, $_GET['view']) : null;
if ($view && $readonly && !problem_observations($db, $view['problem_id'], $staff_cities)) {
    $view = null;
}
$count  = null;
if ($view) {
    $view_id  = intval($view['problem_id']);
    $view_url = $self_url . '&view=' . $view_id;
    $titles   = problem_titles($db);
    // Messages of the actions on the observations, shown in their card (the page goes back to it)
    $local_messages = array('attach' => array(), 'observations' => array());
    // Archived observations: only when asked (count and attachment)
    $include_archived = !empty($_POST['include_archived']);
    if (isset($_POST['problem_count'])) {
        $count = problem_count($db, $view, $include_archived);
    }
    // Resolved problem: its observations set as resolved (resolutions), after confirmation
    if (isset($_POST['problem_resolve_observations'])) {
        $plan = problem_admin_resolution_plan($db, $view_id);
        if ($view['problem_status'] != 'resolved') {
            $messages[] = array('warning', 'Le problème n\'est pas résolu.');
        } elseif (!$plan['free'] && !$plan['resolutions']) {
            $messages[] = array('info', 'Toutes les observations du problème sont déjà résolues.');
        } else {
            list($created, $updated, $failed) = problem_admin_resolve_observations($db, $view, $plan, $messages);
            if ($created !== '') {
                $messages[] = array('success', 'Résolution <strong>' . h($created) . '</strong> créée (résolue) avec ' . count($plan['free']) . ' observation(s).');
            }
            if ($updated) {
                $messages[] = array('success', 'Résolution(s) passée(s) à « Résolue » : <strong>' . h(implode(', ', $updated)) . '</strong>.');
            }
            if ($failed) {
                $messages[] = array('danger', 'Résolution(s) non modifiée(s) : ' . h(implode(' ; ', $failed)) . '.');
            }
        }
    }
    // Status and priority changed from the detail
    if (isset($_POST['problem_quick'])) {
        $new_status   = isset($_POST['problem_status']) && is_string($_POST['problem_status']) && isset($statuses[$_POST['problem_status']]) ? $_POST['problem_status'] : $view['problem_status'];
        $new_priority = isset($_POST['problem_priority']) && isset($priorities[intval($_POST['problem_priority'])]) ? intval($_POST['problem_priority']) : intval($view['problem_priority']);
        $changes      = array('title' => $view['problem_title']);
        if ($new_status !== $view['problem_status']) {
            $changes['status'] = array(isset($statuses[$view['problem_status']]) ? $statuses[$view['problem_status']][0] : $view['problem_status'], $statuses[$new_status][0]);
        }
        if ($new_priority !== intval($view['problem_priority'])) {
            $changes['priority'] = array(isset($priorities[intval($view['problem_priority'])]) ? $priorities[intval($view['problem_priority'])][0] : '', $priorities[$new_priority][0]);
        }
        if (count($changes) > 1 && mysqli_query($db, "UPDATE obs_problems SET problem_status = '" . mysqli_real_escape_string($db, $new_status) . "',
                                                       problem_priority = " . $new_priority . ", problem_updated_time = " . time() . ",
                                                       problem_updated_login = '" . mysqli_real_escape_string($db, $login) . "'
                                                       WHERE problem_id = " . $view_id)) {
            audit_log('problem_edit', 'problem:' . $view_id, $changes);
            $messages[] = array('success', 'Statut et priorité enregistrés.');
            $view = problem_load($db, $view_id);
        }
    }
    // Internal notes (obs_notes shared with the observations, never published nor in the reports)
    if (isset($_POST['problem_note_add'])) {
        $note_text = isset($_POST['note_text']) && is_scalar($_POST['note_text']) ? vigilo_truncate(trim((string) $_POST['note_text']), 5000) : '';
        if ($note_text === '') {
            $messages[] = array('warning', 'La note est vide.');
        } elseif (mysqli_query($db, "INSERT INTO obs_notes (note_obsid, note_problemid, note_time, note_login, note_text)
                                     VALUES (0, " . $view_id . ", " . time() . ", '" . mysqli_real_escape_string($db, $login) . "',
                                             '" . mysqli_real_escape_string($db, $note_text) . "')")) {
            audit_log('note_add', 'problem:' . $view_id, array('note_id' => intval(mysqli_insert_id($db))));
            $messages[] = array('success', 'Note ajoutée.');
        }
    }
    if (isset($_POST['problem_note_delete']) && isset($_POST['note_id'])) {
        $query = mysqli_query($db, "SELECT * FROM obs_notes WHERE note_id = " . intval($_POST['note_id']) . " AND note_problemid = " . $view_id);
        $note  = $query ? mysqli_fetch_assoc($query) : null;
        if ($note && mysqli_query($db, "DELETE FROM obs_notes WHERE note_id = " . intval($note['note_id']))) {
            audit_log('note_delete', 'problem:' . $view_id, array('note_id' => intval($note['note_id']), 'note_login' => $note['note_login'], 'note_text' => $note['note_text']));
            $messages[] = array('success', 'Note supprimée.');
        }
    }
    if (isset($_POST['problem_attach_retro'])) {
        $result = problem_attach_retro($db, $view, $login, $include_archived);
        audit_log('problem_attach_retro', 'problem:' . $view_id, array('title' => $view['problem_title'], 'archived' => $include_archived, 'attached' => count($result['attached']),
                  'tokens' => implode(',', array_slice($result['attached'], 0, 100)), 'already' => $result['already'],
                  'conflicts' => count($result['conflicts'])));
        $local_messages['attach'][] = array(count($result['conflicts']) ? 'warning' : 'success', '<strong>' . count($result['attached']) . '</strong> observation(s) rattachée(s), '
            . intval($result['already']) . ' déjà rattachée(s) à ce problème'
            . (count($result['conflicts']) ? ', <strong>' . count($result['conflicts']) . '</strong> laissée(s) dans un autre problème (à arbitrer) :' . problem_admin_conflict_list($result['conflicts'], $titles, $self_url) : '.'));
    }
    if (isset($_POST['problem_attach_token'])) {
        $token = isset($_POST['obs_token']) && is_scalar($_POST['obs_token']) ? strtoupper(trim((string) $_POST['obs_token'])) : '';
        $query = mysqli_query($db, "SELECT obs_id FROM obs_list WHERE obs_token = '" . mysqli_real_escape_string($db, $token) . "' LIMIT 1");
        $row   = $query ? mysqli_fetch_assoc($query) : null;
        $state = $row ? problem_attach($db, $view_id, $row['obs_id'], 'manual', $login) : 'unknown';
        if ($state == 'attached') {
            audit_log('problem_attach', 'problem:' . $view_id, array('title' => $view['problem_title'], 'token' => $token));
            $local_messages['observations'][] = array('success', 'Observation <strong>' . h($token) . '</strong> rattachée.');
        } elseif ($state == 'conflict') {
            $other = mysqli_query($db, "SELECT po_problemid FROM obs_problem_observations WHERE po_obsid = " . intval($row['obs_id']));
            $other = $other ? mysqli_fetch_assoc($other) : null;
            $local_messages['observations'][] = array('warning', 'L\'observation <strong>' . h($token) . '</strong> appartient déjà à un autre problème : la détacher d\'abord de celui-ci.'
                . problem_admin_conflict_list(array(array('token' => $token, 'problem' => $other ? intval($other['po_problemid']) : 0)), $titles, $self_url));
        } else {
            $texts = array('already' => 'est déjà rattachée à ce problème', 'unpublished' => 'n\'est pas publiée (seules les observations publiées peuvent être rattachées)',
                           'unknown' => 'est introuvable', 'error' => 'n\'a pas pu être rattachée');
            $local_messages['observations'][] = array($state == 'already' ? 'info' : 'danger', 'L\'observation <strong>' . h($token) . '</strong> ' . $texts[$state] . '.');
        }
    }
    // Split: the checked observations go to a new problem (copy of this one) or to another problem
    if (isset($_POST['bulk_action']) && in_array($_POST['bulk_action'], array('split_new', 'split_move'), true)) {
        $obs_ids = isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids']) ? array_slice(array_values(array_unique(array_filter(array_map('intval', $_POST['bulk_ids'])))), 0, 1000) : array();
        $target  = null;
        if ($_POST['bulk_action'] == 'split_move') {
            $target = isset($_POST['split_target']) ? problem_load($db, $_POST['split_target']) : null;
            if ($target && intval($target['problem_id']) == $view_id) {
                $target = null;
            }
        } else {
            $title = isset($_POST['split_title']) && is_scalar($_POST['split_title']) ? trim((string) $_POST['split_title']) : '';
            $new   = $obs_ids ? problem_copy($db, $view, $title !== '' ? $title : $view['problem_title'] . ' (séparé)', $login) : 0;
            $target = $new ? problem_load($db, $new) : null;
        }
        $moved = ($obs_ids && $target) ? problem_move_observations($db, $view_id, $target['problem_id'], $obs_ids, $login) : 0;
        if (!$moved) {
            $local_messages['observations'][] = array('warning', 'Aucune observation déplacée (sélection vide ou problème cible invalide).');
        } else {
            $target_url = $self_url . '&view=' . intval($target['problem_id']);
            audit_log('problem_split', 'problem:' . $view_id, array('title' => $view['problem_title'], 'to' => intval($target['problem_id']),
                      'to_title' => $target['problem_title'], 'new' => $_POST['bulk_action'] == 'split_new', 'observations' => $moved));
            $local_messages['observations'][] = array('success', intval($moved) . ' observation(s) déplacée(s) vers ' . ($_POST['bulk_action'] == 'split_new' ? 'le nouveau problème' : 'le problème')
                                           . ' <a class="alert-link" href="' . h($target_url) . '">' . h($target['problem_title']) . '</a>.'
                                           . ($_POST['bulk_action'] == 'split_new' ? ' Il reprend la zone de celui-ci : <a class="alert-link" href="' . h($self_url . '&edit=' . intval($target['problem_id'])) . '#zone">modifier sa zone</a>
                                              pour que les nouvelles observations soient rattachées au bon problème.' : ''));
        }
    }
    if (isset($_GET['action']) && $_GET['action'] == 'detach' && isset($_GET['obsid'])) {
        $query = mysqli_query($db, "SELECT obs_token FROM obs_list WHERE obs_id = " . intval($_GET['obsid']));
        $row   = $query ? mysqli_fetch_assoc($query) : null;
        if (problem_detach($db, $view_id, $_GET['obsid'])) {
            audit_log('problem_detach', 'problem:' . $view_id, array('title' => $view['problem_title'], 'token' => $row ? $row['obs_token'] : intval($_GET['obsid'])));
            $local_messages['observations'][] = array('success', 'Observation <strong>' . h($row ? $row['obs_token'] : '') . '</strong> détachée du problème.');
        }
    }
}

/* Filters of the list */
$f_q        = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
// Status: active problems by default, "all" for every status
$f_status   = isset($_GET['status']) && is_scalar($_GET['status']) && ($_GET['status'] === 'all' || $_GET['status'] === 'active' || isset($statuses[$_GET['status']])) ? (string) $_GET['status'] : 'active';
$f_priority = isset($_GET['priority']) && is_scalar($_GET['priority']) && isset($priorities[intval($_GET['priority'])]) ? intval($_GET['priority']) : 0;
$f_category = isset($_GET['category']) && is_scalar($_GET['category']) && isset($problem_categories[intval($_GET['category'])]) ? intval($_GET['category']) : 0;

$where = array('1');
if ($f_q !== '') {
    $like    = mysqli_real_escape_string($db, addcslashes($f_q, '%_\\'));
    $where[] = "(problem_title LIKE '%" . $like . "%' OR problem_description LIKE '%" . $like . "%')";
}
if ($f_status === 'active') {
    $where[] = "problem_status IN ('" . implode("','", problem_active_statuses()) . "')";
} elseif ($f_status !== 'all') {
    $where[] = "problem_status = '" . mysqli_real_escape_string($db, $f_status) . "'";
}
if ($f_priority) {
    $where[] = "problem_priority = " . intval($f_priority);
}
if ($f_category) {
    // A problem without category covers them all
    $where[] = "(FIND_IN_SET(" . intval($f_category) . ", problem_categories) OR problem_categories = '')";
}
if ($readonly) {
    $where[] = "problem_id IN (SELECT po_problemid FROM obs_problem_observations INNER JOIN obs_list ON obs_list.obs_id = obs_problem_observations.po_obsid
                               WHERE " . problem_city_condition($staff_cities) . ")";
}
$problems = array();
$query    = mysqli_query($db, "SELECT * FROM obs_problems WHERE " . implode(' AND ', $where) . " ORDER BY problem_priority DESC, problem_updated_time DESC, problem_id DESC");
while ($query && ($row = mysqli_fetch_assoc($query))) {
    $problems[] = $row;
}
$counts   = problem_observation_counts($db, $staff_cities);
// "Diffuser" only when an active webhook follows the event
$broadcast_hooks = $readonly ? 0 : count(webhooks_for_event($db, VIGILO_WEBHOOK_EVENT_PROBLEM_BROADCAST));
$filtered = $f_q !== '' || $f_status !== 'active' || $f_priority || $f_category;
$tab      = isset($_GET['tab']) && $_GET['tab'] === 'map' ? 'map' : 'list';
$filter_query = '&' . http_build_query(array_filter(array('q' => $f_q, 'status' => $f_status !== 'active' ? $f_status : '', 'priority' => $f_priority ?: '', 'category' => $f_category ?: ''), 'strlen'));

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

/* After a creation: existing observations counted, attachment proposed (same panel as the detail) */
if (!empty($created_problem)) { ?>
<div class="modal fade" id="attachModal" tabindex="-1" aria-labelledby="attachModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="attachModalTitle"><i class="bi bi-link-45deg"></i> Rattacher les observations existantes à « <?= h($created_problem['problem_title']) ?> » ?</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <?= problem_admin_attach_panel($created_problem, problem_count($db, $created_problem, false), false, problem_titles($db), $self_url, 'created-') ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Plus tard</button>
      </div>
    </div>
  </div>
</div>
<div hidden data-reopen-modal="#attachModal"></div>
<?php }

if ($edit !== null) {
    $selected = problem_category_ids($edit);
    $polygon  = $edit['problem_zone'] == 'polygon' ? (string) $edit['problem_polygon'] : '';
    $zone     = in_array($edit['problem_zone'], array('polygon', 'streets'), true) ? $edit['problem_zone'] : 'circle';
    $points   = problem_admin_reference_points($db, $reference_tokens, intval($edit['problem_id']));
    $street_texts = problem_admin_street_texts($edit);
    // Suggestion: its streets proposed (used if the zone "Rues" is chosen)
    if (!$street_texts && $points && !$edit['problem_id']) {
        $seen = array();
        foreach ($points as $point) {
            list($street, $city) = problem_address_parts($point['address']);
            $street = vigilo_street_name($street);
            $key    = problem_street_key($street) . '|' . problem_street_key($city);
            if (problem_street_key($street) !== '' && !isset($seen[$key]) && count($seen) < VIGILO_PROBLEM_STREETS_MAX) {
                $seen[$key]     = true;
                $street_texts[] = problem_street_text(array('street' => $street, 'city' => $city));
            }
        }
    }
    $known_streets = problem_admin_known_streets($db);
?>
<form class="card shadow-sm mb-4" method="POST" action="<?= h($self_url . ($edit['problem_id'] ? '&view=' . intval($edit['problem_id']) : '')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="problem_id" value="<?= intval($edit['problem_id']) ?>">
  <?php if ($reference_tokens) { ?><input type="hidden" name="reference_tokens" value="<?= h(implode(',', array_slice(array_filter($reference_tokens, 'is_string'), 0, 200))) ?>"><?php } ?>
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
          <legend class="form-label fs-6 mb-1">Catégories <span class="text-body-secondary small">(aucune cochée : toutes)</span></legend>
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
          <div class="form-text">Une observation de l'une des catégories cochées peut être rattachée ; sans case cochée, toutes les catégories.</div>
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
      <div class="col-12 problem-map-block" id="zone">
        <fieldset>
          <legend class="form-label fs-6 mb-1">Zone</legend>
          <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
            <div class="form-check form-check-inline me-0">
              <input class="form-check-input" type="radio" name="problem_zone" id="problem_zone_circle" value="circle"<?= $zone == 'circle' ? ' checked' : '' ?>>
              <label class="form-check-label" for="problem_zone_circle">Cercle</label>
            </div>
            <div class="form-check form-check-inline me-0">
              <input class="form-check-input" type="radio" name="problem_zone" id="problem_zone_polygon" value="polygon"<?= $zone == 'polygon' ? ' checked' : '' ?>>
              <label class="form-check-label" for="problem_zone_polygon">Polygone</label>
            </div>
            <div class="form-check form-check-inline me-0">
              <input class="form-check-input" type="radio" name="problem_zone" id="problem_zone_streets" value="streets"<?= $zone == 'streets' ? ' checked' : '' ?>>
              <label class="form-check-label" for="problem_zone_streets">Rues</label>
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
          <div class="problem-map rounded border" data-problem-map data-center="<?= h($map_center) ?>" data-zoom="<?= intval($map_zoom) ?>" data-points="<?= h(json_encode($points)) ?>" style="height: 380px;"></div>
          <div class="form-text" data-map-status aria-live="polite"></div>
          <?php if ($points) { ?>
          <div class="form-text"><span class="problem-legend-dot" style="background: <?= $edit['problem_id'] ? '#198754' : '#fd7e14' ?>;"></span>
            <?= count($points) ?> observation<?= count($points) > 1 ? 's' : '' ?> <?= $edit['problem_id'] ? 'rattachée' . (count($points) > 1 ? 's' : '') . ' au problème' : 'de la suggestion' ?> (repère<?= count($points) > 1 ? 's' : '' ?> sur la carte).</div>
          <?php } ?>
          <div class="form-text" data-zone-help="circle">Cliquer sur la carte pour placer le centre du cercle (marqueur déplaçable), puis régler le rayon.</div>
          <div class="form-text" data-zone-help="polygon">Cliquer sur la carte pour ajouter un point à la fin du polygone (3 au moins). Faire glisser un point pour le déplacer,
            cliquer ou faire glisser le petit point au milieu d'un côté pour en insérer un, clic droit (ou appui long) sur un point pour le retirer.</div>
          <div class="form-text" data-zone-help="streets">Les observations dont l'adresse est dans l'une de ces rues correspondent au problème
            (sans tenir compte des majuscules, des accents ni du numéro). Cliquer sur une observation de la carte ajoute sa rue.</div>
          <div class="mt-2" data-zone-tools="streets">
            <label class="form-label mb-1" for="problem_street_0">Rues <span class="text-body-secondary small">(1 à <?= VIGILO_PROBLEM_STREETS_MAX ?>, « Rue, Ville » ; sans ville : toutes les villes)</span></label>
            <div data-street-list>
              <?php foreach ($street_texts ? $street_texts : array('') as $i => $text) { ?>
              <div class="input-group input-group-sm mb-1" data-street-row>
                <input class="form-control" id="problem_street_<?= intval($i) ?>" name="problem_streets[]" list="problem_known_streets" maxlength="255" value="<?= h($text) ?>" placeholder="Rue de la Gare, Montpellier" aria-label="Rue">
                <button class="btn btn-outline-danger" type="button" data-street-remove aria-label="Retirer cette rue"><i class="bi bi-x-lg"></i></button>
              </div>
              <?php } ?>
            </div>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-street-add><i class="bi bi-plus-lg"></i> Ajouter une rue</button>
            <datalist id="problem_known_streets">
              <?php foreach ($known_streets as $text => $count) { ?><option value="<?= h($text) ?>"><?= intval($count) ?> observation<?= $count > 1 ? 's' : '' ?></option><?php } ?>
            </datalist>
          </div>
          <div class="row g-3 mt-0" data-zone-tools="circle">
            <div class="col-6 col-md-3">
              <label class="form-label" for="problem_lat">Latitude du centre</label>
              <input class="form-control" id="problem_lat" name="problem_lat" inputmode="decimal" value="<?= $zone == 'circle' && floatval($edit['problem_lat']) ? h($edit['problem_lat']) : '' ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="problem_lon">Longitude du centre</label>
              <input class="form-control" id="problem_lon" name="problem_lon" inputmode="decimal" value="<?= $zone == 'circle' && floatval($edit['problem_lon']) ? h($edit['problem_lon']) : '' ?>">
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
    <a class="btn btn-outline-secondary" href="<?= h($self_url . ($edit['problem_id'] ? '&view=' . intval($edit['problem_id']) : '')) ?>">Annuler</a>
    <button class="btn btn-primary" type="submit" name="problem_save" value="1"><i class="bi bi-check-lg"></i> Enregistrer</button>
  </div>
</form>
<link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="js/street-lines.js?v=<?= intval(@filemtime(__DIR__ . '/../js/street-lines.js')) ?>"></script>
<script src="js/problem-map.js?v=<?= intval(@filemtime(__DIR__ . '/../js/problem-map.js')) ?>"></script>
<?php } ?>

<?php if ($view && $edit === null) {
    $attached = problem_observations($db, $view_id, $staff_cities);
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
                      'radius' => intval($view['problem_radius']), 'polygon' => problem_polygon_points($view['problem_polygon']), 'points' => $points,
                      'streets' => problem_streets_list(isset($view['problem_streets']) ? $view['problem_streets'] : ''),
                      'center' => $map_center, 'zoom' => $map_zoom);
    // Follow-up notes (obs_notes), in a window opened from the main card
    $notes = array();
    $query = $readonly ? false : mysqli_query($db, "SELECT * FROM obs_notes WHERE note_problemid = " . $view_id . " ORDER BY note_time ASC, note_id ASC");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $notes[] = $row;
    }
?>
<p><a href="<?= h($self_url) ?>"><i class="bi bi-arrow-left"></i> Tous les problèmes</a></p>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0 text-break"><?= h($view['problem_title']) ?></h2>
    <div class="d-flex gap-2 align-items-center">
      <span class="badge text-bg-<?= h($priority[1]) ?>"><?= h($priority[0]) ?></span>
      <span class="badge text-bg-<?= h($status[1]) ?>"><?= h($status[0]) ?></span>
      <a class="btn btn-sm btn-outline-secondary text-nowrap" href="problem_report.php?id=<?= $view_id ?>" target="_blank" rel="noopener" title="Analyse des observations (dates, horaires, lieux) à imprimer ou enregistrer en PDF"><i class="bi bi-file-earmark-bar-graph"></i> Rapport</a>
      <?php if (!$readonly) { ?>
      <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-bs-toggle="modal" data-bs-target="#notesModal"><i class="bi bi-sticky"></i> Notes <span class="badge text-bg-secondary"><?= count($notes) ?></span></button>
      <a class="btn btn-sm btn-outline-primary" href="<?= h($self_url) ?>&amp;edit=<?= $view_id ?>"><i class="bi bi-pencil"></i> Modifier</a>
      <?php } ?>
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
    <?php if (!$readonly) { ?>
    <form method="POST" action="<?= h($view_url) ?>" class="row g-2 align-items-end mb-3">
      <?= csrf_field() ?>
      <div class="col-6 col-sm-auto">
        <label class="form-label small mb-1" for="quick_status">Statut</label>
        <select class="form-select form-select-sm" id="quick_status" name="problem_status">
          <?php foreach ($statuses as $key => $item) { ?><option value="<?= h($key) ?>"<?= $view['problem_status'] === $key ? ' selected' : '' ?>><?= h($item[0]) ?></option><?php } ?>
        </select>
      </div>
      <div class="col-6 col-sm-auto">
        <label class="form-label small mb-1" for="quick_priority">Priorité</label>
        <select class="form-select form-select-sm" id="quick_priority" name="problem_priority">
          <?php foreach ($priorities as $key => $item) { ?><option value="<?= intval($key) ?>"<?= intval($view['problem_priority']) === $key ? ' selected' : '' ?>><?= h($item[0]) ?></option><?php } ?>
        </select>
      </div>
      <div class="col-12 col-sm-auto">
        <button class="btn btn-sm btn-outline-primary" type="submit" name="problem_quick" value="1"><i class="bi bi-check-lg"></i> Enregistrer</button>
      </div>
    </form>
    <?php } ?>
    <div class="problem-view-map rounded border" data-problem-view="<?= h(json_encode($map_data)) ?>" style="height: 320px;"></div>
    <div class="form-text">
      <span class="text-success"><i class="bi bi-circle-fill"></i> rattachées</span>
      <?php if ($count) { ?> · <span class="text-primary"><i class="bi bi-circle-fill"></i> à rattacher</span> · <span><i class="bi bi-circle-fill" style="color: #fd7e14;"></i> dans un autre problème</span><?php } ?>
      <?php if (!$readonly) { ?> · <a href="<?= h($self_url) ?>&amp;edit=<?= $view_id ?>#zone"><i class="bi bi-bounding-box"></i> Modifier la zone</a><?php } ?>
    </div>
  </div>
</div>

<?php if ($attached) {
    $time_stats  = problem_time_stats($attached);
    $time_charts = problem_time_charts($time_stats); ?>
<div class="card shadow-sm mb-4" id="timeline">
  <div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> Chronologie et horaires des observations</div>
  <div class="card-body">
    <h3 class="h6 mb-0">Frise chronologique</h3>
    <div class="problem-chart"><?= $time_charts['timeline'] /* SVG built with escaped labels (report_svg_columns) */ ?></div>
    <h3 class="h6 mb-0 mt-3">Jours et heures</h3>
    <div class="problem-chart"><?= $time_charts['heatmap'] /* matrix, darker with the number of observations */ ?></div>
  </div>
</div>
<?php } ?>

<?php if (!$readonly && $view['problem_status'] == 'resolved') {
    $plan = problem_admin_resolution_plan($db, $view_id);
    $to_resolve = count($plan['free']);
    foreach ($plan['resolutions'] as $resolution) {
        $to_resolve += $resolution['here'];
    }
    $others = 0;
    foreach ($plan['resolutions'] as $resolution) {
        $others += $resolution['others'];
    }
    if ($to_resolve) { ?>
<div class="card shadow-sm mb-4 border-success" id="resolve">
  <div class="card-header fw-semibold"><i class="bi bi-check2-all"></i> Problème résolu : passer ses observations en résolues ?</div>
  <div class="card-body">
    <p class="mb-2"><strong><?= intval($to_resolve) ?></strong> observation(s) publiée(s) du problème ne sont pas résolues<?= $plan['resolved'] ? ' (' . intval($plan['resolved']) . ' déjà résolue(s))' : '' ?> :</p>
    <ul class="small">
      <?php if ($plan['free']) { ?><li><?= count($plan['free']) ?> sans résolution : une résolution « Résolue », datée d'aujourd'hui, sera créée pour elles (<?= h(implode(', ', array_slice($plan['free'], 0, 20))) ?><?= count($plan['free']) > 20 ? '…' : '' ?>) ;</li><?php } ?>
      <?php foreach ($plan['resolutions'] as $resolution) { ?>
      <li>résolution <strong><?= h($resolution['token']) ?></strong> (<?= intval($resolution['here']) ?> observation(s) du problème<?= $resolution['others'] ? ', <strong>' . intval($resolution['others']) . ' autre(s)</strong>' : '' ?>) : passera à « Résolue » ;</li>
      <?php } ?>
    </ul>
    <?php if ($others) { ?><p class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> <?= intval($others) ?> observation(s) hors de ce problème, dans ces résolutions, seront aussi résolues.</p><?php } ?>
    <p class="small text-body-secondary">Les observations résolues apparaissent comme telles dans l'application ; les webhooks des résolutions sont appelés.</p>
    <form method="POST" action="<?= h($view_url) ?>#resolve" class="m-0">
      <?= csrf_field() ?>
      <button class="btn btn-success" type="submit" name="problem_resolve_observations" value="1" data-confirm="Passer <?= intval($to_resolve) ?> observation(s) en résolues ?"><i class="bi bi-check2-all"></i> Passer <?= intval($to_resolve) ?> observation(s) en résolues</button>
    </form>
  </div>
</div>
<?php }
} ?>

<?php if (!$readonly) { ?>
<div class="card shadow-sm mb-4" id="attach">
  <div class="card-header fw-semibold">Rattachement des observations existantes</div>
  <div class="card-body">
    <?= problem_admin_alerts($local_messages['attach']) ?>
    <?= problem_admin_attach_panel($view, $count, $include_archived, $titles, $self_url, '') ?>
  </div>
</div>
<?php } ?>

<div class="card shadow-sm mb-4" id="observations">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="fw-semibold">Observations rattachées (<?= count($attached) ?>)</span>
    <?php if (!$readonly) { ?>
    <form method="POST" action="<?= h($view_url) ?>#observations" class="d-flex gap-2">
      <?= csrf_field() ?>
      <label class="visually-hidden" for="obs_token">Token de l'observation</label>
      <input class="form-control form-control-sm" id="obs_token" name="obs_token" placeholder="Token" maxlength="20" required style="max-width: 9rem;">
      <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit" name="problem_attach_token" value="1"><i class="bi bi-plus-lg"></i> Rattacher</button>
    </form>
    <?php } ?>
  </div>
  <?php if ($local_messages['observations']) { ?><div class="card-body pb-0"><?= problem_admin_alerts($local_messages['observations']) ?></div><?php } ?>
  <?php if (empty($attached)) { ?>
    <div class="card-body"><p class="text-body-secondary mb-0">Aucune observation rattachée.</p></div>
  <?php } else {
      // Pages of the attached observations (the map shows all of them)
      $per_page   = 20;
      $obs_pages  = max(1, (int) ceil(count($attached) / $per_page));
      $obs_page   = isset($_GET['opage']) && ctype_digit((string) $_GET['opage']) ? max(1, min($obs_pages, intval($_GET['opage']))) : 1;
      $page_rows  = array_slice($attached, ($obs_page - 1) * $per_page, $per_page); ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin table-stack-md mb-0">
      <thead><tr><?php if (!$readonly) { ?><th scope="col"><span class="visually-hidden">Sélection</span></th><?php } ?><th scope="col">Photo</th><th scope="col">Observation</th><th scope="col">Catégorie</th><th scope="col">Rattachement</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($page_rows as $row) {
          $token = (string) $row['obs_token'];
          $catid = intval($row['obs_categorie']); ?>
        <tr>
          <?php if (!$readonly) { ?><td><input class="form-check-input" type="checkbox" name="bulk_ids[]" value="<?= intval($row['obs_id']) ?>" form="split-form" aria-label="Sélectionner l'observation <?= h($token) ?>"></td><?php } ?>
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
          <td class="text-end"><?php if (!$readonly) { ?><a class="btn btn-sm btn-outline-danger text-nowrap" href="<?= h($view_url) ?>&amp;action=detach&amp;obsid=<?= intval($row['obs_id']) ?><?= h(csrf_query()) ?>#observations" data-confirm="Détacher l'observation <?= h($token) ?> du problème ?"><i class="bi bi-x-lg"></i> Détacher</a><?php } ?></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <?php if ($obs_pages > 1) {
      $opage_url = $view_url . '&opage='; ?>
  <nav class="card-footer" aria-label="Pages des observations rattachées">
    <ul class="pagination pagination-sm flex-wrap mb-0" id="attached">
      <li class="page-item<?= $obs_page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= h($opage_url . max(1, $obs_page - 1)) ?>#attached"><i class="bi bi-chevron-left"></i><span class="visually-hidden">Précédent</span></a></li>
      <?php for ($i = 1; $i <= $obs_pages; $i++) { ?>
      <li class="page-item<?= $i == $obs_page ? ' active' : '' ?>"<?= $i == $obs_page ? ' aria-current="page"' : '' ?>><a class="page-link" href="<?= h($opage_url . $i) ?>#attached"><?= $i ?></a></li>
      <?php } ?>
      <li class="page-item<?= $obs_page >= $obs_pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= h($opage_url . min($obs_pages, $obs_page + 1)) ?>#attached"><i class="bi bi-chevron-right"></i><span class="visually-hidden">Suivant</span></a></li>
    </ul>
    <div class="small text-body-secondary mt-1"><?= ($obs_page - 1) * $per_page + 1 ?> à <?= min(count($attached), $obs_page * $per_page) ?> sur <?= count($attached) ?></div>
  </nav>
  <?php } ?>
  <?php if (!$readonly) {
      $other_titles = $titles;
      unset($other_titles[$view_id]);
      asort($other_titles, SORT_NATURAL | SORT_FLAG_CASE); ?>
  <form method="POST" action="<?= h($view_url . ($obs_page > 1 ? '&opage=' . $obs_page : '')) ?>#observations" id="split-form" class="card-footer" data-bulk>
    <?= csrf_field() ?>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="form-check mb-0 me-2">
        <input class="form-check-input" type="checkbox" id="split-all" data-bulk-all>
        <label class="form-check-label" for="split-all">Toute la page</label>
      </div>
      <span class="text-body-secondary small me-2"><strong data-bulk-count>0</strong> sélectionnée(s)</span>
      <label class="visually-hidden" for="split-action">Séparer le problème</label>
      <select class="form-select form-select-sm w-auto" name="bulk_action" id="split-action">
        <option value="">Séparer : déplacer la sélection…</option>
        <option value="split_new" data-field="split_new" data-confirm="Déplacer %n observation(s) vers un nouveau problème ?">vers un nouveau problème</option>
        <?php if ($other_titles) { ?><option value="split_move" data-field="split_move" data-confirm="Déplacer %n observation(s) vers le problème choisi ?">vers un autre problème</option><?php } ?>
      </select>
      <span data-bulk-field="split_new" hidden>
        <label class="visually-hidden" for="split-title">Titre du nouveau problème</label>
        <input class="form-control form-control-sm" id="split-title" name="split_title" maxlength="200" value="<?= h($view['problem_title'] . ' (séparé)') ?>" style="min-width: 14rem;">
      </span>
      <span data-bulk-field="split_move" hidden>
        <label class="visually-hidden" for="split-target">Problème cible</label>
        <select class="form-select form-select-sm w-auto mw-100" name="split_target" id="split-target">
          <option value="">— problème —</option>
          <?php foreach ($other_titles as $other_id => $other_title) { ?>
          <option value="<?= intval($other_id) ?>"><?= h($other_title) ?></option>
          <?php } ?>
        </select>
      </span>
      <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-scissors"></i> Déplacer</button>
    </div>
    <div class="form-text">Le nouveau problème reprend les catégories, la zone, la période, la priorité et le statut de celui-ci : ajuster ensuite sa zone.</div>
  </form>
  <?php } ?>
  <?php } ?>
</div>
<?php if (!$readonly) { ?>
<div class="modal fade" id="notesModal" tabindex="-1" aria-labelledby="notesModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="notesModalTitle"><i class="bi bi-sticky"></i> Notes de suivi (<?= count($notes) ?>)</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <p class="small text-body-secondary"><i class="bi bi-lock"></i> Visibles seulement dans l'admin : jamais publiées ni reprises dans les rapports.</p>
        <?php if (!$notes) { ?><p class="small text-body-secondary">Aucune note pour l'instant.</p><?php } ?>
        <?php foreach ($notes as $note) { ?>
        <div class="border-bottom pb-2 mb-2">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="small text-body-secondary"><i class="bi bi-person"></i> <?= h($note['note_login']) ?> · <?= h(date('d/m/Y H:i', intval($note['note_time']))) ?></div>
            <form method="POST" action="<?= h($view_url) ?>" class="m-0">
              <?= csrf_field() ?>
              <input type="hidden" name="note_id" value="<?= intval($note['note_id']) ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" name="problem_note_delete" value="1" data-confirm="Supprimer cette note ?" title="Supprimer la note"><i class="bi bi-trash"></i><span class="visually-hidden">Supprimer la note</span></button>
            </form>
          </div>
          <div class="small text-break"><?= nl2br(h($note['note_text'])) ?></div>
        </div>
        <?php } ?>
      </div>
      <div class="modal-footer d-block">
        <form method="POST" action="<?= h($view_url) ?>" class="m-0">
          <?= csrf_field() ?>
          <label class="visually-hidden" for="note_text">Nouvelle note</label>
          <textarea class="form-control form-control-sm mb-2" id="note_text" name="note_text" rows="2" maxlength="5000" placeholder="Échange avec la mairie, relance, décision…" required></textarea>
          <button class="btn btn-sm btn-outline-primary" type="submit" name="problem_note_add" value="1"><i class="bi bi-plus-lg"></i> Ajouter la note</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php if (isset($_POST['problem_note_add']) || isset($_POST['problem_note_delete'])) { ?><div hidden data-reopen-modal="#notesModal"></div><?php } ?>
<?php if (count($titles) > 1) {
    $other_titles = $titles;
    unset($other_titles[$view_id]);
    asort($other_titles, SORT_NATURAL | SORT_FLAG_CASE); ?>
<div class="card shadow-sm mb-4" id="merge">
  <div class="card-header fw-semibold"><i class="bi bi-intersect"></i> Fusionner avec un autre problème</div>
  <div class="card-body">
    <p class="small text-body-secondary">
      Les observations et les notes de ce problème passent dans le problème choisi, qui reprend aussi ses catégories et
      une période couvrant les deux ; ce problème est ensuite supprimé. La zone du problème choisi est conservée.
    </p>
    <form method="POST" action="<?= h($view_url) ?>" class="d-flex flex-wrap gap-2">
      <?= csrf_field() ?>
      <label class="visually-hidden" for="merge-target">Problème qui reçoit celui-ci</label>
      <select class="form-select form-select-sm w-auto mw-100" name="problem_merge" id="merge-target" required>
        <option value="">— fusionner dans… —</option>
        <?php foreach ($other_titles as $other_id => $other_title) { ?>
        <option value="<?= intval($other_id) ?>"><?= h($other_title) ?></option>
        <?php } ?>
      </select>
      <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Fusionner « <?= h($view['problem_title']) ?> » dans le problème choisi ? Ce problème sera supprimé."><i class="bi bi-intersect"></i> Fusionner</button>
    </form>
  </div>
</div>
<?php } ?>
<?php } ?>
<link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="js/street-lines.js?v=<?= intval(@filemtime(__DIR__ . '/../js/street-lines.js')) ?>"></script>
<script src="js/problem-map.js?v=<?= intval(@filemtime(__DIR__ . '/../js/problem-map.js')) ?>"></script>
<?php } else { ?>
<?php
$ambiguous = ($edit === null && !$readonly) ? problems_ambiguous($db) : array();
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
<?php }
$suggest_settings = problem_suggest_settings($db);
if (!$readonly) { ?>
<div class="card shadow-sm mb-4" id="suggestions">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span class="fw-semibold"><i class="bi bi-lightbulb"></i> Suggestions<?= $suggest ? ' (' . count($suggest['suggestions']) . ')' : '' ?></span>
    <form method="POST" action="<?= h($self_url) ?>#suggestions" class="m-0">
      <?= csrf_field() ?>
      <button class="btn btn-sm btn-outline-primary" type="submit" name="problem_suggest" value="1"><i class="bi bi-search"></i> Rechercher</button>
    </form>
  </div>
  <div class="card-body<?= $suggest && $suggest['suggestions'] ? ' pb-0' : '' ?>">
    <p class="small text-body-secondary mb-0">
      Groupes d'au moins <?= intval($suggest_settings['min']) ?> observations publiées sans problème, de même catégorie, à moins de
      <?= intval($suggest_settings['radius']) ?> m (ou dans la même rue), des <?= intval($suggest_settings['months']) ?> derniers mois
      (<a href="?page=settings">réglages</a>). Rien n'est créé sans votre accord.
      <?php if ($suggest) { ?><br><?= intval($suggest['analysed']) ?> observation(s) analysée(s)<?= $suggest['analysed'] >= VIGILO_PROBLEM_SUGGEST_MAX ? ' (les plus récentes)' : '' ?>.<?php } ?>
    </p>
    <?php if ($suggest && !$suggest['suggestions']) { ?><p class="mb-0 mt-2">Aucune suggestion.</p><?php } ?>
  </div>
  <?php if ($suggest && $suggest['suggestions']) { ?>
  <ul class="list-group list-group-flush">
    <?php foreach ($suggest['suggestions'] as $suggestion) {
        $category = isset($problem_categories[$suggestion['category']]) ? $problem_categories[$suggestion['category']][0] : '#' . $suggestion['category'];
        $create   = $self_url . '&edit=new&suggestion=1&' . http_build_query(array('title' => $category . ' – ' . $suggestion['address'], 'catid' => $suggestion['category'],
                    'lat' => $suggestion['lat'], 'lon' => $suggestion['lon'], 'radius' => $suggestion['radius'], 'start' => date('Y-m-d', $suggestion['first']),
                    'tokens' => implode(',', array_slice($suggestion['tokens'], 0, 200)))); ?>
    <li class="list-group-item">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge text-bg-primary"><?= intval($suggestion['count']) ?></span>
        <span class="fw-semibold"><?= h($category) ?></span>
        <span class="text-break me-auto"><?= h($suggestion['address']) ?></span>
        <a class="btn btn-sm btn-primary" href="<?= h($create) ?>"><i class="bi bi-plus-lg"></i> Créer le problème</a>
        <form method="POST" action="<?= h($self_url) ?>#suggestions" class="m-0">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-outline-secondary" type="submit" name="problem_suggestion_dismiss" value="<?= h($suggestion['key']) ?>" data-confirm="Ignorer cette suggestion ? Elle ne sera plus proposée."><i class="bi bi-eye-slash"></i> Ignorer</button>
        </form>
      </div>
      <div class="small text-body-secondary mt-1">
        Du <?= h(date('d/m/Y', $suggestion['first'])) ?> au <?= h(date('d/m/Y', $suggestion['last'])) ?> ·
        <?= h(implode(', ', array_slice($suggestion['tokens'], 0, 8))) ?><?= count($suggestion['tokens']) > 8 ? '…' : '' ?>
      </div>
    </li>
    <?php } ?>
  </ul>
  <?php } ?>
</div>
<?php } ?>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-diagram-3"></i> Problèmes</h2>
    <?php if (!$readonly) { ?><a class="btn btn-sm btn-primary" href="<?= h($self_url) ?>&amp;edit=new"><i class="bi bi-plus-lg"></i> Nouveau problème</a><?php } ?>
  </div>
  <div class="card-body pb-0">
    <p class="small text-body-secondary">
      Un problème regroupe les observations publiées d'une zone (cercle, polygone ou rues), de catégories et d'une période.
      À sa publication, une observation est rattachée automatiquement au problème actif (ouvert, en cours, en attente)
      qui lui correspond, s'il n'y en a qu'un. Les problèmes ne sont visibles que dans l'admin.
      <?php if ($readonly) { ?><br>Consultation seulement : problèmes ayant des observations dans vos villes, gérés par les administrateurs.<?php } ?>
    </p>
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link<?= $tab == 'list' ? ' active" aria-current="page' : '' ?>" href="<?= h($self_url . rtrim($filter_query, '&')) ?>"><i class="bi bi-list-ul"></i> Liste</a></li>
      <li class="nav-item"><a class="nav-link<?= $tab == 'map' ? ' active" aria-current="page' : '' ?>" href="<?= h($self_url . '&tab=map' . rtrim($filter_query, '&')) ?>"><i class="bi bi-map"></i> Carte</a></li>
    </ul>
    <form class="row g-2 align-items-end mb-3" method="GET" action="">
      <input type="hidden" name="page" value="<?= h($page_name) ?>">
      <?php if ($tab == 'map') { ?><input type="hidden" name="tab" value="map"><?php } ?>
      <div class="col-12 col-sm">
        <label class="form-label small mb-1" for="f_q">Recherche</label>
        <input class="form-control form-control-sm" type="search" id="f_q" name="q" value="<?= h($f_q) ?>" placeholder="Titre, description">
      </div>
      <div class="col-6 col-sm-auto">
        <label class="form-label small mb-1" for="f_status">Statut</label>
        <select class="form-select form-select-sm" id="f_status" name="status">
          <option value="active"<?= $f_status === 'active' ? ' selected' : '' ?>>Actifs (ouvert, en cours, en attente)</option>
          <option value="all"<?= $f_status === 'all' ? ' selected' : '' ?>>Tous</option>
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
        <?php if ($filtered) { ?><a class="btn btn-outline-secondary btn-sm" href="<?= h($self_url . ($tab == 'map' ? '&tab=map' : '')) ?>"><i class="bi bi-x-lg"></i> Réinitialiser</a><?php } ?>
      </div>
    </form>
  </div>
  <?php if (empty($problems)) { ?>
    <div class="card-body pt-0"><p class="text-body-secondary mb-0"><?= $filtered ? 'Aucun problème ne correspond aux filtres.' : 'Aucun problème actif (statut « Tous » pour voir les autres).' ?></p></div>
  <?php } elseif ($tab == 'map') {
      $map_problems = problem_admin_map_data($db, $problems, $counts, $statuses, $priorities, $staff_cities); ?>
  <div class="card-body pt-0">
    <div class="problem-list-map rounded border" data-problems-map="<?= h(json_encode(array('problems' => $map_problems['shown'], 'center' => $map_center, 'zoom' => $map_zoom))) ?>" style="height: 520px;"></div>
    <ul class="problem-map-legend list-unstyled small d-flex flex-wrap gap-2 mt-2 mb-1">
      <?php foreach ($map_problems['shown'] as $item) { ?>
      <li class="text-nowrap mw-100 text-truncate"><span class="problem-legend-dot<?= $item['active'] ? '' : ' inactive' ?>" style="background: <?= h($item['color']) ?>;"></span>
        <a href="<?= h($self_url . '&view=' . intval($item['id'])) ?>"><?= h($item['title']) ?></a> <span class="text-body-secondary">(<?= h($item['status']) ?>)</span></li>
      <?php } ?>
    </ul>
    <div class="form-text">
      Une couleur par problème ; contour en pointillés : problème résolu ou clos. Problème de rues : ses rues surlignées
      (ses observations rattachées si elles ne sont pas trouvées).
      <?php if ($map_problems['hidden']) { ?><br><?= count($map_problems['hidden']) ?> problème(s) sans position (rues sans observation rattachée) : <?= h(implode(', ', $map_problems['hidden'])) ?>.<?php } ?>
    </div>
  </div>
  <link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
  <script src="assets/vendor/leaflet/leaflet.js"></script>
  <script src="js/street-lines.js?v=<?= intval(@filemtime(__DIR__ . '/../js/street-lines.js')) ?>"></script>
<script src="js/problem-map.js?v=<?= intval(@filemtime(__DIR__ . '/../js/problem-map.js')) ?>"></script>
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
            <?php if (!$readonly) { ?>
            <form method="POST" action="<?= h($self_url . rtrim($filter_query, '&')) ?>" class="d-inline">
              <?= csrf_field() ?>
              <?php if ($broadcast_hooks) { ?>
              <button class="btn btn-sm btn-outline-success" type="submit" name="problem_broadcast" value="<?= $id ?>" data-confirm="Diffuser le problème « <?= h($problem['problem_title']) ?> » (webhooks « Diffusion d'un problème ») et le passer « En attente » ?" title="Appelle les webhooks « Diffusion d'un problème » et passe le statut à « En attente »"><i class="bi bi-megaphone"></i> Diffuser</button>
              <?php } else { ?>
              <span class="d-inline-block" tabindex="0" title="Aucun webhook actif ne suit l'événement « Diffusion d'un problème » (page Webhooks)"><button class="btn btn-sm btn-outline-secondary" type="button" disabled><i class="bi bi-megaphone"></i> Diffuser</button></span>
              <?php } ?>
            </form>
            <a class="btn btn-sm btn-outline-primary" href="<?= h($self_url) ?>&amp;edit=<?= $id ?>"><i class="bi bi-pencil"></i> Modifier</a>
            <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;problemid=<?= $id ?><?= h(csrf_query()) ?>" data-confirm="Supprimer le problème « <?= h($problem['problem_title']) ?> » ? Ses observations sont conservées." aria-label="Supprimer"><i class="bi bi-trash"></i></a>
            <?php } else { ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= h($self_url) ?>&amp;view=<?= $id ?>"><i class="bi bi-eye"></i> Voir</a>
            <?php } ?>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>
</div>
<?php } ?>
