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
 * Report for a local authority (admin page "Rapports", admin/report.php): published observations
 * of some cities, categories and period, key figures, statistics, and similar observations grouped
 * (same category, close to each other or at the same address).
 */

/* Status names of an observation (see resolution_status_from_rank()) */
/* Names and colors of the categories: id => array(name, color) */
function report_category_list()
{
    $categories = array();
    foreach ((array) getCategoriesList() as $categorie) {
        if (isset($categorie['catid'])) {
            $categories[intval($categorie['catid'])] = array('name' => (string) $categorie['catname'],
                'color' => isset($categorie['catcolor']) && $categorie['catcolor'] !== '' ? (string) $categorie['catcolor'] : '#9e9e9e');
        }
    }
    return $categories;
}

function report_category($categories, $id)
{
    return isset($categories[$id]) ? $categories[$id] : array('name' => 'Catégorie ' . intval($id), 'color' => '#9e9e9e');
}

function report_month_names()
{
    return array(1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.');
}

function report_date($time)
{
    return date('d/m/Y', $time);
}

function report_number($value, $decimals = 0)
{
    return number_format((float) $value, $decimals, ',', "\u{202F}");
}

/*
 * Link of an observation in the web application, as {{observation_url}} of the webhooks: instance
 * of the scope of this backend (vigilo-conf), VIGILO_WEBAPP_URL (app.vigilo.city by default).
 */
function report_observation_url($db, $token)
{
    static $base = null;
    if ($base === null) {
        global $config;
        $instance = false;
        $query = mysqli_query($db, "SELECT scope_name FROM obs_scopes ORDER BY scope_id LIMIT 1");
        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $instance = getInstanceNameFromFirebase($row['scope_name']);
        }
        $base = $config['VIGILO_WEBAPP_URL'] . '?' . ($instance ? 'instance=' . rawurlencode($instance) . '&' : '');
    }
    return $base . 'token=' . rawurlencode((string) $token);
}

/* Token of an observation as a link to it in the web application (new tab) */
function report_token_link($db, $token)
{
    return '<a class="token-link" href="' . h(report_observation_url($db, $token)) . '" target="_blank" rel="noopener">' . h($token) . '</a>';
}

/*
 * Comments shown for a problem: the most recent non-empty ones, each text once (without case),
 * at most $count; rows with token, time and comment.
 */
function report_recent_comments($observations, $count)
{
    usort($observations, function ($a, $b) {
        return intval($b['time']) - intval($a['time']);
    });
    $shown = array();
    $seen  = array();
    foreach ($observations as $obs) {
        $text = isset($obs['comment']) ? trim(preg_replace('/\s+/u', ' ', (string) $obs['comment'])) : '';
        $key  = vigilo_ascii_lower($text);
        if ($text === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $shown[]    = array('token' => $obs['token'], 'time' => $obs['time'], 'comment' => $text);
        if (count($shown) >= $count) {
            break;
        }
    }
    return $shown;
}

/* HTML list of comments (report_recent_comments()), tokens linked to the web application */
function report_comments_html($db, $comments)
{
    $html = '<ul class="obs-comments">';
    foreach ($comments as $comment) {
        $html .= '<li><q>' . h($comment['comment']) . '</q> <span class="note">' . report_token_link($db, $comment['token']) . ' · ' . h(report_date($comment['time'])) . '</span></li>';
    }
    return $html . '</ul>';
}

/* Does an observation have a photo file? */
function report_photo_exists($token)
{
    global $config;
    return file_exists(dirname(__FILE__) . '/../' . $config['DATA_PATH'] . 'images/' . basename((string) $token) . '.jpg');
}

/* Source of the photo of an observation (admin pages): the placeholder image when it has no photo file (printed as is) */
function report_photo_src($token, $width)
{
    return report_photo_exists($token) ? 'photo.php?s=' . intval($width) . '&token=' . urlencode($token) : 'assets/photo-missing.svg';
}

/*
 * Observations whose photo is shown (rows with token and time): the most recent ones that have a
 * photo file, at most $count; when none has one, the most recent observation (placeholder image).
 */
function report_recent_photos($observations, $count)
{
    usort($observations, function ($a, $b) {
        return intval($b['time']) - intval($a['time']);
    });
    $shown = array();
    foreach ($observations as $obs) {
        if (count($shown) >= $count) {
            break;
        }
        if (report_photo_exists($obs['token'])) {
            $shown[] = $obs;
        }
    }
    return ($shown || !$observations) ? $shown : array($observations[0]);
}

function report_status_names()
{
    return array(0 => 'Sans suite', 2 => 'Prise en compte', 3 => 'En cours de résolution', 4 => 'Indiquée résolue', 1 => 'Résolue');
}

function report_status_colors()
{
    return array(0 => '#9aa4b1', 2 => '#4c8dff', 3 => '#f2a20c', 4 => '#5ec27a', 1 => '#2e7d32');
}

/*
 * Filters from the request: cities (ids), categories (ids), from / to (aaaa-mm-jj), radius (m).
 * $allowed_cities: cities a citystaff account may see (null: all). Returns the normalized filters.
 */
function report_filters($get, $allowed_cities)
{
    $ints = function ($key) use ($get) {
        $values = isset($get[$key]) && is_array($get[$key]) ? $get[$key] : array();
        return array_values(array_unique(array_filter(array_map('intval', $values), function ($v) { return $v > 0; })));
    };
    $cities = $ints('cities');
    if ($allowed_cities !== null) {
        $cities = count($cities) ? array_values(array_intersect($cities, $allowed_cities)) : $allowed_cities;
    }
    $day = function ($key, $default) use ($get) {
        $value = isset($get[$key]) && is_scalar($get[$key]) ? (string) $get[$key] : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return $value;
        }
        return $default;
    };
    $to   = $day('to', date('Y-m-d'));
    $from = $day('from', date('Y-m-d', strtotime($to . ' -1 year +1 day')));
    if ($from > $to) {
        $tmp  = $from;
        $from = $to;
        $to   = $tmp;
    }
    $radius = isset($get['radius']) && in_array(intval($get['radius']), array(25, 50, 100, 200), true) ? intval($get['radius']) : 50;
    $title  = isset($get['title']) && is_scalar($get['title']) ? vigilo_report_truncate(trim((string) $get['title']), 120) : '';
    return array('cities' => $cities, 'categories' => $ints('categories'), 'from' => $from, 'to' => $to,
                 'radius' => $radius, 'title' => $title, 'restricted' => $allowed_cities !== null);
}

function vigilo_report_truncate($text, $length)
{
    return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
}

/* Time bounds of the period (timestamps of the first and last second) */
function report_bounds($filters)
{
    return array(strtotime($filters['from'] . ' 00:00:00'), strtotime($filters['to'] . ' 23:59:59'));
}

/* SQL condition of the filters, without the period */
function report_where($filters)
{
    $where = "obs_complete=1 AND obs_approved=1";
    if (count($filters['cities'])) {
        $where .= " AND obs_city IN (" . implode(',', array_map('intval', $filters['cities'])) . ")";
    } elseif ($filters['restricted']) {
        $where .= " AND 0";
    }
    if (count($filters['categories'])) {
        $where .= " AND obs_categorie IN (" . implode(',', array_map('intval', $filters['categories'])) . ")";
    }
    // Given observations only (report of a problem)
    if (isset($filters['ids'])) {
        $ids = array_values(array_filter(array_map('intval', (array) $filters['ids'])));
        $where .= $ids ? " AND obs_id IN (" . implode(',', $ids) . ")" : " AND 0";
    }
    return $where;
}

/*
 * Published observations of the filters and period (archived ones included: they stay in the
 * statistics), with their status (most advanced resolution) and the date of their resolution.
 */
function report_observations($db, $filters, $start = null, $end = null)
{
    if ($start === null) {
        list($start, $end) = report_bounds($filters);
    }
    $query = mysqli_query($db, "SELECT obs_id, obs_token, obs_city, obs_cityname, obs_cities.city_name,
        obs_coordinates_lat, obs_coordinates_lon, obs_address_string, obs_comment, obs_time, obs_categorie,
        COALESCE(obs_res.resolution_rank,0) resolution_rank, COALESCE(obs_res.resolved_time,0) resolved_time
      FROM obs_list
      LEFT JOIN obs_cities ON obs_list.obs_city = obs_cities.city_id
      LEFT JOIN (
        SELECT obs_resolutions_tokens.restok_observationid,
               MAX(" . resolution_rank_sql('obs_resolutions.resolution_status') . ") resolution_rank,
               MAX(CASE WHEN obs_resolutions.resolution_status = 1 THEN obs_resolutions.resolution_time ELSE 0 END) resolved_time
        FROM obs_resolutions_tokens
        INNER JOIN obs_resolutions ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid
        GROUP BY obs_resolutions_tokens.restok_observationid
      ) obs_res ON obs_list.obs_id = obs_res.restok_observationid
      WHERE " . report_where($filters) . " AND obs_time >= " . intval($start) . " AND obs_time <= " . intval($end) . "
      ORDER BY obs_time ASC");
    $list = array();
    while ($query && $row = mysqli_fetch_assoc($query)) {
        $city = !empty($row['city_name']) ? (string) $row['city_name'] : (string) $row['obs_cityname'];
        $address = trim((string) $row['obs_address_string']);
        if (intval($row['obs_city']) == 0 && $city === '' && preg_match('/^([^,]*),([^,]*)$/', $address, $m)) {
            $address = trim($m[1]);
            $city = trim($m[2]);
        }
        $list[] = array(
            'id' => intval($row['obs_id']),
            'token' => (string) $row['obs_token'],
            'city' => $city,
            'lat' => (float) $row['obs_coordinates_lat'],
            'lon' => (float) $row['obs_coordinates_lon'],
            'address' => $address,
            'comment' => (string) $row['obs_comment'],
            'time' => intval($row['obs_time']),
            'category' => intval($row['obs_categorie']),
            'status' => resolution_status_from_rank($row['resolution_rank']),
            'resolved_time' => intval($row['resolved_time']),
        );
    }
    return $list;
}

/* Street of an address, to compare addresses ("12 rue X" and "rue X" are the same place) */
function report_street($address)
{
    return vigilo_street_key($address);
}

/*
 * Similar observations grouped: same category, and closer than $radius metres to the first one
 * of the group or at the same street. Groups sorted by number of observations, then most recent.
 * $mode: 'both' (close or same street within 1 km), 'radius' (close only), 'street' (same street
 * of the same city, whatever the distance; observations without street are left out).
 */
function report_groups($observations, $radius, $mode = 'both')
{
    $groups = array();
    foreach ($observations as $obs) {
        $found = null;
        $city  = isset($obs['city']) ? vigilo_ascii_lower($obs['city']) : '';
        if ($mode == 'street' && report_street($obs['address']) === '') {
            continue;
        }
        foreach ($groups as $key => $group) {
            if ($group['category'] != $obs['category']) {
                continue;
            }
            if ($mode == 'street') {
                $close = false;
                $same_street = $group['street'] === report_street($obs['address']) && $group['city'] === $city;
            } else {
                $close = distance($group['lat'], $group['lon'], $obs['lat'], $obs['lon'], 'm') <= $radius;
                $same_street = $mode == 'both' && $group['street'] !== '' && $group['street'] === report_street($obs['address'])
                    && distance($group['lat'], $group['lon'], $obs['lat'], $obs['lon'], 'm') <= 1000;
            }
            if ($close || $same_street) {
                $found = $key;
                break;
            }
        }
        if ($found === null) {
            $groups[] = array('category' => $obs['category'], 'lat' => $obs['lat'], 'lon' => $obs['lon'],
                              'street' => report_street($obs['address']), 'city' => $city, 'observations' => array());
            $found = count($groups) - 1;
        }
        $groups[$found]['observations'][] = $obs;
    }
    foreach ($groups as &$group) {
        $list = $group['observations'];
        $addresses = array();
        $cities = array();
        $statuses = array();
        $lat = 0;
        $lon = 0;
        foreach ($list as $obs) {
            $addresses[$obs['address']] = (isset($addresses[$obs['address']]) ? $addresses[$obs['address']] : 0) + 1;
            if ($obs['city'] !== '') {
                $cities[$obs['city']] = true;
            }
            $statuses[$obs['status']] = (isset($statuses[$obs['status']]) ? $statuses[$obs['status']] : 0) + 1;
            $lat += $obs['lat'];
            $lon += $obs['lon'];
        }
        arsort($addresses);
        $group['address'] = (string) key($addresses);
        $group['cities'] = array_keys($cities);
        $group['statuses'] = $statuses;
        $group['count'] = count($list);
        $group['first'] = $list[0]['time'];
        $group['last'] = $list[count($list) - 1]['time'];
        $group['center'] = array($lat / count($list), $lon / count($list));
        $group['resolved'] = isset($statuses[1]) ? $statuses[1] : 0;
        $group['photo'] = $list[count($list) - 1]['token'];
    }
    unset($group);
    usort($groups, function ($a, $b) {
        if ($a['count'] != $b['count']) {
            return $b['count'] - $a['count'];
        }
        return $b['last'] - $a['last'];
    });
    return $groups;
}

function report_median($values)
{
    if (count($values) == 0) {
        return null;
    }
    sort($values);
    $middle = intval(floor(count($values) / 2));
    return (count($values) % 2) ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

/* Count of the values of a key, sorted by count */
function report_count_by($observations, $key)
{
    $counts = array();
    foreach ($observations as $obs) {
        $value = $obs[$key];
        $counts[$value] = (isset($counts[$value]) ? $counts[$value] : 0) + 1;
    }
    arsort($counts);
    return $counts;
}

/*
 * Key figures and statistics of the report. $previous: number of observations of the period of
 * the same length just before. $population: inhabitants of the cities of the report (0: unknown).
 */
function report_stats($observations, $groups, $filters, $previous, $population)
{
    list($start, $end) = report_bounds($filters);
    $total = count($observations);
    $months = max(1, intval(round(($end - $start) / (30.44 * 86400))));
    $statuses = array(0 => 0, 2 => 0, 3 => 0, 4 => 0, 1 => 0);
    $delays = array();
    $weekdays = array_fill(1, 7, 0);
    $hours = array_fill(0, 24, 0);
    foreach ($observations as $obs) {
        $statuses[$obs['status']]++;
        if ($obs['status'] == 1 && $obs['resolved_time'] > $obs['time']) {
            $delays[] = ($obs['resolved_time'] - $obs['time']) / 86400;
        }
        $weekdays[intval(date('N', $obs['time']))]++;
        $hours[intval(date('G', $obs['time']))]++;
    }
    // observations by month, every month of the period (empty ones too)
    $by_month = array();
    for ($month = strtotime(date('Y-m-01', $start)); $month <= $end; $month = strtotime('+1 month', $month)) {
        $by_month[date('Y-m', $month)] = 0;
    }
    foreach ($observations as $obs) {
        $key = date('Y-m', $obs['time']);
        if (isset($by_month[$key])) {
            $by_month[$key]++;
        }
    }
    $recurring = 0;
    $recurring_obs = 0;
    foreach ($groups as $group) {
        if ($group['count'] > 1) {
            $recurring++;
            $recurring_obs += $group['count'];
        }
    }
    $streets = vigilo_count_streets(array_map(function ($obs) { return $obs['address']; }, $observations));
    $in_progress = $statuses[2] + $statuses[3] + $statuses[4];
    return array(
        'total' => $total,
        'per_month' => $total / $months,
        'previous' => $previous,
        'evolution' => $previous > 0 ? ($total - $previous) / $previous * 100 : null,
        'statuses' => $statuses,
        'resolved' => $statuses[1],
        'resolved_rate' => $total ? $statuses[1] / $total * 100 : 0,
        'in_progress' => $in_progress,
        'handled_rate' => $total ? ($statuses[1] + $in_progress) / $total * 100 : 0,
        'median_delay' => report_median($delays),
        'places' => count($groups),
        'recurring' => $recurring,
        'recurring_obs' => $recurring_obs,
        'by_month' => $by_month,
        'by_category' => report_count_by($observations, 'category'),
        'by_city' => report_count_by($observations, 'city'),
        'by_street' => array_slice($streets, 0, 10, true),
        'weekdays' => $weekdays,
        'hours' => $hours,
        'population' => $population,
        'per_10000' => $population > 0 ? $total / $population * 10000 : null,
    );
}

/*
 * Sections of the report, in their order: key => array(title, administrators only,
 * included by default). Chosen in the form (sections[], with s=1); without s (old links),
 * the default ones, the problems only with problems=1.
 */
function report_sections()
{
    return array(
        'kpis'            => array('Chiffres clés', false, true),
        'monthly'         => array('Évolution mensuelle', false, true),
        'categories'      => array('Types d\'observation', false, true),
        'statuses'        => array('État de traitement', false, true),
        'cities'          => array('Répartition par ville', false, true),
        'streets'         => array('Rues les plus concernées', false, true),
        'times'           => array('Jours et heures', false, true),
        'map'             => array('Carte des lieux', false, true),
        'problems'        => array('Problèmes suivis (tableau)', true, true),
        'problem_details' => array('Détail de chaque problème (carte, chronologie, horaires, photos)', true, true),
        'groups'          => array('Lieux récurrents (sans ceux déjà suivis par un problème)', false, true),
        'method'          => array('Méthode', false, true),
    );
}

/* Sections chosen for a request: key => true */
function report_chosen_sections($get, $is_admin)
{
    $chosen = array();
    foreach (report_sections() as $key => $section) {
        if ($section[1] && !$is_admin) {
            continue;
        }
        if (isset($get['s'])) {
            $on = isset($get['sections']) && is_array($get['sections']) && in_array($key, $get['sections'], true);
        } elseif ($section[1]) {
            $on = !empty($get['problems']);
        } else {
            $on = $section[2];
        }
        if ($on) {
            $chosen[$key] = true;
        }
    }
    return $chosen;
}

/* ---- SVG charts (no library: printed as they are seen) ---- */

function report_svg_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/* Vertical bars: $values label => number */
function report_svg_columns($values, $color, $title)
{
    $width = 720;
    $height = 240;
    $left = 30;
    $bottom = 40;
    $max = max(1, max(array_values($values) ?: array(0)));
    $n = max(1, count($values));
    $slot = ($width - $left) / $n;
    $bar = max(2, $slot * 0.7);
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . report_svg_escape($title) . '" class="report-chart">';
    foreach (array(0, 0.5, 1) as $step) {
        $y = ($height - $bottom) - ($height - $bottom - 10) * $step;
        $svg .= '<line x1="' . $left . '" x2="' . $width . '" y1="' . $y . '" y2="' . $y . '" class="grid"/>'
              . '<text x="' . ($left - 4) . '" y="' . ($y + 4) . '" text-anchor="end" class="axis">' . round($max * $step) . '</text>';
    }
    $i = 0;
    $every = max(1, intval(ceil($n / 12)));
    foreach ($values as $label => $value) {
        $h = ($height - $bottom - 10) * $value / $max;
        $x = $left + $i * $slot + ($slot - $bar) / 2;
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($height - $bottom - $h, 1) . '" width="' . round($bar, 1) . '" height="' . round($h, 1) . '" fill="' . report_svg_escape($color) . '" rx="2"><title>' . report_svg_escape($label . ' : ' . $value) . '</title></rect>';
        if ($value > 0 && $n <= 24) {
            $svg .= '<text x="' . round($x + $bar / 2, 1) . '" y="' . round($height - $bottom - $h - 4, 1) . '" text-anchor="middle" class="value">' . intval($value) . '</text>';
        }
        if ($i % $every == 0) {
            $svg .= '<text x="' . round($x + $bar / 2, 1) . '" y="' . ($height - $bottom + 16) . '" text-anchor="middle" class="axis">' . report_svg_escape($label) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

/* Number of observations per day of the week (Monday first) and hour: 7 rows of 24 numbers */
function report_week_grid($observations)
{
    $grid = array_fill(0, 7, array_fill(0, 24, 0));
    foreach ($observations as $obs) {
        $time = intval(isset($obs['time']) ? $obs['time'] : (isset($obs['obs_time']) ? $obs['obs_time'] : 0));
        if ($time > 0) {
            $grid[intval(date('N', $time)) - 1][intval(date('G', $time))]++;
        }
    }
    return $grid;
}

/*
 * Matrix days × hours, squares darker with the number of observations (same ramp and steps
 * as the statistics of the web application)
 */
function report_svg_heatmap($grid, $title)
{
    $ramp  = report_heat_ramp();
    $days  = array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');
    $cell  = 26;
    $gap   = 3;
    $left  = 44;
    $top   = 18;
    $width = $left + 24 * ($cell + $gap);
    $max   = 0;
    foreach ($grid as $row) {
        $max = max($max, max($row));
    }
    $height = $top + 7 * ($cell + $gap) + 30;
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . report_svg_escape($title) . '" class="report-chart report-heatmap">';
    for ($h = 0; $h < 24; $h += 3) {
        $svg .= '<text x="' . ($left + $h * ($cell + $gap)) . '" y="12" class="axis">' . $h . ' h</text>';
    }
    $busiest = array(0, '');
    foreach ($grid as $d => $row) {
        $y = $top + $d * ($cell + $gap);
        $svg .= '<text x="' . ($left - 6) . '" y="' . ($y + $cell / 2 + 4) . '" text-anchor="end" class="axis">' . report_svg_escape(substr($days[$d], 0, 3) . '.') . '</text>';
        foreach ($row as $h => $count) {
            $step = report_heat_step($count, $max);
            $tip  = $days[$d] . ' ' . $h . ' h – ' . ($h + 1) . ' h : ' . $count . ' observation' . ($count > 1 ? 's' : '');
            $svg .= '<rect x="' . ($left + $h * ($cell + $gap)) . '" y="' . $y . '" width="' . $cell . '" height="' . $cell . '" rx="4" fill="' . $ramp[$step] . '"'
                  . ($step == 0 ? ' class="empty"' : '') . '><title>' . report_svg_escape($tip) . '</title></rect>';
            if ($count > $busiest[0]) {
                $busiest = array($count, $tip);
            }
        }
    }
    // Scale: less → more
    $y  = $top + 7 * ($cell + $gap) + 8;
    $x  = $width - (count($ramp) - 1) * 16 - 40;
    $svg .= '<text x="' . ($x - 6) . '" y="' . ($y + 11) . '" text-anchor="end" class="axis">moins</text>';
    for ($i = 1; $i < count($ramp); $i++) {
        $svg .= '<rect x="' . ($x + ($i - 1) * 16) . '" y="' . $y . '" width="13" height="13" rx="3" fill="' . $ramp[$i] . '"/>';
    }
    $svg .= '<text x="' . ($x + (count($ramp) - 1) * 16 + 4) . '" y="' . ($y + 11) . '" class="axis">plus</text>';
    if ($busiest[0] > 0) {
        $svg .= '<text x="0" y="' . ($y + 11) . '" class="axis">Le plus fréquent : ' . report_svg_escape($busiest[1]) . '</text>';
    }
    return $svg . '</svg>';
}

/* Ramp of the matrices and timelines (same as the statistics of the web application) and step of a number */
function report_heat_ramp()
{
    return array('#f4f5f7', '#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#104281');
}

function report_heat_step($count, $max)
{
    $n = count(report_heat_ramp());
    return $count == 0 || $max == 0 ? 0 : min($n - 1, 1 + (int) floor($count / $max * ($n - 1) - 1e-9));
}

/*
 * Compact horizontal timeline: one square per period, darker with the number of observations;
 * $months 'YYYY-MM' => number, grouped by quarter beyond 4 years and by year beyond 12 years.
 * Years below, scale and busiest period. $milestones (list of array('time', 'label')): numbered
 * flags above their period (the months must cover them, report_months_cover()).
 */
function report_svg_strip($months, $title, $milestones = array())
{
    $ramp  = report_heat_ramp();
    $names = array(1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre');
    $unit  = count($months) > 144 ? 'year' : (count($months) > 48 ? 'quarter' : 'month');
    $key_of = function ($month) use ($unit, $names) {
        $year = substr($month, 0, 4);
        $m    = intval(substr($month, 5, 2));
        if ($unit == 'year') {
            return array($year, $year);
        }
        if ($unit == 'quarter') {
            $q = intval(ceil($m / 3));
            return array($year . '-T' . $q, $q . ($q == 1 ? 'er' : 'e') . ' trimestre ' . $year);
        }
        return array($month, $names[$m] . ' ' . $year);
    };
    // Periods: key => array(year, label, number)
    $periods = array();
    foreach ($months as $month => $count) {
        list($key, $label) = $key_of($month);
        if (!isset($periods[$key])) {
            $periods[$key] = array(substr($month, 0, 4), $label, 0);
        }
        $periods[$key][2] += $count;
    }
    $n     = max(1, count($periods));
    $width = 760;
    $gap   = 2;
    $cell  = min(28, ($width - ($n - 1) * $gap) / $n);
    $top   = $milestones ? 26 : 0;
    $max   = 0;
    foreach ($periods as $period) {
        $max = max($max, $period[2]);
    }
    $svg   = '<svg viewBox="0 0 ' . $width . ' ' . (64 + $top) . '" role="img" aria-label="' . report_svg_escape($title) . '" class="report-chart report-heatmap report-strip">';
    $i     = 0;
    $last_label = -1000;
    $last_year  = '';
    $busiest    = array(0, '');
    $x_of       = array();
    foreach ($periods as $key => $period) {
        list($year, $label, $count) = $period;
        $x    = round($i * ($cell + $gap), 2);
        $x_of[$key] = $x;
        $step = report_heat_step($count, $max);
        $svg .= '<rect x="' . $x . '" y="' . $top . '" width="' . round($cell, 2) . '" height="26" rx="' . ($cell > 8 ? 3 : 1) . '" fill="' . $ramp[$step] . '"'
              . ($step == 0 ? ' class="empty"' : '') . '><title>' . report_svg_escape($label . ' : ' . $count . ' observation' . ($count > 1 ? 's' : '')) . '</title></rect>';
        // Year at its first period, unless too close to the previous label
        if ($year !== $last_year && $x - $last_label >= 34) {
            $svg .= '<line x1="' . $x . '" x2="' . $x . '" y1="' . ($top + 28) . '" y2="' . ($top + 34) . '" class="grid"/>'
                  . '<text x="' . $x . '" y="' . ($top + 45) . '" class="axis">' . report_svg_escape($year) . '</text>';
            $last_label = $x;
        }
        $last_year = $year;
        if ($count > $busiest[0]) {
            $busiest = array($count, $label);
        }
        $i++;
    }
    // Milestones: one flag per period, with the numbers of its milestones
    $flags  = array();
    $titles = array();
    foreach (array_values($milestones) as $k => $milestone) {
        list($key) = $key_of(date('Y-m', intval($milestone['time'])));
        if (isset($x_of[$key])) {
            $flags[$key][] = $k + 1;
            $titles[$key][] = ($k + 1) . '. ' . date('d/m/Y', intval($milestone['time'])) . ' : ' . $milestone['label'];
        }
    }
    foreach ($flags as $key => $numbers) {
        $x = round($x_of[$key] + $cell / 2, 2);
        $text = implode(',', $numbers);
        $w = max(16, 7 * strlen($text) + 8);
        $left = max(0, min($width - $w, $x - $w / 2));
        $svg .= '<g class="milestone"><title>' . report_svg_escape(implode("\n", $titles[$key])) . '</title>'
              . '<line x1="' . $x . '" x2="' . $x . '" y1="17" y2="' . ($top + 26) . '" stroke="#d9480f" stroke-width="2"/>'
              . '<rect x="' . round($left, 2) . '" y="0" width="' . $w . '" height="17" rx="8" fill="#d9480f"/>'
              . '<text x="' . round($left + $w / 2, 2) . '" y="12.5" text-anchor="middle" fill="#fff" font-size="11" font-weight="700">' . report_svg_escape($text) . '</text></g>';
    }
    $x = $width - (count($ramp) - 1) * 16 - 32;
    $svg .= '<text x="' . ($x - 6) . '" y="' . ($top + 61) . '" text-anchor="end" class="axis">moins</text>';
    for ($k = 1; $k < count($ramp); $k++) {
        $svg .= '<rect x="' . ($x + ($k - 1) * 16) . '" y="' . ($top + 51) . '" width="13" height="13" rx="3" fill="' . $ramp[$k] . '"/>';
    }
    $svg .= '<text x="' . ($x + (count($ramp) - 1) * 16 + 4) . '" y="' . ($top + 61) . '" class="axis">plus</text>';
    if ($busiest[0] > 0) {
        $what = array('month' => 'Mois', 'quarter' => 'Trimestre', 'year' => 'Année');
        $svg .= '<text x="0" y="' . ($top + 61) . '" class="axis">' . $what[$unit] . ' le plus chargé' . ($unit == 'year' ? 'e' : '') . ' : '
              . report_svg_escape($busiest[1] . ' (' . $busiest[0] . ')') . '</text>';
    }
    return $svg . '</svg>';
}

/* Months 'YYYY-MM' => number extended (with 0) to cover $times, without a gap, at most 240 months */
function report_months_cover($months, $times)
{
    $keys = array_keys($months);
    foreach ($times as $time) {
        $keys[] = date('Y-m', intval($time));
    }
    if (!$keys) {
        return $months;
    }
    sort($keys);
    $result = array();
    $month  = strtotime($keys[0] . '-01');
    $last   = end($keys);
    while (count($result) < 240) {
        $key = date('Y-m', $month);
        $result[$key] = isset($months[$key]) ? $months[$key] : 0;
        if ($key >= $last) {
            break;
        }
        $month = strtotime('+1 month', $month);
    }
    return $result;
}

/*
 * Legend of the milestones of report_svg_strip() (list of array('time', 'label', 'text')),
 * numbered in the same order; $with_text: text of the note under its milestone.
 */
function report_milestones_html($milestones, $with_text = true)
{
    if (!$milestones) {
        return '';
    }
    $html = '<ol class="milestones">';
    foreach (array_values($milestones) as $milestone) {
        $html .= '<li><span class="milestone-date">' . h(date('d/m/Y', intval($milestone['time']))) . '</span> <strong>' . h($milestone['label']) . '</strong>'
               . ($with_text && isset($milestone['text']) && $milestone['text'] !== '' ? '<div class="milestone-text">' . nl2br(h($milestone['text'])) . '</div>' : '') . '</li>';
    }
    return $html . '</ol>';
}

/* Horizontal bars: rows of array(label, value, color) */
function report_svg_bars($rows, $title)
{
    $row_height = 26;
    $label_width = 230;
    $width = 720;
    $height = max(1, count($rows)) * $row_height + 6;
    $max = 1;
    foreach ($rows as $row) {
        $max = max($max, $row[1]);
    }
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . report_svg_escape($title) . '" class="report-chart">';
    foreach (array_values($rows) as $i => $row) {
        $y = $i * $row_height + 3;
        $w = ($width - $label_width - 50) * $row[1] / $max;
        $label = vigilo_report_truncate((string) $row[0], 34);
        $svg .= '<text x="' . ($label_width - 8) . '" y="' . ($y + 16) . '" text-anchor="end" class="label">' . report_svg_escape($label) . '</text>'
              . '<rect x="' . $label_width . '" y="' . ($y + 3) . '" width="' . round(max(1, $w), 1) . '" height="' . ($row_height - 8) . '" fill="' . report_svg_escape($row[2]) . '" rx="3"><title>' . report_svg_escape($row[0] . ' : ' . $row[1]) . '</title></rect>'
              . '<text x="' . round($label_width + $w + 6, 1) . '" y="' . ($y + 16) . '" class="value">' . intval($row[1]) . '</text>';
    }
    return $svg . '</svg>';
}

/* Proportions in one bar with a legend: rows of array(label, value, color) */
function report_svg_stack($rows, $title)
{
    $width = 720;
    $total = 0;
    foreach ($rows as $row) {
        $total += $row[1];
    }
    $legend_rows = intval(ceil(count($rows) / 3));
    $height = 44 + $legend_rows * 22;
    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . report_svg_escape($title) . '" class="report-chart">';
    $x = 0;
    foreach ($rows as $row) {
        if ($total == 0 || $row[1] == 0) {
            continue;
        }
        $w = $width * $row[1] / $total;
        $svg .= '<rect x="' . round($x, 1) . '" y="0" width="' . round($w, 1) . '" height="30" fill="' . report_svg_escape($row[2]) . '"><title>' . report_svg_escape($row[0] . ' : ' . $row[1]) . '</title></rect>';
        if ($w > 40) {
            $svg .= '<text x="' . round($x + $w / 2, 1) . '" y="20" text-anchor="middle" class="inbar">' . round($row[1] / $total * 100) . ' %</text>';
        }
        $x += $w;
    }
    foreach (array_values($rows) as $i => $row) {
        $lx = ($i % 3) * 240;
        $ly = 48 + intval(floor($i / 3)) * 22;
        $svg .= '<rect x="' . $lx . '" y="' . ($ly - 11) . '" width="12" height="12" rx="2" fill="' . report_svg_escape($row[2]) . '"/>'
              . '<text x="' . ($lx + 18) . '" y="' . $ly . '" class="label-left">' . report_svg_escape($row[0] . ' (' . $row[1] . ')') . '</text>';
    }
    return $svg . '</svg>';
}
