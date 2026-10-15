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
