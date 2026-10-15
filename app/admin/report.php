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
 * Report for a local authority (form of the page "Rapports"): document (A4) or slides (16:9),
 * saved as PDF with the print function of the browser. Read only: no CSRF token needed.
 * A citystaff account only gets the observations of their cities.
 */
require_once('../includes/security.php');

vigilo_session_start();
vigilo_admin_headers();

if (!isset($_SESSION['login']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], array('admin', 'citystaff'), true)) {
  header('Location: login.php');
  exit;
}

require_once('../includes/common.php');
require_once('../includes/functions.php');
require_once('../includes/handle.php');
require_once('../includes/report.php');
require_once('../includes/problems.php');

$allowed = ($_SESSION['role'] == 'citystaff') ? getRoleCityIds($db, (string) $_SESSION['login']) : null;
$filters = report_filters($_GET, $allowed);
$slides  = isset($_GET['format']) && $_GET['format'] === 'slides';
list($start, $end) = report_bounds($filters);

/* Names and colors of the categories, names and population of the cities */
$categories = array();
foreach (getCategoriesList() as $categorie) {
  if (isset($categorie['catid'])) {
    $categories[intval($categorie['catid'])] = array('name' => (string) $categorie['catname'],
      'color' => isset($categorie['catcolor']) && $categorie['catcolor'] !== '' ? (string) $categorie['catcolor'] : '#9e9e9e');
  }
}
function report_category($categories, $id)
{
  return isset($categories[$id]) ? $categories[$id] : array('name' => 'Catégorie ' . intval($id), 'color' => '#9e9e9e');
}
$cities = array();
$population = 0;
$cities_query = mysqli_query($db, "SELECT city_id, city_name, city_population FROM obs_cities ORDER BY city_name");
while ($cities_query && $row = mysqli_fetch_array($cities_query)) {
  $cities[intval($row['city_id'])] = (string) $row['city_name'];
  if (count($filters['cities']) == 0 || in_array(intval($row['city_id']), $filters['cities'], true)) {
    $population += intval($row['city_population']);
  }
}

$observations = report_observations($db, $filters);
$previous     = count(report_observations($db, $filters, $start - ($end - $start + 1), $start - 1));
$groups       = report_groups($observations, $filters['radius']);
$stats        = report_stats($observations, $groups, $filters, $previous, $population);
// Sections chosen in the form; problems (#327): administrators only, never the internal notes
$sections     = report_chosen_sections($_GET, $_SESSION['role'] == 'admin');
$problem_rows = (isset($sections['problems']) || isset($sections['problem_details'])) ? problem_report_rows($db, $observations) : array();

/* Texts of the report */
$instance_name = isset($config['VIGILO_NAME']) && $config['VIGILO_NAME'] !== '' ? $config['VIGILO_NAME'] : 'Vigilo';
$city_names = array();
foreach ($filters['cities'] as $city_id) {
  if (isset($cities[$city_id])) {
    $city_names[] = $cities[$city_id];
  }
}
if (count($city_names) == 0) {
  $territory = $instance_name;
} elseif (count($city_names) <= 3) {
  $territory = implode(', ', $city_names);
} else {
  $territory = count($city_names) . ' communes';
}
$title = $filters['title'] !== '' ? $filters['title'] : 'Observations des déplacements à pied et à vélo — ' . $territory;
$category_names = array();
foreach ($filters['categories'] as $cat_id) {
  $category_names[] = report_category($categories, $cat_id)['name'];
}
$months_fr = array(1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.');
function report_date($time)
{
  return date('d/m/Y', $time);
}
function report_number($value, $decimals = 0)
{
  return number_format((float) $value, $decimals, ',', "\u{202F}");
}
$period_text = 'du ' . report_date($start) . ' au ' . report_date($end);

/* Chart data */
$month_values = array();
foreach ($stats['by_month'] as $month => $count) {
  $month_values[$months_fr[intval(substr($month, 5, 2))] . ' ' . substr($month, 2, 2)] = $count;
}
$category_rows = array();
foreach ($stats['by_category'] as $cat_id => $count) {
  $cat = report_category($categories, $cat_id);
  $category_rows[] = array($cat['name'], $count, $cat['color']);
}
$status_rows = array();
foreach (array(1, 4, 3, 2, 0) as $status) {
  $status_rows[] = array(report_status_names()[$status], $stats['statuses'][$status], report_status_colors()[$status]);
}
$city_rows = array();
foreach (array_slice($stats['by_city'], 0, 12, true) as $city => $count) {
  $city_rows[] = array($city !== '' ? $city : 'Ville non renseignée', $count, '#3f6fb5');
}
$street_rows = array();
foreach ($stats['by_street'] as $street => $count) {
  $street_rows[] = array($street, $count, '#7b8794');
}

/* Groups on the map (all of them) and in the lists (the recurrent ones first) */
$map_groups = array();
foreach ($groups as $group) {
  $cat = report_category($categories, $group['category']);
  $map_groups[] = array('lat' => round($group['center'][0], 6), 'lon' => round($group['center'][1], 6), 'count' => $group['count'],
                        'color' => $cat['color'], 'label' => $cat['name'] . ' — ' . $group['address'] . ' (' . $group['count'] . ')');
}
$top_groups = array_slice(array_values(array_filter($groups, function ($g) { return $g['count'] > 1; })), 0, $slides ? 12 : 20);

/* Key figures (label, value, detail) */
$kpis = array();
$kpis[] = array('Observations', report_number($stats['total']),
  $stats['evolution'] === null ? 'période précédente : ' . report_number($stats['previous'])
    : ($stats['evolution'] >= 0 ? '+' : '') . report_number($stats['evolution']) . ' % par rapport à la période précédente (' . report_number($stats['previous']) . ')');
$kpis[] = array('Par mois', report_number($stats['per_month'], 1), 'en moyenne sur la période');
if ($stats['per_10000'] !== null) {
  $kpis[] = array('Pour 10 000 habitants', report_number($stats['per_10000'], 1), report_number($stats['population']) . ' habitants');
}
$kpis[] = array('Résolues', report_number($stats['resolved_rate']) . ' %', report_number($stats['resolved']) . ' observation(s)');
$kpis[] = array('Prises en charge', report_number($stats['handled_rate']) . ' %', 'résolues, en cours ou prises en compte');
$kpis[] = array('Délai de résolution', $stats['median_delay'] === null ? '—' : report_number($stats['median_delay']) . ' j', 'médiane, de l\'observation à la résolution');
$kpis[] = array('Lieux concernés', report_number($stats['places']), 'observations similaires regroupées');
$kpis[] = array('Lieux récurrents', report_number($stats['recurring']),
  report_number($stats['recurring_obs']) . ' observation(s) à des endroits signalés plusieurs fois');
$top_category = count($category_rows) ? $category_rows[0][0] : '—';

function report_group_html($group, $categories, $cities_shown)
{
  $cat = report_category($categories, $group['category']);
  $statuses = array();
  foreach (array(1, 4, 3, 2, 0) as $status) {
    if (!empty($group['statuses'][$status])) {
      $statuses[] = '<span class="status-dot" style="background:' . h(report_status_colors()[$status]) . '"></span>' . h(report_status_names()[$status]) . ' : ' . intval($group['statuses'][$status]);
    }
  }
  $period = report_date($group['first']) . ($group['last'] != $group['first'] ? ' → ' . report_date($group['last']) : '');
  $tokens = array();
  foreach (array_slice($group['observations'], 0, 8) as $obs) {
    $tokens[] = $obs['token'];
  }
  return '<article class="group">'
    . '<img src="photo.php?s=400&amp;token=' . h(urlencode($group['photo'])) . '" alt="Photo de l\'observation ' . h($group['photo']) . '" loading="eager">'
    . '<div class="group-body">'
    . '<div class="group-head"><span class="count">' . intval($group['count']) . '</span><span class="cat"><span class="cat-dot" style="background:' . h($cat['color']) . '"></span>' . h($cat['name']) . '</span></div>'
    . '<div class="group-address">' . h($group['address']) . ($cities_shown && count($group['cities']) ? ', ' . h(implode(', ', $group['cities'])) : '') . '</div>'
    . '<div class="group-meta">' . h($period) . '</div>'
    . '<div class="group-status">' . implode(' · ', $statuses) . '</div>'
    . '<div class="group-tokens">' . h(implode(' ', $tokens)) . (count($group['observations']) > 8 ? ' …' : '') . '</div>'
    . '</div></article>';
}
/* Detail of a problem of the report (one block; two slides) */
function report_problem_detail_html($row, $categories, $statuses, $priorities, $slides, $section)
{
  $problem = $row['problem'];
  $status  = isset($statuses[$problem['problem_status']]) ? $statuses[$problem['problem_status']][0] : (string) $problem['problem_status'];
  $prio    = isset($priorities[intval($problem['problem_priority'])]) ? $priorities[intval($problem['problem_priority'])][0] : '';
  $names   = array();
  foreach (problem_category_ids($problem) as $cat_id) {
    $names[] = report_category($categories, $cat_id)['name'];
  }
  $info = '<dl class="problem-info">'
    . '<dt>Statut</dt><dd>' . h($status) . '</dd><dt>Priorité</dt><dd>' . h($prio) . '</dd>'
    . '<dt>Créé le</dt><dd>' . (intval($problem['problem_created_time']) ? h(report_date(intval($problem['problem_created_time']))) : '—') . '</dd>'
    . '<dt>Catégories</dt><dd>' . h(implode(', ', $names)) . '</dd>'
    . '<dt>Zone</dt><dd>' . h(problem_zone_label($problem)) . '</dd>'
    . '<dt>Observations</dt><dd>' . report_number($row['count']) . ' dans le rapport' . ($row['total'] != $row['count'] ? ' (' . report_number($row['total']) . ' au total)' : '')
    . ', du ' . h(report_date($row['first'])) . ' au ' . h(report_date($row['last'])) . '</dd>'
    . '</dl>'
    . ($problem['problem_description'] !== '' ? '<p class="problem-description">' . nl2br(h(vigilo_report_truncate($problem['problem_description'], $slides ? 300 : 1500))) . '</p>' : '');
  $points = array();
  foreach ($row['observations'] as $obs) {
    $points[] = array('lat' => round($obs['lat'], 6), 'lon' => round($obs['lon'], 6), 'label' => $obs['token'] . ' — ' . report_date($obs['time']));
  }
  $map_data = array('zone' => $problem['problem_zone'], 'lat' => (float) $problem['problem_lat'], 'lon' => (float) $problem['problem_lon'],
                    'radius' => intval($problem['problem_radius']), 'polygon' => problem_polygon_points($problem['problem_polygon']), 'points' => $points);
  $map    = '<div class="report-problem-map" data-problem="' . h(json_encode($map_data)) . '" role="img" aria-label="Carte du problème ' . h($problem['problem_title']) . '"></div>';
  $photos = '';
  foreach (array_slice(array_reverse($row['observations']), 0, $slides ? 4 : 6) as $obs) {
    $photos .= '<figure><img src="photo.php?s=300&amp;token=' . h(urlencode($obs['token'])) . '" alt="Photo de l\'observation ' . h($obs['token']) . '" loading="eager">'
      . '<figcaption>' . h($obs['token'] . ' · ' . report_date($obs['time'])) . '</figcaption></figure>';
  }
  $photos  = '<h3>Dernières photos</h3><div class="problem-photos">' . $photos . '</div>';
  $charts  = problem_time_charts(problem_time_stats($row['observations']));
  $timing  = '<h3>Chronologie (observations par mois)</h3>' . $charts['timeline']
    . '<h3>Jours et heures</h3>' . $charts['heatmap'];
  $title = 'Problème : ' . $problem['problem_title'];
  if ($slides) {
    return $section($title, '<div class="two-cols problem-slide"><div>' . $info . $photos . '</div><div>' . $map . '</div></div>', 'problem-detail')
      . $section($title . ' — chronologie et horaires', $timing, 'problem-detail');
  }
  return $section($title, $info . '<div class="two-cols problem-top"><div>' . $map . '</div><div>' . $photos . '</div></div>' . $timing, 'problem-detail');
}

$cities_shown = count($filters['cities']) != 1;
$filters_text = 'Période ' . $period_text . ' · ' . (count($city_names) ? 'Villes : ' . implode(', ', $city_names) : ($filters['restricted'] ? 'Vos villes' : 'Toutes les villes'))
  . ' · ' . (count($category_names) ? 'Types : ' . implode(', ', $category_names) : 'Tous les types d\'observation');
$method_text = 'Observations publiées (approuvées par la modération) ' . $period_text . ', archivées comprises. État de traitement : '
  . 'le plus avancé des résolutions de chaque observation. Observations similaires : même catégorie, à moins de ' . intval($filters['radius'])
  . ' m l\'une de l\'autre ou dans la même rue. Délai de résolution : médiane entre la date de l\'observation et celle de sa résolution.';

$section = function ($title, $content, $class = '') use ($slides) {
  return '<section class="' . ($slides ? 'slide' : 'block') . ($class ? ' ' . $class : '') . '"><h2>' . h($title) . '</h2>' . $content . '</section>';
};
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($title) ?></title>
    <link rel="icon" href="vigilo.png">
    <link href="assets/vendor/leaflet/leaflet.css" rel="stylesheet">
    <link href="assets/report.css?v=<?= intval(@filemtime(__DIR__ . '/assets/report.css')) ?>" rel="stylesheet">
  </head>
  <body class="<?= $slides ? 'report-slides' : 'report-document' ?>">
    <div class="toolbar">
      <a href="index.php?page=reports" class="tool">← Rapports</a>
      <span class="spacer"></span>
      <?php if ($slides) { ?><button type="button" class="tool" data-present>Présenter</button><?php } ?>
      <button type="button" class="tool primary" data-print>Imprimer / PDF</button>
    </div>

    <?php if ($slides) { ?>
    <section class="slide title-slide">
      <img src="vigilo.png" alt="" class="logo">
      <h1><?= h($title) ?></h1>
      <p class="subtitle"><?= h(ucfirst($period_text)) ?></p>
      <p class="filters"><?= h($filters_text) ?></p>
      <p class="generated">Rapport <?= h($instance_name) ?> · généré le <?= h(date('d/m/Y')) ?></p>
    </section>
    <?php } else { ?>
    <header class="report-header">
      <img src="vigilo.png" alt="" class="logo">
      <div>
        <h1><?= h($title) ?></h1>
        <p class="subtitle"><?= h(ucfirst($period_text)) ?></p>
        <p class="filters"><?= h($filters_text) ?></p>
        <p class="generated">Rapport <?= h($instance_name) ?> · généré le <?= h(date('d/m/Y à H:i')) ?></p>
      </div>
    </header>
    <?php } ?>

    <?php if ($stats['total'] == 0) {
      echo $section('Aucune observation', '<p>Aucune observation publiée ne correspond à ces critères.</p>');
    } else {
      $kpi_html = '<div class="kpis">';
      foreach ($kpis as $kpi) {
        $kpi_html .= '<div class="kpi"><div class="kpi-label">' . h($kpi[0]) . '</div><div class="kpi-value">' . h($kpi[1]) . '</div><div class="kpi-detail">' . h($kpi[2]) . '</div></div>';
      }
      $kpi_html .= '</div><p class="summary">' . h(report_number($stats['total'])) . ' observation(s) ' . h($period_text) . ', dont '
        . h(report_number($stats['resolved_rate'])) . ' % résolues. Type le plus fréquent : <strong>' . h($top_category) . '</strong>. '
        . h(report_number($stats['recurring'])) . ' lieu(x) signalé(s) plusieurs fois.</p>';
      if (isset($sections['kpis'])) {
        echo $section('Chiffres clés', $kpi_html, 'kpi-section');
      }
      if (isset($sections['monthly'])) {
        echo $section('Évolution mensuelle', report_svg_columns($month_values, '#f2c200', 'Observations par mois'));
      }
      if (isset($sections['categories'])) {
        echo $section('Types d\'observation', report_svg_bars(array_slice($category_rows, 0, 12), 'Observations par type'));
      }
      $status_html = report_svg_stack($status_rows, 'État de traitement des observations');
      if (!isset($sections['statuses'])) {
        // not shown
      } elseif ($slides) {
        echo $section('État de traitement', $status_html);
      } else {
        echo $section('État de traitement', $status_html . '<p class="note">Résolue : problème corrigé (résolution validée). Indiquée résolue : signalée comme corrigée, à vérifier. En cours / prise en compte : une résolution est engagée. Sans suite : aucune résolution.</p>');
      }
      if (isset($sections['cities']) && count($city_rows) > 1) {
        echo $section('Répartition par ville', report_svg_bars($city_rows, 'Observations par ville'));
      }
      if (isset($sections['streets']) && count($street_rows)) {
        echo $section('Rues les plus concernées', report_svg_bars($street_rows, 'Observations par rue'));
      }
      if (isset($sections['times'])) {
        echo $section('Jours et heures', report_svg_heatmap(report_week_grid($observations), 'Observations par jour de la semaine et par heure')
          . ($slides ? '' : '<p class="note">Une case par jour et par heure, d\'autant plus foncée que les observations y sont nombreuses.</p>'));
      }
      if (isset($sections['map'])) {
        echo $section('Carte des lieux', '<div id="report-map" class="report-map" data-groups="' . h(json_encode($map_groups)) . '" role="img" aria-label="Carte des lieux des observations"></div>'
          . '<p class="note">Un cercle par lieu ; sa taille dépend du nombre d\'observations, sa couleur de leur type.</p>');
      }

      if (!isset($sections['groups'])) {
        // not shown
      } elseif (count($top_groups)) {
        $per_slide = 4;
        $chunks = $slides ? array_chunk($top_groups, $per_slide) : array($top_groups);
        foreach ($chunks as $i => $chunk) {
          $html = '<div class="groups">';
          foreach ($chunk as $group) {
            $html .= report_group_html($group, $categories, $cities_shown);
          }
          $html .= '</div>';
          echo $section('Lieux récurrents' . (count($chunks) > 1 ? ' (' . ($i + 1) . '/' . count($chunks) . ')' : ''),
            ($i == 0 && !$slides ? '<p class="note">Lieux où des observations similaires se répètent, du plus signalé au moins signalé.</p>' : '') . $html, 'groups-section');
        }
      } else {
        echo $section('Lieux récurrents', '<p>Aucun lieu n\'a été signalé plusieurs fois sur la période.</p>');
      }

      $statuses   = problem_statuses();
      $priorities = problem_priorities();
      if ($problem_rows && isset($sections['problems'])) {
        $chunks     = $slides ? array_chunk($problem_rows, 6) : array($problem_rows);
        foreach ($chunks as $i => $chunk) {
          $html = '<table class="problems-table"><thead><tr><th>Problème</th><th>État</th><th>Priorité</th><th>Observations</th><th>Période</th></tr></thead><tbody>';
          foreach ($chunk as $row) {
            $problem = $row['problem'];
            $status  = isset($statuses[$problem['problem_status']]) ? $statuses[$problem['problem_status']][0] : $problem['problem_status'];
            $prio    = isset($priorities[intval($problem['problem_priority'])]) ? $priorities[intval($problem['problem_priority'])][0] : '';
            $html .= '<tr><td><strong>' . h($problem['problem_title']) . '</strong>'
              . ($problem['problem_description'] !== '' ? '<div class="note">' . h(vigilo_report_truncate($problem['problem_description'], $slides ? 120 : 300)) . '</div>' : '')
              . '</td><td data-label="État">' . h($status) . '</td><td data-label="Priorité">' . h($prio) . '</td><td data-label="Observations">' . report_number($row['count'])
              . ($row['total'] != $row['count'] ? ' <span class="note">(' . report_number($row['total']) . ' au total)</span>' : '') . '</td>'
              . '<td data-label="Période">' . h(report_date($row['first'])) . ($row['last'] != $row['first'] ? ' – ' . h(report_date($row['last'])) : '') . '</td></tr>';
          }
          $html .= '</tbody></table>';
          echo $section('Problèmes suivis' . (count($chunks) > 1 ? ' (' . ($i + 1) . '/' . count($chunks) . ')' : ''),
            ($i == 0 && !$slides ? '<p class="note">Observations regroupées par l\'association en problèmes (même lieu, même type) : nombre d\'observations du rapport et état de leur suivi.</p>' : '') . $html, 'problems-section');
        }
      }
      // Detail of each problem: information, map, timeline, days and hours, photos (observations of the report)
      if ($problem_rows && isset($sections['problem_details'])) {
        foreach ($problem_rows as $row) {
          echo report_problem_detail_html($row, $categories, $statuses, $priorities, $slides, $section);
        }
      }
    }
    if (isset($sections['method'])) {
      echo $section('Méthode', '<p class="note">' . h($method_text) . '</p><p class="note">Données : ' . h($instance_name) . ', application Vigilo (vigilo.city). Fond de carte © contributeurs OpenStreetMap.</p>', 'method');
    }
    ?>

    <script src="assets/vendor/leaflet/leaflet.js"></script>
    <script src="assets/report.js?v=<?= intval(@filemtime(__DIR__ . '/assets/report.js')) ?>"></script>
  </body>
</html>
