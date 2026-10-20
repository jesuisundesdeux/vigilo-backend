<?php
/*
 * Unit tests of the problem rules (app/includes/problems.php) that need no database:
 * zones (circle, polygon, streets), bounding box, validation of the form.
 *
 *   php tests/problems/unit.php
 */
require __DIR__ . '/../../app/includes/functions.php';
require __DIR__ . '/../../app/includes/problems.php';
require __DIR__ . '/../../app/includes/addresses.php';

$failures = 0;
function check($condition, $message)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
}

$circle = array('problem_zone' => 'circle', 'problem_lat' => 43.6, 'problem_lon' => 3.9, 'problem_radius' => 100, 'problem_polygon' => '');
check(problem_zone_contains($circle, 43.6003, 3.9003), 'circle: point at 41 m inside');
check(!problem_zone_contains($circle, 43.601, 3.901), 'circle: point at 136 m outside');
$bbox = problem_bbox($circle);
check($bbox[0] < 43.5991 && $bbox[1] > 43.6009 && $bbox[2] < 3.8988 && $bbox[3] > 3.9012, 'circle: bounding box larger than the circle');
check(problem_bbox(array_merge($circle, array('problem_radius' => 0))) === null && !problem_zone_contains(array_merge($circle, array('problem_radius' => 0)), 43.6, 3.9),
      'circle without radius: no zone');

// Concave polygon (U shape): the hollow is outside
$u = json_encode(array(array(0, 0), array(0, 3), array(3, 3), array(3, 2), array(1, 2), array(1, 1), array(3, 1), array(3, 0)));
$polygon = array('problem_zone' => 'polygon', 'problem_lat' => 0, 'problem_lon' => 0, 'problem_radius' => 0, 'problem_polygon' => $u);
check(problem_zone_contains($polygon, 0.5, 0.5), 'polygon: point inside');
check(problem_zone_contains($polygon, 2.5, 2.5), 'polygon: point in a branch');
check(!problem_zone_contains($polygon, 2, 1.5), 'polygon: point in the hollow outside');
check(!problem_zone_contains($polygon, 4, 1), 'polygon: point beyond outside');
check(problem_bbox($polygon) == array(0, 3, 0, 3), 'polygon: bounding box');
foreach (array('', 'x', '[[0,0],[1,1]]', '[[0,0],[1,1],[95,1]]', '[[0,0],[1,1],["a",1]]', '{"a":1}') as $bad) {
    check(problem_polygon_points($bad) === array(), 'polygon refused: ' . $bad);
}

// Form
$known = array(2 => array('Stationnement', false), 3 => array('Travaux', false));
$post  = array('problem_title' => '  Piste bloquée ', 'problem_description' => "Ligne 1\r\nLigne 2", 'problem_categories' => array('2', '3', '2', '99', 'x'),
               'problem_zone' => 'circle', 'problem_lat' => '43.6', 'problem_lon' => '3.9', 'problem_radius' => '150',
               'problem_start' => '2020-01-01', 'problem_end' => '', 'problem_priority' => '4', 'problem_status' => 'waiting');
list($problem, $errors) = problem_from_post($post, $known);
check($errors === array(), 'form: valid circle accepted');
check($problem['problem_title'] === 'Piste bloquée' && $problem['problem_description'] === "Ligne 1\nLigne 2", 'form: texts trimmed');
check($problem['problem_categories'] === '2,3', 'form: known categories only, once');
check($problem['problem_start'] === strtotime('2020-01-01 00:00:00') && $problem['problem_end'] === null, 'form: period without end');
check($problem['problem_priority'] === 4 && $problem['problem_status'] === 'waiting', 'form: priority and status');

list($problem, $errors) = problem_from_post(array_merge($post, array('problem_end' => '2019-12-31')), $known);
check(in_array('La date de fin doit suivre la date de début.', $errors, true), 'form: end before start refused');
list($problem, $errors) = problem_from_post(array_merge($post, array('problem_end' => '2020-01-01')), $known);
check($errors === array() && $problem['problem_end'] === strtotime('2020-01-01 23:59:59'), 'form: end day included');
list($problem, $errors) = problem_from_post(array_merge($post, array('problem_title' => '', 'problem_categories' => array('99'), 'problem_radius' => '5',
                                                                   'problem_start' => '2020-02-30', 'problem_status' => 'x', 'problem_priority' => '9')), $known);
check(count($errors) == 3 && $problem['problem_status'] === 'open' && $problem['problem_priority'] === 2, 'form: title, radius, start date checked; unknown status and priority replaced');
check($problem['problem_categories'] === '', 'form: no (known) category = all categories');
list($problem, $errors) = problem_from_post(array_merge($post, array('problem_lat' => '', 'problem_lon' => '')), $known);
check(in_array('Placer le centre du cercle sur la carte.', $errors, true), 'form: circle without center refused');

$post_polygon = array_merge($post, array('problem_zone' => 'polygon', 'problem_polygon' => '[[43.6,3.9],[43.61,3.9],[43.61,3.92]]'));
list($problem, $errors) = problem_from_post($post_polygon, $known);
check($errors === array() && $problem['problem_radius'] === 0 && abs($problem['problem_lat'] - 43.605) < 1e-9 && abs($problem['problem_lon'] - 3.91) < 1e-9,
      'form: polygon accepted, center of its box');
list($problem, $errors) = problem_from_post(array_merge($post_polygon, array('problem_polygon' => '[[43.6,3.9],[43.61,3.9]]')), $known);
check(count($errors) == 1, 'form: polygon of 2 points refused');


// Zone of streets
check(problem_street_key('12 bis, Rue Jean-Jaurès') === 'ruejeanjaures' && problem_street_key('rue  JEAN jaures') === 'ruejeanjaures', 'streets: names compared without case, accents, number');
check(problem_address_parts('12, Rue de la Gare, Montpellier') === array('Rue de la Gare', 'Montpellier'), 'streets: address split (number alone ignored)');
check(problem_address_parts('Rue de la Gare') === array('Rue de la Gare', ''), 'streets: address without city');
$streets = problem_streets_list('[{"street":"Rue de la Gare","city":"Montpellier"},{"street":"rue de la gare","city":"montpellier"},{"street":"Avenue X","city":""}]');
check(count($streets) == 2, 'streets: duplicates removed');
check(problem_streets_list('[{"city":"X"}]') === array() && problem_streets_list('x') === array(), 'streets: invalid JSON refused');
$zone = array('problem_zone' => 'streets', 'problem_lat' => 0, 'problem_lon' => 0, 'problem_radius' => 0, 'problem_polygon' => '',
              'problem_streets' => json_encode($streets));
check(problem_location_matches($zone, 0, 0, '3 Rue de la Gare, Montpellier'), 'streets: observation of the street and city');
check(problem_location_matches($zone, 0, 0, 'Rue de la Gare, Lattes', 'Montpellier'), 'streets: city name of the observation also accepted');
check(!problem_location_matches($zone, 0, 0, 'Rue de la Gare, Lattes'), 'streets: other city refused');
check(problem_location_matches($zone, 0, 0, 'Avenue X, Nîmes') && !problem_location_matches($zone, 0, 0, 'Avenue Y, Nîmes'), 'streets: street without city, any city');
check(problem_bbox($zone) === null && !problem_zone_contains($zone, 0, 0), 'streets: no bounding box');
check(problem_zone_label($zone) === 'Rues : Rue de la Gare, Montpellier ; Avenue X', 'streets: label');
check(problem_street_from_text(' Rue A,  B , Ville ') === array('street' => 'Rue A, B', 'city' => 'Ville'), 'streets: text split on the last comma');

$post_streets = array_merge($post, array('problem_zone' => 'streets', 'problem_streets' => array('Rue de la Gare, Montpellier', '', 'Avenue X')));
list($problem, $errors) = problem_from_post($post_streets, $known);
check($errors === array() && $problem['problem_zone'] === 'streets' && $problem['problem_radius'] === 0
      && problem_streets_list($problem['problem_streets']) === array(array('street' => 'Rue de la Gare', 'city' => 'Montpellier'), array('street' => 'Avenue X', 'city' => '')),
      'form: streets accepted, empty fields ignored');
list($problem, $errors) = problem_from_post(array_merge($post_streets, array('problem_streets' => array(' '))), $known);
check(count($errors) == 1, 'form: zone of streets without street refused');

// Archived observations excluded unless asked
check(strpos(problem_candidates_where(array_merge($circle, array('problem_categories' => '2', 'problem_start' => 0, 'problem_end' => null))), 'obs_archived = 0') !== false
      && strpos(problem_candidates_where(array_merge($circle, array('problem_categories' => '2', 'problem_start' => 0, 'problem_end' => null)), true), 'obs_archived') === false,
      'archived observations excluded unless asked');

// No category: all categories
$all = array_merge($circle, array('problem_categories' => '', 'problem_start' => 0, 'problem_end' => null));
check(problem_candidates_where($all) !== '0' && strpos(problem_candidates_where($all), 'obs_categorie') === false
      && strpos(problem_candidates_where(array_merge($all, array('problem_categories' => '2'))), 'obs_categorie IN (2)') !== false, 'no category: no condition on the category');
$obs = array('obs_complete' => 1, 'obs_approved' => 1, 'obs_categorie' => 7, 'obs_time' => 100, 'obs_coordinates_lat' => 43.6, 'obs_coordinates_lon' => 3.9);
check(problem_matches_observation($all, $obs) && !problem_matches_observation(array_merge($all, array('problem_categories' => '2')), $obs), 'no category: observation of any category matches');

// Distribution in time
$stats = problem_time_stats(array(array('obs_time' => mktime(8, 30, 0, 11, 30, 2020)), array('time' => mktime(18, 0, 0, 2, 1, 2021)), array('obs_time' => 0)));
check($stats['count'] == 2 && array_keys($stats['months']) === array('2020-11', '2020-12', '2021-01', '2021-02')
      && $stats['months']['2020-12'] === 0 && $stats['weekdays'][0] === 2 && $stats['hours'][8] === 1 && $stats['hours'][18] === 1,
      'time stats: continuous months, Monday first, hours');
check(problem_time_stats(array())['months'] === array(), 'time stats: no observation');
check($stats['grid'][0][8] === 1 && $stats['grid'][0][18] === 1 && array_sum(array_map('array_sum', $stats['grid'])) === 2, 'time stats: matrix days × hours');
require_once __DIR__ . '/../../app/includes/report.php';
$svg = report_svg_heatmap($stats['grid'], 'Test <b>');
check(substr_count($svg, '<rect') == 7 * 24 + 6 && strpos($svg, 'lundi 8 h – 9 h : 1 observation<') !== false && strpos($svg, '#104281') !== false
      && strpos($svg, '<b>') === false, 'heatmap: 168 squares and a scale, darkest for the maximum, title escaped');
$strip = report_svg_strip(array('2020-11' => 1, '2020-12' => 0, '2021-01' => 4), 'Frise');
check(substr_count($strip, '<rect') == 3 + 6 && strpos($strip, 'décembre 2020 : 0 observation<') !== false && strpos($strip, '>2021</text>') !== false
      && strpos($strip, '>2020</text>') !== false && strpos($strip, 'janvier 2021 (4)') !== false && strpos($strip, '#104281') !== false,
      'timeline strip: one square per month, years, busiest month, darkest for the maximum');
$long = array();
for ($t = mktime(0, 0, 0, 1, 1, 2015); $t < mktime(0, 0, 0, 1, 1, 2021); $t = strtotime('+1 month', $t)) {
    $long[date('Y-m', $t)] = date('n', $t) == 5 ? 2 : 0;
}
$strip = report_svg_strip($long, 'Frise');
check(substr_count($strip, '<rect') == 24 + 6 && strpos($strip, '2e trimestre 2015 : 2 observations<') !== false && strpos($strip, 'Trimestre le plus chargé') !== false,
      'timeline strip: by quarter beyond 4 years');
check(report_week_grid(array(array('time' => mktime(23, 0, 0, 1, 3, 2021))))[6][23] === 1, 'week grid: Sunday 23 h');

check(problem_active_statuses() === array('open', 'in_progress', 'waiting'), 'active statuses');

// Analysis of the observations of a problem (report of a problem)
date_default_timezone_set('Europe/Paris');
$zone = array('problem_zone' => 'circle', 'problem_lat' => 43.6, 'problem_lon' => 3.9, 'problem_radius' => 100, 'problem_polygon' => '', 'problem_streets' => '');
$obs  = function ($token, $date, $lat, $lon, $address, $status = 0) {
    return array('id' => 0, 'token' => $token, 'time' => strtotime($date), 'lat' => $lat, 'lon' => $lon, 'address' => $address,
                 'city' => 'Testville', 'category' => 2, 'status' => $status, 'resolved_time' => $status == 1 ? strtotime($date . ' +10 days') : 0, 'comment' => '');
};
$list = array(
    $obs('A1', '2026-01-05 08:10', 43.6, 3.9, '1 Rue de la Gare'),           // Monday morning
    $obs('A2', '2026-01-06 08:40', 43.60005, 3.90005, 'Rue de la Gare'),     // Tuesday morning, 7 m from A1
    $obs('A3', '2026-03-03 18:00', 43.6003, 3.9003, '5 Rue de la Gare', 1),  // Tuesday evening, resolved
    $obs('A4', '2026-06-13 23:30', 43.61, 3.91, 'Avenue Loin'),               // Saturday night, outside the zone
);
$an = problem_analysis($zone, $list, strtotime('2026-07-01'));
check($an['count'] == 4 && count($an['time']['months']) == 6 && $an['time']['active_months'] == 3, 'analysis: months of the problem (6, 3 with observations)');
check(abs($an['time']['weekday_share'] - 75) < 0.01 && $an['time']['top_day'] === 'mardi', 'analysis: share of weekdays, most frequent day');
check(key($an['time']['bands']) === 'Matin (6 h – 10 h)' && $an['time']['bands']['Matin (6 h – 10 h)'] == 2, 'analysis: most frequent time of day');
check($an['time']['recent'] == 1 && $an['time']['before'] == 3 && round($an['time']['longest_gap'][0]) == 102, 'analysis: last 3 months, previous ones, longest gap');
check($an['geo']['outside'] == 1 && $an['geo']['street_count'] == 2 && key($an['geo']['streets']) === 'Rue de la Gare', 'analysis: outside the zone, streets');
check(count($an['geo']['hotspots']) == 1 && $an['geo']['hotspots'][0]['count'] == 3, 'analysis: hot spot of the 3 observations of the street');
check($an['follow']['resolved'] == 1 && round($an['follow']['delay']) == 10, 'analysis: resolved observations, delay');
check(count($an['findings']['summary']) == 4 && count($an['findings']['time']) >= 4 && count($an['findings']['geo']) >= 3 && strpos(implode(' ', $an['findings']['geo']), 'hors de la zone') !== false,
      'analysis: sentences of the report');
$empty = problem_analysis($zone, array(), time());
check($empty['count'] == 0 && $empty['findings']['time'] === array(), 'analysis without observation');

// Addresses (includes/addresses.php)
$cities = array(1 => array('name' => 'Montpellier', 'postcode' => '34000,34070'), 2 => array('name' => 'Saint-Jean-de-Védas', 'postcode' => '34430'),
                3 => array('name' => 'Lattes', 'postcode' => '34970'));
check(address_tidy(" 12 ,  rue  d\u{2019}Alger ,, ") === "12, rue d'Alger", 'addresses: spaces, commas and apostrophes tidied');
check(address_parse('12, rue X, 34000 Montpellier, France') === array('street' => '12 rue X', 'city' => 'Montpellier', 'postcode' => '34000'),
      'addresses: lone number, postcode and country');
check(address_match_city('ST JEAN DE VEDAS', '', $cities) === 2 && address_match_city('montpellier', '', $cities) === 1
      && address_match_city('', '34970', $cities) === 3 && address_match_city('Pérols', '', $cities) === 0, 'addresses: city matched without case, accents, "St", or by postcode');
$fix = address_fix(array('obs_address_string' => '12 bis, rue X, 34000 Montpellier', 'obs_city' => 0, 'obs_cityname' => ''), $cities);
check($fix['address'] === 'Rue X' && $fix['city'] === 1 && $fix['reasons'] === array('city', 'number', 'street_type'), 'addresses: no house number before the street, type of street in full');
check(vigilo_street_type_normalize('bd Victor Hugo') === 'Boulevard Victor Hugo' && vigilo_street_type_normalize('Av. Foch') === 'Avenue Foch'
      && vigilo_street_type_normalize('rue de la Gare') === 'Rue de la Gare' && vigilo_street_type_normalize('allee des Pins') === 'Allée des Pins'
      && vigilo_street_type_normalize('Le Corum') === 'Le Corum' && vigilo_street_type_normalize('Rue') === 'Rue'
      && vigilo_street_key('Bd Victor Hugo') === vigilo_street_key('boulevard victor-hugo'), 'streets: type in full ("bd" = "Boulevard"), same key');
$fix = address_fix(array('obs_address_string' => 'Rue A, Montpellier', 'obs_city' => 1, 'obs_cityname' => ''), $cities);
check($fix['address'] === 'Rue A' && $fix['reasons'] === array('city_duplicate'), 'addresses: city already known taken out of the address');
$fix = address_fix(array('obs_address_string' => 'Rue A', 'obs_city' => 0, 'obs_cityname' => 'Saint Jean de Védas'), $cities);
check($fix['city'] === 2 && $fix['cityname'] === '' && $fix['reasons'] === array('city_known'), 'addresses: free city name of a referenced city');
$fix = address_fix(array('obs_address_string' => 'Rue W, Pérols', 'obs_city' => 0, 'obs_cityname' => ''), $cities);
check($fix['city'] === 0 && $fix['cityname'] === 'Pérols' && $fix['address'] === 'Rue W', 'addresses: unknown city kept as a free name');
$fix = address_fix(array('obs_address_string' => 'Résidence Les Pins, Rue X', 'obs_city' => 1, 'obs_cityname' => ''), $cities);
check($fix['address'] === 'Résidence Les Pins, Rue X' && $fix['reasons'] === array(), 'addresses: two parts without a city left as is');
$rows = array(1 => array('obs_token' => 'A', 'obs_address_string' => '5 avenue de lodeve', 'obs_city' => 1, 'obs_cityname' => ''),
              2 => array('obs_token' => 'B', 'obs_address_string' => 'Avenue de Lodève', 'obs_city' => 1, 'obs_cityname' => ''),
              3 => array('obs_token' => 'C', 'obs_address_string' => '12 Avenue de Lodève', 'obs_city' => 1, 'obs_cityname' => ''),
              4 => array('obs_token' => 'D', 'obs_address_string' => 'avenue de lodeve', 'obs_city' => 3, 'obs_cityname' => ''));
$proposals = address_cleanup_proposals($rows, $cities);
check(array_keys($proposals) === array(1, 3, 4) && $proposals[1]['after']['address'] === 'Avenue de Lodève' && $proposals[1]['reasons'] === array('number', 'street_type', 'spelling')
      && $proposals[4]['after']['address'] === 'Avenue de lodeve' && $proposals[4]['reasons'] === array('street_type')
      && $proposals[3]['after']['address'] === 'Avenue de Lodève' && $proposals[3]['reasons'] === array('number'),
      'addresses: number taken out, most frequent spelling of the street in the same city, other city untouched');
check(address_format_details("Route  de Mende ", 'Route de Mende') === array('espaces en double retirés', 'espace au début ou à la fin retiré')
      && address_format_details("Route\u{00A0}de Mende", 'Route de Mende') === array('espace insécable ou tabulation remplacé')
      && address_format_details('Route de Mende', 'Route de Mende') === array(), 'addresses: invisible format changes described');

echo $failures . " failure(s)\n";
exit($failures ? 1 : 0);
