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

/*
 * Problems (#327): published observations grouped by the administrators under a
 * structured problem. An observation matches a problem when it is published (complete and
 * approved), of one of its categories, in its zone (circle or polygon) and in its period.
 * Needs functions.php (distance()).
 */

require_once(dirname(__FILE__) . '/security.php');

define('VIGILO_PROBLEM_RADIUS_MIN', 10);
define('VIGILO_PROBLEM_RADIUS_MAX', 20000);
define('VIGILO_PROBLEM_POLYGON_MAX', 200);

/* Priorities: value stored => label, color of the badge */
function problem_priorities()
{
    return array(1 => array('Basse', 'secondary'), 2 => array('Normale', 'info'),
                 3 => array('Haute', 'warning'), 4 => array('Critique', 'danger'));
}

/* Statuses: value stored => label, color of the badge, active (receives observations automatically) */
function problem_statuses()
{
    return array(
        'open'        => array('Ouvert', 'primary', true),
        'in_progress' => array('En cours', 'info', true),
        'waiting'     => array('En attente', 'warning', true),
        'resolved'    => array('Résolu', 'success', false),
        'closed'      => array('Clos', 'secondary', false),
    );
}

function problem_active_statuses()
{
    $active = array();
    foreach (problem_statuses() as $key => $status) {
        if ($status[2]) {
            $active[] = $key;
        }
    }
    return $active;
}

function problem_load($db, $id)
{
    $query = mysqli_query($db, "SELECT * FROM obs_problems WHERE problem_id = " . intval($id) . " LIMIT 1");
    $row   = $query ? mysqli_fetch_assoc($query) : null;
    return $row ? $row : null;
}

/* Category ids of a problem (comma-separated in problem_categories) */
function problem_category_ids($problem)
{
    $ids = array();
    foreach (explode(',', (string) $problem['problem_categories']) as $id) {
        if (ctype_digit(trim($id))) {
            $ids[] = intval($id);
        }
    }
    return array_values(array_unique($ids));
}

/* Points of a polygon zone: list of array(lat, lon), or an empty list if invalid */
function problem_polygon_points($json)
{
    $points = json_decode((string) $json, true);
    if (!is_array($points)) {
        return array();
    }
    $list = array();
    foreach ($points as $point) {
        if (!is_array($point) || count($point) != 2 || !is_numeric(reset($point)) || !is_numeric(end($point))) {
            return array();
        }
        $point = array_values($point);
        $lat   = (float) $point[0];
        $lon   = (float) $point[1];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return array();
        }
        $list[] = array($lat, $lon);
    }
    return (count($list) >= 3 && count($list) <= VIGILO_PROBLEM_POLYGON_MAX) ? $list : array();
}

/* Bounding box of the zone: array(lat min, lat max, lon min, lon max), null if no valid zone */
function problem_bbox($problem)
{
    if ($problem['problem_zone'] == 'polygon') {
        $points = problem_polygon_points($problem['problem_polygon']);
        if (!$points) {
            return null;
        }
        $lats = array();
        $lons = array();
        foreach ($points as $point) {
            $lats[] = $point[0];
            $lons[] = $point[1];
        }
        return array(min($lats), max($lats), min($lons), max($lons));
    }
    $radius = intval($problem['problem_radius']);
    if ($radius <= 0) {
        return null;
    }
    $lat  = (float) $problem['problem_lat'];
    $lon  = (float) $problem['problem_lon'];
    // A bit larger than the circle (1 degree of latitude ~ 111 km)
    $dlat = $radius / 111000 * 1.01;
    $dlon = $dlat / max(0.01, cos(deg2rad($lat)));
    return array($lat - $dlat, $lat + $dlat, $lon - $dlon, $lon + $dlon);
}

/* Ray casting: is the point inside the polygon (list of array(lat, lon))? */
function problem_point_in_polygon($lat, $lon, $points)
{
    $inside = false;
    $count  = count($points);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $lat_i = $points[$i][0];
        $lon_i = $points[$i][1];
        $lat_j = $points[$j][0];
        $lon_j = $points[$j][1];
        if ((($lat_i > $lat) != ($lat_j > $lat))
            && ($lon < ($lon_j - $lon_i) * ($lat - $lat_i) / ($lat_j - $lat_i) + $lon_i)) {
            $inside = !$inside;
        }
    }
    return $inside;
}

function problem_zone_contains($problem, $lat, $lon)
{
    if ($problem['problem_zone'] == 'polygon') {
        $points = problem_polygon_points($problem['problem_polygon']);
        return $points ? problem_point_in_polygon($lat, $lon, $points) : false;
    }
    $radius = intval($problem['problem_radius']);
    return $radius > 0 && distance((float) $problem['problem_lat'], (float) $problem['problem_lon'], $lat, $lon, 'm') <= $radius;
}

/*
 * SQL condition of the observations that may match the problem: published, categories,
 * period and bounding box of the zone (the exact zone is checked in PHP)
 */
function problem_candidates_where($problem)
{
    $categories = problem_category_ids($problem);
    $bbox       = problem_bbox($problem);
    if (!$categories || !$bbox) {
        return "0";
    }
    $where = "obs_list.obs_complete = 1 AND obs_list.obs_approved = 1"
           . " AND obs_list.obs_categorie IN (" . implode(',', $categories) . ")"
           . " AND obs_list.obs_time >= " . intval($problem['problem_start']);
    if ($problem['problem_end'] !== null && $problem['problem_end'] !== '') {
        $where .= " AND obs_list.obs_time <= " . intval($problem['problem_end']);
    }
    $where .= " AND CAST(obs_list.obs_coordinates_lat AS DECIMAL(10,6)) BETWEEN " . sprintf('%.6F AND %.6F', $bbox[0], $bbox[1])
            . " AND CAST(obs_list.obs_coordinates_lon AS DECIMAL(10,6)) BETWEEN " . sprintf('%.6F AND %.6F', $bbox[2], $bbox[3]);
    return $where;
}

/*
 * Observations matching the problem, with the problem they are attached to (0: none):
 * list of array('id', 'token', 'lat', 'lon', 'time', 'category', 'address', 'problem')
 */
function problem_matching_observations($db, $problem)
{
    $list  = array();
    $query = mysqli_query($db, "SELECT obs_list.obs_id, obs_list.obs_token, obs_list.obs_coordinates_lat, obs_list.obs_coordinates_lon,
                                       obs_list.obs_time, obs_list.obs_categorie, obs_list.obs_address_string,
                                       COALESCE(obs_problem_observations.po_problemid, 0) AS po_problemid
                                FROM obs_list
                                LEFT JOIN obs_problem_observations ON obs_problem_observations.po_obsid = obs_list.obs_id
                                WHERE " . problem_candidates_where($problem) . "
                                ORDER BY obs_list.obs_time ASC");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $lat = (float) $row['obs_coordinates_lat'];
        $lon = (float) $row['obs_coordinates_lon'];
        if (!problem_zone_contains($problem, $lat, $lon)) {
            continue;
        }
        $list[] = array('id' => intval($row['obs_id']), 'token' => (string) $row['obs_token'], 'lat' => $lat, 'lon' => $lon,
                        'time' => intval($row['obs_time']), 'category' => intval($row['obs_categorie']),
                        'address' => (string) $row['obs_address_string'], 'problem' => intval($row['po_problemid']));
    }
    return $list;
}

/* Number of observations attached to each problem: problem_id => count */
function problem_observation_counts($db)
{
    $counts = array();
    $query  = mysqli_query($db, "SELECT po_problemid, COUNT(*) AS nb FROM obs_problem_observations GROUP BY po_problemid");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $counts[intval($row['po_problemid'])] = intval($row['nb']);
    }
    return $counts;
}

/*
 * Validation of the fields of a problem (posted by the form): array(problem, errors).
 * $known_categories: catid => name of the categories that can be chosen.
 */
function problem_from_post($post, $known_categories)
{
    $text = function ($key, $max) use ($post) {
        $value = isset($post[$key]) && is_scalar($post[$key]) ? trim(str_replace("\r\n", "\n", (string) $post[$key])) : '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    };
    $day = function ($key) use ($post) {
        $value = isset($post[$key]) && is_scalar($post[$key]) ? (string) $post[$key] : '';
        return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) ? $value : '';
    };
    $errors     = array();
    $categories = array();
    if (isset($post['problem_categories']) && is_array($post['problem_categories'])) {
        foreach ($post['problem_categories'] as $id) {
            if (is_scalar($id) && ctype_digit((string) $id) && array_key_exists(intval($id), $known_categories)) {
                $categories[] = intval($id);
            }
        }
    }
    $categories = array_values(array_unique($categories));
    $statuses   = problem_statuses();
    $priorities = problem_priorities();
    $problem    = array(
        'problem_id'          => isset($post['problem_id']) ? intval($post['problem_id']) : 0,
        'problem_title'       => $text('problem_title', 200),
        'problem_description' => $text('problem_description', 10000),
        'problem_categories'  => implode(',', $categories),
        'problem_zone'        => (isset($post['problem_zone']) && $post['problem_zone'] === 'polygon') ? 'polygon' : 'circle',
        'problem_lat'         => isset($post['problem_lat']) && is_numeric($post['problem_lat']) ? (float) $post['problem_lat'] : 0,
        'problem_lon'         => isset($post['problem_lon']) && is_numeric($post['problem_lon']) ? (float) $post['problem_lon'] : 0,
        'problem_radius'      => isset($post['problem_radius']) && is_numeric($post['problem_radius']) ? intval($post['problem_radius']) : 0,
        'problem_polygon'     => '',
        'problem_start'       => 0,
        'problem_end'         => null,
        'problem_priority'    => isset($post['problem_priority']) && isset($priorities[intval($post['problem_priority'])]) ? intval($post['problem_priority']) : 2,
        'problem_status'      => isset($post['problem_status']) && is_string($post['problem_status']) && isset($statuses[$post['problem_status']]) ? $post['problem_status'] : 'open',
        'start_day'           => $day('problem_start'),
        'end_day'             => $day('problem_end'),
    );
    if ($problem['problem_title'] === '') {
        $errors[] = 'Le titre est obligatoire.';
    }
    if (!$categories) {
        $errors[] = 'Choisir au moins une catégorie.';
    }
    if ($problem['problem_zone'] == 'polygon') {
        $points = problem_polygon_points(isset($post['problem_polygon']) && is_string($post['problem_polygon']) ? $post['problem_polygon'] : '');
        if (!$points) {
            $errors[] = 'Le polygone doit avoir de 3 à ' . VIGILO_PROBLEM_POLYGON_MAX . ' points.';
        } else {
            $rounded = array();
            foreach ($points as $point) {
                $rounded[] = array(round($point[0], 6), round($point[1], 6));
            }
            $problem['problem_polygon'] = json_encode($rounded);
            // Center of the polygon (bounding box): view of the map, list
            $bbox = problem_bbox($problem);
            $problem['problem_lat'] = round(($bbox[0] + $bbox[1]) / 2, 6);
            $problem['problem_lon'] = round(($bbox[2] + $bbox[3]) / 2, 6);
        }
        $problem['problem_radius'] = 0;
    } else {
        if ($problem['problem_lat'] < -90 || $problem['problem_lat'] > 90 || $problem['problem_lon'] < -180 || $problem['problem_lon'] > 180
            || ($problem['problem_lat'] == 0 && $problem['problem_lon'] == 0)) {
            $errors[] = 'Placer le centre du cercle sur la carte.';
        }
        if ($problem['problem_radius'] < VIGILO_PROBLEM_RADIUS_MIN || $problem['problem_radius'] > VIGILO_PROBLEM_RADIUS_MAX) {
            $errors[] = 'Le rayon doit être compris entre ' . VIGILO_PROBLEM_RADIUS_MIN . ' et ' . VIGILO_PROBLEM_RADIUS_MAX . ' mètres.';
        }
    }
    if ($problem['start_day'] === '') {
        $errors[] = 'La date de début est obligatoire.';
    } else {
        $problem['problem_start'] = strtotime($problem['start_day'] . ' 00:00:00');
    }
    if ($problem['end_day'] !== '') {
        $problem['problem_end'] = strtotime($problem['end_day'] . ' 23:59:59');
        if ($problem['start_day'] !== '' && $problem['end_day'] < $problem['start_day']) {
            $errors[] = 'La date de fin doit suivre la date de début.';
        }
    }
    return array($problem, $errors);
}

/* Summary of the zone, for the lists */
function problem_zone_label($problem)
{
    if ($problem['problem_zone'] == 'polygon') {
        return 'Polygone de ' . count(problem_polygon_points($problem['problem_polygon'])) . ' points';
    }
    return 'Cercle de ' . intval($problem['problem_radius']) . ' m';
}

/*
 * Retroactive attribution, first step (changes nothing): the matching observations split
 * into already attached to this problem, to attach (attached to no problem) and conflicts
 * (attached to another problem, never moved silently)
 */
function problem_count($db, $problem)
{
    $result = array('matching' => 0, 'here' => array(), 'to_attach' => array(), 'conflicts' => array());
    foreach (problem_matching_observations($db, $problem) as $observation) {
        $result['matching']++;
        if ($observation['problem'] == intval($problem['problem_id'])) {
            $result['here'][] = $observation;
        } elseif ($observation['problem'] == 0) {
            $result['to_attach'][] = $observation;
        } else {
            $result['conflicts'][] = $observation;
        }
    }
    return $result;
}

/*
 * Attaches an observation to a problem: 'attached', 'already' (already in this problem),
 * 'conflict' (in another problem: left there), 'unpublished' or 'error'. The unique key on
 * po_obsid makes it safe when two requests attach the same observation.
 */
function problem_attach($db, $problem_id, $obs_id, $mode, $login)
{
    $obs_id = intval($obs_id);
    $query  = mysqli_query($db, "SELECT obs_list.obs_id, COALESCE(obs_problem_observations.po_problemid, 0) AS po_problemid
                                 FROM obs_list LEFT JOIN obs_problem_observations ON obs_problem_observations.po_obsid = obs_list.obs_id
                                 WHERE obs_list.obs_id = " . $obs_id . " AND obs_list.obs_complete = 1 AND obs_list.obs_approved = 1 LIMIT 1");
    $row    = $query ? mysqli_fetch_assoc($query) : null;
    if (!$row) {
        return 'unpublished';
    }
    if (intval($row['po_problemid'])) {
        return intval($row['po_problemid']) == intval($problem_id) ? 'already' : 'conflict';
    }
    $mode = in_array($mode, array('retro', 'auto', 'manual'), true) ? $mode : 'manual';
    $ok   = mysqli_query($db, "INSERT IGNORE INTO obs_problem_observations (po_problemid, po_obsid, po_mode, po_time, po_login)
                               VALUES (" . intval($problem_id) . ", " . $obs_id . ", '" . $mode . "', " . time() . ",
                                       '" . mysqli_real_escape_string($db, (string) $login) . "')");
    if (!$ok) {
        return 'error';
    }
    if (mysqli_affected_rows($db) == 1) {
        return 'attached';
    }
    // Attached meanwhile by another request
    $query = mysqli_query($db, "SELECT po_problemid FROM obs_problem_observations WHERE po_obsid = " . $obs_id);
    $row   = $query ? mysqli_fetch_assoc($query) : null;
    return ($row && intval($row['po_problemid']) == intval($problem_id)) ? 'already' : 'conflict';
}

function problem_detach($db, $problem_id, $obs_id)
{
    mysqli_query($db, "DELETE FROM obs_problem_observations WHERE po_problemid = " . intval($problem_id) . " AND po_obsid = " . intval($obs_id));
    return mysqli_affected_rows($db) == 1;
}

/*
 * Retroactive attribution, second step: the matching observations are computed again
 * (the data may have changed since the count) and those attached to no problem are
 * attached. Idempotent: a second run attaches nothing. Under a lock so that two
 * administrators get consistent results.
 * Returns array('attached' => tokens, 'already' => n, 'conflicts' => observations).
 */
function problem_attach_retro($db, $problem, $login)
{
    $lock   = mysqli_query($db, "SELECT GET_LOCK('vigilo_problem_attach', 10)");
    $locked = $lock && intval(mysqli_fetch_array($lock)[0]) === 1;
    $result = array('attached' => array(), 'already' => 0, 'conflicts' => array());
    foreach (problem_matching_observations($db, $problem) as $observation) {
        $status = problem_attach($db, $problem['problem_id'], $observation['id'], 'retro', $login);
        if ($status == 'attached') {
            $result['attached'][] = $observation['token'];
        } elseif ($status == 'already') {
            $result['already']++;
        } elseif ($status == 'conflict') {
            $result['conflicts'][] = $observation;
        }
    }
    if ($locked) {
        mysqli_query($db, "SELECT RELEASE_LOCK('vigilo_problem_attach')");
    }
    return $result;
}

/* Observations attached to a problem, with the details of the attachment */
function problem_observations($db, $problem_id)
{
    $list  = array();
    $query = mysqli_query($db, "SELECT obs_list.obs_id, obs_list.obs_token, obs_list.obs_coordinates_lat, obs_list.obs_coordinates_lon,
                                       obs_list.obs_time, obs_list.obs_categorie, obs_list.obs_address_string, obs_list.obs_comment,
                                       obs_list.obs_approved, obs_list.obs_archived,
                                       obs_problem_observations.po_mode, obs_problem_observations.po_time, obs_problem_observations.po_login
                                FROM obs_problem_observations
                                INNER JOIN obs_list ON obs_list.obs_id = obs_problem_observations.po_obsid
                                WHERE obs_problem_observations.po_problemid = " . intval($problem_id) . "
                                ORDER BY obs_list.obs_time DESC");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $list[] = $row;
    }
    return $list;
}

/* Titles of the problems: problem_id => title */
function problem_titles($db)
{
    $titles = array();
    $query  = mysqli_query($db, "SELECT problem_id, problem_title FROM obs_problems");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $titles[intval($row['problem_id'])] = (string) $row['problem_title'];
    }
    return $titles;
}

/* Does a published observation (row of obs_list) match the criteria of the problem? */
function problem_matches_observation($problem, $obs)
{
    if (intval($obs['obs_complete']) != 1 || intval($obs['obs_approved']) != 1
        || !in_array(intval($obs['obs_categorie']), problem_category_ids($problem), true)
        || intval($obs['obs_time']) < intval($problem['problem_start'])
        || ($problem['problem_end'] !== null && $problem['problem_end'] !== '' && intval($obs['obs_time']) > intval($problem['problem_end']))) {
        return false;
    }
    return problem_zone_contains($problem, (float) $obs['obs_coordinates_lat'], (float) $obs['obs_coordinates_lon']);
}

/* Active problems (open, in progress, waiting): problem_id => problem */
function problems_active($db)
{
    $problems = array();
    $query    = mysqli_query($db, "SELECT * FROM obs_problems WHERE problem_status IN ('" . implode("','", problem_active_statuses()) . "') ORDER BY problem_id");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $problems[intval($row['problem_id'])] = $row;
    }
    return $problems;
}

/*
 * Automatic attachment, when an observation becomes published (approval, or photo received
 * after the approval): attached to the active problem it matches if there is exactly one;
 * with several, it is left for the administrators ("À arbitrer"). An observation already
 * in a problem is not changed. Returns array(state, problem ids): state 'attached',
 * 'ambiguous', 'none' (no problem, or not published) or 'already'.
 */
function problems_on_publication($db, $token, $login = '', $source = 'admin')
{
    $query = mysqli_query($db, "SELECT obs_list.obs_id, obs_list.obs_token, obs_list.obs_complete, obs_list.obs_approved, obs_list.obs_categorie,
                                       obs_list.obs_time, obs_list.obs_coordinates_lat, obs_list.obs_coordinates_lon,
                                       COALESCE(obs_problem_observations.po_problemid, 0) AS po_problemid
                                FROM obs_list LEFT JOIN obs_problem_observations ON obs_problem_observations.po_obsid = obs_list.obs_id
                                WHERE obs_list.obs_token = '" . mysqli_real_escape_string($db, (string) $token) . "' LIMIT 1");
    $obs   = $query ? mysqli_fetch_assoc($query) : null;
    if (!$obs || intval($obs['obs_complete']) != 1 || intval($obs['obs_approved']) != 1) {
        return array('none', array());
    }
    if (intval($obs['po_problemid'])) {
        return array('already', array(intval($obs['po_problemid'])));
    }
    $matches = array();
    foreach (problems_active($db) as $id => $problem) {
        if (problem_matches_observation($problem, $obs)) {
            $matches[] = $id;
        }
    }
    if (count($matches) == 1) {
        $state = problem_attach($db, $matches[0], $obs['obs_id'], 'auto', $login);
        if ($state == 'attached') {
            audit_log('problem_attach_auto', 'problem:' . $matches[0], array('token' => $obs['obs_token'], 'source' => $source));
            return array('attached', $matches);
        }
        return array($state == 'already' ? 'already' : 'none', $matches);
    }
    if (count($matches) > 1) {
        audit_log('problem_attach_ambiguous', (string) $obs['obs_token'], array('problems' => implode(',', $matches), 'source' => $source));
        return array('ambiguous', $matches);
    }
    return array('none', array());
}

/*
 * Observations to arbitrate: published, in no problem, and matching several active
 * problems. List of array('id', 'token', 'time', 'address', 'category', 'problems' => ids).
 */
function problems_ambiguous($db)
{
    $observations = array();
    foreach (problems_active($db) as $id => $problem) {
        foreach (problem_matching_observations($db, $problem) as $observation) {
            if ($observation['problem']) {
                continue;
            }
            if (!isset($observations[$observation['id']])) {
                $observations[$observation['id']] = $observation;
                $observations[$observation['id']]['problems'] = array();
            }
            $observations[$observation['id']]['problems'][] = $id;
        }
    }
    return array_values(array_filter($observations, function ($observation) {
        return count($observation['problems']) > 1;
    }));
}

/* Thresholds of the suggestions (page Configuration), within sensible bounds */
function problem_suggest_settings($db)
{
    $settings = array('min' => 3, 'radius' => 50, 'months' => 12);
    $keys     = array('vigilo_problem_suggest_min' => 'min', 'vigilo_problem_suggest_radius' => 'radius', 'vigilo_problem_suggest_months' => 'months');
    $query    = mysqli_query($db, "SELECT config_param, config_value FROM obs_config WHERE config_param IN ('" . implode("','", array_keys($keys)) . "')");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        if ($row['config_value'] !== '' && is_numeric($row['config_value'])) {
            $settings[$keys[$row['config_param']]] = intval($row['config_value']);
        }
    }
    $settings['min']    = max(2, min(1000, $settings['min']));
    $settings['radius'] = max(10, min(1000, $settings['radius']));
    $settings['months'] = max(1, min(120, $settings['months']));
    return $settings;
}

/*
 * Suggestions of problems: groups of published observations in no problem, of the same
 * category and close (report_groups() of report.php), of the last months, with at least
 * the minimal number of observations, not dismissed. Never creates anything. Each one:
 * key (category and first observation), category, address, count, first / last dates,
 * center, radius covering the group, tokens. At most VIGILO_PROBLEM_SUGGEST_MAX recent
 * observations are analysed.
 */
define('VIGILO_PROBLEM_SUGGEST_MAX', 3000);

function problem_suggestions($db, $settings)
{
    require_once(dirname(__FILE__) . '/report.php');
    $dismissed = array();
    $query     = mysqli_query($db, "SELECT sd_key FROM obs_problem_suggestions_dismissed");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $dismissed[(string) $row['sd_key']] = true;
    }
    $observations = array();
    $query = mysqli_query($db, "SELECT * FROM (
                                  SELECT obs_list.obs_id, obs_list.obs_token, obs_list.obs_coordinates_lat, obs_list.obs_coordinates_lon,
                                         obs_list.obs_address_string, obs_list.obs_time, obs_list.obs_categorie
                                  FROM obs_list LEFT JOIN obs_problem_observations ON obs_problem_observations.po_obsid = obs_list.obs_id
                                  WHERE obs_problem_observations.po_obsid IS NULL AND obs_list.obs_complete = 1 AND obs_list.obs_approved = 1
                                    AND obs_list.obs_time >= " . intval(strtotime('-' . intval($settings['months']) . ' months')) . "
                                  ORDER BY obs_list.obs_time DESC LIMIT " . VIGILO_PROBLEM_SUGGEST_MAX . "
                                ) recent ORDER BY obs_time ASC");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $observations[] = array('id' => intval($row['obs_id']), 'token' => (string) $row['obs_token'],
                                'lat' => (float) $row['obs_coordinates_lat'], 'lon' => (float) $row['obs_coordinates_lon'],
                                'address' => (string) $row['obs_address_string'], 'time' => intval($row['obs_time']),
                                'category' => intval($row['obs_categorie']), 'city' => '', 'status' => 0);
    }
    $suggestions = array();
    foreach (report_groups($observations, $settings['radius']) as $group) {
        if ($group['count'] < $settings['min']) {
            continue;
        }
        $key = $group['category'] . ':' . $group['observations'][0]['id'];
        if (isset($dismissed[$key])) {
            continue;
        }
        $radius = $settings['radius'];
        $tokens = array();
        foreach ($group['observations'] as $obs) {
            $radius   = max($radius, (int) ceil(distance($group['center'][0], $group['center'][1], $obs['lat'], $obs['lon'], 'm')) + 10);
            $tokens[] = $obs['token'];
        }
        $suggestions[] = array('key' => $key, 'category' => $group['category'], 'address' => $group['address'], 'count' => $group['count'],
                               'first' => $group['first'], 'last' => $group['last'], 'lat' => round($group['center'][0], 6),
                               'lon' => round($group['center'][1], 6), 'radius' => min(VIGILO_PROBLEM_RADIUS_MAX, $radius), 'tokens' => $tokens);
    }
    return array('suggestions' => $suggestions, 'analysed' => count($observations));
}

function problem_suggestion_dismiss($db, $key, $login)
{
    if (!preg_match('/^[0-9]+:[0-9]+$/', (string) $key)) {
        return false;
    }
    return (bool) mysqli_query($db, "INSERT IGNORE INTO obs_problem_suggestions_dismissed (sd_key, sd_time, sd_login)
                                     VALUES ('" . mysqli_real_escape_string($db, $key) . "', " . time() . ",
                                             '" . mysqli_real_escape_string($db, (string) $login) . "')");
}

/*
 * Problems of a report (section of admin/report.php, administrators only): the problems
 * having observations among those of the report, with their number in the report and in
 * total, first and last dates in the report. The internal notes are never part of it.
 */
function problem_report_rows($db, $observations)
{
    $in_report = array();
    foreach ($observations as $obs) {
        $in_report[intval($obs['id'])] = intval($obs['time']);
    }
    if (!$in_report) {
        return array();
    }
    $rows  = array();
    $query = mysqli_query($db, "SELECT obs_problems.*, obs_problem_observations.po_obsid
                                FROM obs_problem_observations
                                INNER JOIN obs_problems ON obs_problems.problem_id = obs_problem_observations.po_problemid
                                WHERE obs_problem_observations.po_obsid IN (" . implode(',', array_keys($in_report)) . ")");
    while ($query && ($row = mysqli_fetch_assoc($query))) {
        $id   = intval($row['problem_id']);
        $time = $in_report[intval($row['po_obsid'])];
        if (!isset($rows[$id])) {
            unset($row['po_obsid']);
            $rows[$id] = array('problem' => $row, 'count' => 0, 'first' => $time, 'last' => $time);
        }
        $rows[$id]['count']++;
        $rows[$id]['first'] = min($rows[$id]['first'], $time);
        $rows[$id]['last']  = max($rows[$id]['last'], $time);
    }
    $totals = problem_observation_counts($db);
    foreach ($rows as $id => &$row) {
        $row['total'] = isset($totals[$id]) ? $totals[$id] : $row['count'];
    }
    unset($row);
    usort($rows, function ($a, $b) {
        if ($a['problem']['problem_priority'] != $b['problem']['problem_priority']) {
            return intval($b['problem']['problem_priority']) - intval($a['problem']['problem_priority']);
        }
        return $b['count'] - $a['count'];
    });
    return array_values($rows);
}
