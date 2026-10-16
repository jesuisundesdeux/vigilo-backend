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
 * Report of one problem (button "Rapport" of its detail): analysis of when and where its
 * observations happen, document (A4) or slides (16:9), saved as PDF with the print function
 * of the browser. Read only: no CSRF token needed. Never the internal notes. A citystaff
 * account gets the problems having observations in their cities, and these observations only.
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

$allowed  = ($_SESSION['role'] == 'citystaff') ? getRoleCityIds($db, (string) $_SESSION['login']) : null;
$problem  = isset($_GET['id']) ? problem_load($db, $_GET['id']) : null;
$attached = $problem ? problem_observations($db, $problem['problem_id'], $allowed) : array();
if (!$problem || ($allowed !== null && !$attached)) {
  http_response_code(404);
  exit('Problème introuvable.');
}
$slides = isset($_GET['format']) && $_GET['format'] === 'slides';
$now    = time();

/* Published observations of the problem (archived ones included), with their status */
$ids = array();
foreach ($attached as $row) {
  $ids[] = intval($row['obs_id']);
}
$filters      = array('cities' => $allowed !== null ? $allowed : array(), 'categories' => array(), 'restricted' => $allowed !== null, 'ids' => $ids);
$observations = report_observations($db, $filters, 0, $now + 86400);
$analysis     = problem_analysis($problem, $observations, $now);
$count        = $analysis['count'];

$categories    = report_category_list();
$statuses      = problem_statuses();
$priorities    = problem_priorities();
$instance_name = isset($config['VIGILO_NAME']) && $config['VIGILO_NAME'] !== '' ? $config['VIGILO_NAME'] : 'Vigilo';
$status        = isset($statuses[$problem['problem_status']]) ? $statuses[$problem['problem_status']][0] : (string) $problem['problem_status'];
$priority      = isset($priorities[intval($problem['problem_priority'])]) ? $priorities[intval($problem['problem_priority'])][0] : '';
$category_names = array();
foreach (problem_category_ids($problem) as $cat_id) {
  $category_names[] = report_category($categories, $cat_id)['name'];
}
$title       = 'Problème : ' . $problem['problem_title'];
$period_text = $count ? 'Observations du ' . report_date($analysis['time']['first']) . ' au ' . report_date($analysis['time']['last']) : 'Aucune observation publiée';
$info_text   = 'Statut : ' . $status . ' · Priorité : ' . $priority . ' · ' . ($category_names ? implode(', ', $category_names) : 'Toutes les catégories');
$self        = 'problem_report.php?id=' . intval($problem['problem_id']);

$section = function ($title, $content, $class = '') use ($slides) {
  return '<section class="' . ($slides ? 'slide' : 'block') . ($class ? ' ' . $class : '') . '"><h2>' . h($title) . '</h2>' . $content . '</section>';
};
$findings = function ($list) {
  return $list ? '<ul class="findings">' . implode('', array_map(function ($text) { return '<li>' . h($text) . '</li>'; }, $list)) . '</ul>' : '';
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
      <a href="index.php?page=problems&amp;view=<?= intval($problem['problem_id']) ?>" class="tool">← Fiche du problème</a>
      <span class="spacer"></span>
      <?php if ($slides) { ?>
      <a href="<?= h($self) ?>" class="tool">Document</a>
      <button type="button" class="tool" data-present>Présenter</button>
      <?php } else { ?>
      <a href="<?= h($self . '&format=slides') ?>" class="tool">Diaporama</a>
      <?php } ?>
      <button type="button" class="tool primary" data-print>Imprimer / PDF</button>
    </div>

    <?php if ($slides) { ?>
    <section class="slide title-slide">
      <img src="vigilo.png" alt="" class="logo">
      <h1><?= h($title) ?></h1>
      <p class="subtitle"><?= h($period_text) ?></p>
      <p class="filters"><?= h($info_text) ?></p>
      <p class="generated">Rapport <?= h($instance_name) ?> · généré le <?= h(date('d/m/Y')) ?></p>
    </section>
    <?php } else { ?>
    <header class="report-header">
      <img src="vigilo.png" alt="" class="logo">
      <div>
        <h1><?= h($title) ?></h1>
        <p class="subtitle"><?= h($period_text) ?></p>
        <p class="filters"><?= h($info_text) ?></p>
        <p class="generated">Rapport <?= h($instance_name) ?> · généré le <?= h(date('d/m/Y à H:i')) ?></p>
      </div>
    </header>
    <?php } ?>

    <?php
    // The problem: information (never the internal notes)
    $info = '<dl class="problem-info">'
      . '<dt>Statut</dt><dd>' . h($status) . '</dd><dt>Priorité</dt><dd>' . h($priority) . '</dd>'
      . '<dt>Créé le</dt><dd>' . (intval($problem['problem_created_time']) ? h(report_date(intval($problem['problem_created_time']))) : '—') . '</dd>'
      . '<dt>Catégories</dt><dd>' . h($category_names ? implode(', ', $category_names) : 'Toutes les catégories') . '</dd>'
      . '<dt>Zone</dt><dd>' . h(problem_zone_label($problem)) . '</dd>'
      . '<dt>Période suivie</dt><dd>' . h(problem_period_text($problem)) . '</dd>'
      . '</dl>'
      . ($problem['problem_description'] !== '' ? '<p class="problem-description">' . nl2br(h(vigilo_report_truncate($problem['problem_description'], $slides ? 400 : 2000))) . '</p>' : '');

    if (!$count) {
      echo $section('Le problème', $info);
      echo $section('Aucune observation', '<p>Aucune observation publiée n\'est rattachée à ce problème' . ($allowed !== null ? ' dans vos villes' : '') . '.</p>');
    } else {
      $t = $analysis['time'];
      $g = $analysis['geo'];
      $kpis = array(
        array('Observations', report_number($count), report_number($t['active_months']) . ' mois avec observation'),
        array('Par mois', report_number($t['per_month'], 1), 'en moyenne sur ' . count($t['months']) . ' mois'),
        array('3 derniers mois', report_number($t['recent']), 'contre ' . report_number($t['before']) . ' les 3 mois précédents'),
        array('Intervalle médian', $t['interval_median'] === null ? '—' : report_number($t['interval_median'], $t['interval_median'] < 10 ? 1 : 0) . ' j', 'entre deux observations'),
        array('Dispersion', $count > 1 ? report_number($g['median']) . ' m' : '—', 'distance médiane au centre des observations'),
        array('Rues', report_number($g['street_count']), 'rue(s) concernée(s)'),
        array('Points chauds', report_number(count($g['hotspots'])), 'plusieurs observations à moins de 25 m'),
        array('Résolues', report_number($analysis['follow']['resolved'] / $count * 100) . ' %', report_number($analysis['follow']['resolved']) . ' observation(s)'),
      );
      $kpi_html = '<div class="kpis">';
      foreach ($kpis as $kpi) {
        $kpi_html .= '<div class="kpi"><div class="kpi-label">' . h($kpi[0]) . '</div><div class="kpi-value">' . h($kpi[1]) . '</div><div class="kpi-detail">' . h($kpi[2]) . '</div></div>';
      }
      $kpi_html .= '</div>';
      if ($slides) {
        echo $section('Synthèse', $kpi_html, 'kpi-section');
        echo $section('En bref', $findings($analysis['findings']['summary']));
      } else {
        echo $section('Synthèse', $kpi_html . $findings($analysis['findings']['summary']), 'kpi-section');
      }
      echo $section('Le problème', $info);

      // When: timeline, months (years beyond 3 years), days × hours, time of day
      $charts = problem_time_charts($t['stats']);
      if (count($t['months']) > 36) {
        $period_chart = report_svg_columns($t['years'], '#3f6fb5', 'Observations par année');
      } else {
        $months_fr = report_month_names();
        $values = array();
        foreach ($t['months'] as $key => $n) {
          $values[$months_fr[intval(substr($key, 5, 2))] . ' ' . substr($key, 2, 2)] = $n;
        }
        $period_chart = report_svg_columns($values, '#3f6fb5', 'Observations par mois');
      }
      $band_rows = array();
      foreach ($t['bands'] as $label => $n) {
        $band_rows[] = array($label, $n, '#3f6fb5');
      }
      $time_text = $findings($analysis['findings']['time']);
      if ($slides) {
        echo $section('Analyse temporelle', $time_text . '<h3>Frise chronologique</h3>' . $charts['timeline'], 'analysis');
        echo $section('Évolution', $period_chart);
        echo $section('Jours et heures', $charts['heatmap']);
        echo $section('Moments de la journée', report_svg_bars($band_rows, 'Observations par moment de la journée'));
      } else {
        echo $section('Analyse temporelle', $time_text
          . '<h3>Frise chronologique</h3>' . $charts['timeline']
          . '<h3>Évolution</h3>' . $period_chart
          . '<h3>Jours et heures</h3>' . $charts['heatmap']
          . '<p class="note">Une case par jour et par heure, d\'autant plus foncée que les observations y sont nombreuses.</p>'
          . '<h3>Moments de la journée</h3>' . report_svg_bars($band_rows, 'Observations par moment de la journée'), 'analysis');
      }

      // Where: map (zone, streets, observations), streets, cities, hot spots
      $points = array();
      foreach ($observations as $obs) {
        $points[] = array('lat' => round($obs['lat'], 6), 'lon' => round($obs['lon'], 6), 'label' => $obs['token'] . ' — ' . report_date($obs['time']));
      }
      $map_data = array('zone' => $problem['problem_zone'], 'lat' => (float) $problem['problem_lat'], 'lon' => (float) $problem['problem_lon'],
                        'radius' => intval($problem['problem_radius']), 'polygon' => problem_polygon_points($problem['problem_polygon']), 'points' => $points,
                        'streets' => problem_streets_list(isset($problem['problem_streets']) ? $problem['problem_streets'] : ''));
      $map = '<div class="report-problem-map problem-map-large" data-problem="' . h(json_encode($map_data)) . '" role="img" aria-label="Carte des observations du problème"></div>';
      $street_rows = array();
      foreach ($g['streets'] as $street => $n) {
        $street_rows[] = array($street, $n, '#7b8794');
      }
      $city_rows = array();
      foreach (array_slice($g['cities'], 0, 10, true) as $city => $n) {
        $city_rows[] = array($city, $n, '#3f6fb5');
      }
      $hot = '';
      if ($g['hotspots']) {
        $hot = '<table class="problems-table"><thead><tr><th>Point chaud</th><th>Observations</th><th>Période</th></tr></thead><tbody>';
        foreach ($g['hotspots'] as $spot) {
          $hot .= '<tr><td>' . h($spot['address'] !== '' ? $spot['address'] : report_number($spot['center'][0], 5) . ', ' . report_number($spot['center'][1], 5)) . '</td>'
            . '<td data-label="Observations">' . intval($spot['count']) . '</td>'
            . '<td data-label="Période">' . h(report_date($spot['first'])) . ($spot['last'] != $spot['first'] ? ' – ' . h(report_date($spot['last'])) : '') . '</td></tr>';
        }
        $hot .= '</tbody></table>';
      }
      $geo_text = $findings($analysis['findings']['geo']);
      if ($slides) {
        echo $section('Répartition géographique', '<div class="two-cols problem-slide"><div>' . $geo_text . '</div><div>' . $map . '</div></div>', 'analysis');
        if ($street_rows) {
          echo $section('Rues concernées', report_svg_bars($street_rows, 'Observations par rue'));
        }
        if ($hot !== '') {
          echo $section('Points chauds', $hot);
        }
      } else {
        echo $section('Répartition géographique', $geo_text . $map
          . '<p class="note">Zone du problème et observations ; les rues d\'un problème de rues sont surlignées d\'après OpenStreetMap.</p>'
          . ($street_rows ? '<h3>Rues concernées</h3>' . report_svg_bars($street_rows, 'Observations par rue') : '')
          . (count($city_rows) > 1 ? '<h3>Villes</h3>' . report_svg_bars($city_rows, 'Observations par ville') : '')
          . ($hot !== '' ? '<h3>Points chauds</h3><p class="note">Plusieurs observations de même type à moins de 25 m l\'une de l\'autre.</p>' . $hot : ''), 'analysis');
      }

      // Types and follow-up of the observations
      $category_rows = array();
      foreach (report_count_by($observations, 'category') as $cat_id => $n) {
        $cat = report_category($categories, $cat_id);
        $category_rows[] = array($cat['name'], $n, $cat['color']);
      }
      $status_counts = report_count_by($observations, 'status');
      $status_rows = array();
      foreach (array(1, 4, 3, 2, 0) as $code) {
        $status_rows[] = array(report_status_names()[$code], isset($status_counts[$code]) ? $status_counts[$code] : 0, report_status_colors()[$code]);
      }
      echo $section('Types et suivi des observations', $findings($analysis['findings']['follow'])
        . (count($category_rows) > 1 ? report_svg_bars($category_rows, 'Observations par type') : '')
        . report_svg_stack($status_rows, 'État de traitement des observations'));

      // Latest photos
      $photos = '';
      foreach (array_slice(array_reverse($observations), 0, $slides ? 8 : 9) as $obs) {
        $photos .= '<figure><img src="' . h(report_photo_src($obs['token'], 300)) . '" alt="Photo de l\'observation ' . h($obs['token']) . '" loading="eager">'
          . '<figcaption>' . h($obs['token'] . ' · ' . report_date($obs['time'])) . '</figcaption></figure>';
      }
      echo $section('Dernières photos', '<div class="problem-photos">' . $photos . '</div>', 'photos-section');
    }
    echo $section('Méthode', '<p class="note">Observations publiées (approuvées par la modération) rattachées au problème, archivées comprises'
      . ($allowed !== null ? ', de vos villes' : '') . '. Centre : moyenne des positions ; dispersion : distance médiane au centre. Points chauds : '
      . 'observations de même type à moins de 25 m ou dans la même rue. État de traitement : le plus avancé des résolutions de chaque observation. '
      . 'Les notes internes ne figurent jamais dans le rapport.</p><p class="note">Données : ' . h($instance_name) . ', application Vigilo (vigilo.city). '
      . 'Fond de carte © contributeurs OpenStreetMap.</p>', 'method');
    ?>

    <script src="assets/vendor/leaflet/leaflet.js"></script>
    <script src="js/street-lines.js?v=<?= intval(@filemtime(__DIR__ . '/js/street-lines.js')) ?>"></script>
    <script src="assets/report.js?v=<?= intval(@filemtime(__DIR__ . '/assets/report.js')) ?>"></script>
  </body>
</html>
