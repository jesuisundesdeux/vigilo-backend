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
$weekday_values = array_combine(array('lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'), array_values($stats['weekdays']));
$hour_values = array();
foreach ($stats['hours'] as $hour => $count) {
  $hour_values[$hour . ' h'] = $count;
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
      echo $section('Chiffres clés', $kpi_html, 'kpi-section');

      echo $section('Évolution mensuelle', report_svg_columns($month_values, '#f2c200', 'Observations par mois'));
      echo $section('Types d\'observation', report_svg_bars(array_slice($category_rows, 0, 12), 'Observations par type'));
      $status_html = report_svg_stack($status_rows, 'État de traitement des observations');
      if ($slides) {
        echo $section('État de traitement', $status_html);
      } else {
        echo $section('État de traitement', $status_html . '<p class="note">Résolue : problème corrigé (résolution validée). Indiquée résolue : signalée comme corrigée, à vérifier. En cours / prise en compte : une résolution est engagée. Sans suite : aucune résolution.</p>');
      }
      if (count($city_rows) > 1) {
        echo $section('Répartition par ville', report_svg_bars($city_rows, 'Observations par ville'));
      }
      if (count($street_rows)) {
        echo $section('Rues les plus concernées', report_svg_bars($street_rows, 'Observations par rue'));
      }
      echo $section('Jours et heures', '<div class="two-cols"><div><h3>Jour de la semaine</h3>' . report_svg_columns($weekday_values, '#3f6fb5', 'Observations par jour de la semaine')
        . '</div><div><h3>Heure</h3>' . report_svg_columns($hour_values, '#3f6fb5', 'Observations par heure') . '</div></div>');
      echo $section('Carte des lieux', '<div id="report-map" class="report-map" data-groups="' . h(json_encode($map_groups)) . '" role="img" aria-label="Carte des lieux des observations"></div>'
        . '<p class="note">Un cercle par lieu ; sa taille dépend du nombre d\'observations, sa couleur de leur type.</p>');

      if (count($top_groups)) {
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
    }
    echo $section('Méthode', '<p class="note">' . h($method_text) . '</p><p class="note">Données : ' . h($instance_name) . ', application Vigilo (vigilo.city). Fond de carte © contributeurs OpenStreetMap.</p>', 'method');
    ?>

    <script src="assets/vendor/leaflet/leaflet.js"></script>
    <script src="assets/report.js?v=<?= intval(@filemtime(__DIR__ . '/assets/report.js')) ?>"></script>
  </body>
</html>
