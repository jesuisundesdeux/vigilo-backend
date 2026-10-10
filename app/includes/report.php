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
    $street = preg_replace('/^\s*\d+\s*(bis|ter)?\s*,?\s*/i', '', (string) $address);
    return flatstring($street);
}

/*
 * Similar observations grouped: same category, and closer than $radius metres to the first one
 * of the group or at the same street. Groups sorted by number of observations, then most recent.
 */
function report_groups($observations, $radius)
{
    $groups = array();
    foreach ($observations as $obs) {
        $found = null;
        foreach ($groups as $key => $group) {
            if ($group['category'] != $obs['category']) {
                continue;
            }
            $close = distance($group['lat'], $group['lon'], $obs['lat'], $obs['lon'], 'm') <= $radius;
            $same_street = $group['street'] !== '' && $group['street'] === report_street($obs['address'])
                && distance($group['lat'], $group['lon'], $obs['lat'], $obs['lon'], 'm') <= 1000;
            if ($close || $same_street) {
                $found = $key;
                break;
            }
        }
        if ($found === null) {
            $groups[] = array('category' => $obs['category'], 'lat' => $obs['lat'], 'lon' => $obs['lon'],
                              'street' => report_street($obs['address']), 'observations' => array());
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
    $streets = array();
    foreach ($observations as $obs) {
        $street = trim(preg_replace('/^\s*\d+\s*(bis|ter)?\s*,?\s*/i', '', $obs['address']));
        if ($street !== '') {
            $streets[$street] = (isset($streets[$street]) ? $streets[$street] : 0) + 1;
        }
    }
    arsort($streets);
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
