<?php
/*
Copyright (C) 2020 Velocité Montpellier

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
    http_response_code(403);
    echo '<div class="alert alert-danger" role="alert">Accès refusé</div>';
    return;
}

// Reuse the SVG chart helpers of the reports (no external dependency)
require_once(__DIR__ . '/../../includes/report.php');

$self_url = '?page=' . urlencode($page_name);

// Human-readable label per route counted by common.php
$route_labels = array(
    'get_issues.php'       => 'Observations (chargement de l\'appli)',
    'get_scope.php'        => 'Territoire',
    'get_categories.php'   => 'Catégories',
    'get_banner.php'       => 'Bannière',
    'get_version.php'      => 'Version',
    'create_issue.php'     => 'Création d\'observation',
    'add_image.php'        => 'Envoi de photo',
    'create_resolution.php' => 'Création de résolution',
    'approve.php'          => 'Modération (publication)',
    'delete.php'           => 'Suppression',
    'acl.php'              => 'Clé / droits',
);

// Range: last N days (instance timezone), or everything
$ranges = array('30' => '30 jours', '90' => '90 jours', '365' => '1 an', 'all' => 'Tout');
$days = isset($_GET['days']) && isset($ranges[$_GET['days']]) ? $_GET['days'] : '30';
$start = ($days === 'all') ? null : date('Y-m-d', time() - (intval($days) - 1) * 86400);
$where = ($start !== null) ? "WHERE stat_date >= '" . mysqli_real_escape_string($db, $start) . "'" : '';

// Totals per route and per day over the range
$per_route = array();
$q = mysqli_query($db, "SELECT stat_route, SUM(stat_count) AS c FROM obs_api_stats $where GROUP BY stat_route ORDER BY c DESC");
while ($q && $row = mysqli_fetch_assoc($q)) {
    $per_route[$row['stat_route']] = intval($row['c']);
}
$per_day = array();
$q = mysqli_query($db, "SELECT stat_date, SUM(stat_count) AS c FROM obs_api_stats $where GROUP BY stat_date");
while ($q && $row = mysqli_fetch_assoc($q)) {
    $per_day[$row['stat_date']] = intval($row['c']);
}

// Unique visitors per day (obs_api_visitors, 0.0.41): one fingerprint per IP per day, so the number of
// rows of a day = unique visitors of that day. Empty before 0.0.41 (missing table: query returns false).
$visitors_by_day = array();
$q = mysqli_query($db, "SELECT stat_date, COUNT(*) AS v FROM obs_api_visitors $where GROUP BY stat_date");
while ($q && $row = mysqli_fetch_assoc($q)) {
    $visitors_by_day[$row['stat_date']] = intval($row['v']);
}

$total = array_sum($per_route);
$app_loads = isset($per_route['get_issues.php']) ? $per_route['get_issues.php'] : 0;
$visitors_total = array_sum($visitors_by_day);
$visitors_avg = count($visitors_by_day) ? (int) round($visitors_total / count($visitors_by_day)) : 0;
$visitors_max = $visitors_by_day ? max($visitors_by_day) : 0;

// Daily series: continuous (zeros filled) for a fixed window, sparse (sorted) for "all"
$daily = array();
if ($start !== null) {
    for ($d = $start; $d <= date('Y-m-d'); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $daily[date('d/m', strtotime($d))] = isset($per_day[$d]) ? $per_day[$d] : 0;
    }
} else {
    ksort($per_day);
    foreach ($per_day as $d => $c) {
        $daily[date('d/m', strtotime($d))] = $c;
    }
}

// Same daily series for unique visitors (aligned with the calls chart)
$visitors_daily = array();
if ($start !== null) {
    for ($d = $start; $d <= date('Y-m-d'); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $visitors_daily[date('d/m', strtotime($d))] = isset($visitors_by_day[$d]) ? $visitors_by_day[$d] : 0;
    }
} else {
    ksort($visitors_by_day);
    foreach ($visitors_by_day as $d => $c) {
        $visitors_daily[date('d/m', strtotime($d))] = $c;
    }
}
?>

<p class="text-body-secondary">Nombre d'appels à l'API de l'instance, agrégés par jour. Les pages de l'admin et les
images (photos, panneaux) ne sont pas comptées. « Observations » correspond au chargement de l'application par un
visiteur. Les <strong>visiteurs uniques</strong> sont comptés par une empreinte quotidienne de l'adresse IP (jamais
l'adresse elle-même) : un même visiteur n'est compté qu'une fois par jour et ne peut pas être suivi d'un jour à
l'autre.</p>

<div class="btn-group btn-group-sm mb-3" role="group" aria-label="Période">
<?php foreach ($ranges as $key => $label): ?>
  <a class="btn btn-outline-primary<?= $key === $days ? ' active' : '' ?>" href="<?= h($self_url . '&days=' . $key) ?>"><?= h($label) ?></a>
<?php endforeach ?>
</div>

<?php if ($total === 0): ?>
  <div class="alert alert-info" role="alert"><i class="bi bi-info-circle"></i> Aucun appel enregistré sur cette période.
    Les statistiques se remplissent au fil des visites (à partir de la version 0.0.40).</div>
<?php else: ?>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="h3 mb-0"><?= number_format($total, 0, ',', ' ') ?></div>
      <div class="text-body-secondary small">Appels à l'API</div>
    </div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="h3 mb-0"><?= number_format($app_loads, 0, ',', ' ') ?></div>
      <div class="text-body-secondary small">Chargements de l'appli (observations)</div>
    </div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="h3 mb-0"><?= number_format($visitors_avg, 0, ',', ' ') ?></div>
      <div class="text-body-secondary small">Visiteurs uniques par jour (moyenne)<?= $visitors_max ? ' — jusqu\'à ' . number_format($visitors_max, 0, ',', ' ') : '' ?></div>
    </div></div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card"><div class="card-body">
      <div class="h3 mb-0"><?= count($per_day) ?></div>
      <div class="text-body-secondary small">Jours avec activité</div>
    </div></div>
  </div>
</div>

<div class="card mb-3"><div class="card-body">
  <h2 class="h6">Appels par jour</h2>
  <div class="problem-chart"><?= report_svg_columns($daily, '#1565c0', 'Appels par jour') ?></div>
</div></div>

<?php if ($visitors_total > 0): ?>
<div class="card mb-3"><div class="card-body">
  <h2 class="h6">Visiteurs uniques par jour</h2>
  <div class="problem-chart"><?= report_svg_columns($visitors_daily, '#2e7d32', 'Visiteurs uniques par jour') ?></div>
</div></div>
<?php endif ?>

<?php
// Horizontal bars + table per route
$bars = array();
foreach ($per_route as $route => $count) {
    $label = isset($route_labels[$route]) ? $route_labels[$route] : $route;
    $bars[] = array($label, $count, '#1565c0');
}
?>
<div class="card"><div class="card-body">
  <h2 class="h6">Par type d'appel</h2>
  <div class="problem-chart mb-3"><?= report_svg_bars($bars, 'Appels par route') ?></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th>Type</th><th class="text-nowrap">Route</th><th class="text-end">Appels</th></tr></thead>
      <tbody>
<?php foreach ($per_route as $route => $count): ?>
        <tr>
          <td><?= h(isset($route_labels[$route]) ? $route_labels[$route] : $route) ?></td>
          <td><code><?= h($route) ?></code></td>
          <td class="text-end"><?= number_format($count, 0, ',', ' ') ?></td>
        </tr>
<?php endforeach ?>
      </tbody>
    </table>
  </div>
</div></div>

<?php endif ?>
