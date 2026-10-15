<?php
/*
 * Unit tests of the problem rules (app/includes/problems.php) that need no database:
 * zones (circle, polygon, streets), bounding box, validation of the form.
 *
 *   php tests/problems/unit.php
 */
require __DIR__ . '/../../app/includes/functions.php';
require __DIR__ . '/../../app/includes/problems.php';

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
check(count($errors) == 4 && $problem['problem_status'] === 'open' && $problem['problem_priority'] === 2, 'form: title, category, radius, start date checked; unknown status and priority replaced');
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

// Distribution in time
$stats = problem_time_stats(array(array('obs_time' => mktime(8, 30, 0, 11, 30, 2020)), array('time' => mktime(18, 0, 0, 2, 1, 2021)), array('obs_time' => 0)));
check($stats['count'] == 2 && array_keys($stats['months']) === array('2020-11', '2020-12', '2021-01', '2021-02')
      && $stats['months']['2020-12'] === 0 && $stats['weekdays'][0] === 2 && $stats['hours'][8] === 1 && $stats['hours'][18] === 1,
      'time stats: continuous months, Monday first, hours');
check(problem_time_stats(array())['months'] === array(), 'time stats: no observation');

check(problem_active_statuses() === array('open', 'in_progress', 'waiting'), 'active statuses');

echo $failures . " failure(s)\n";
exit($failures ? 1 : 0);
