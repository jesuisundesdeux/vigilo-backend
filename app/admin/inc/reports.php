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

if (!isset($page_name) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $menu[$page_name]['access'])) {
  exit('Not allowed');
}

/* Report for a local authority: the form opens admin/report.php (document or slides) in a new tab */
$report_cities = array();
$cities_query = mysqli_query($db, "SELECT city_id, city_name FROM obs_cities ORDER BY city_name");
while ($cities_query && $row = mysqli_fetch_array($cities_query)) {
  $report_cities[intval($row['city_id'])] = (string) $row['city_name'];
}
if ($_SESSION['role'] == 'citystaff') {
  $allowed = getRoleCityIds($db, (string) $_SESSION['login']);
  $report_cities = array_intersect_key($report_cities, array_flip($allowed));
}
$report_categories = array();
foreach (getCategoriesList() as $categorie) {
  if (isset($categorie['catid'])) {
    $report_categories[intval($categorie['catid'])] = (string) $categorie['catname'] . (!empty($categorie['catdisable']) ? ' (désactivée)' : '');
  }
}
?>
<p class="text-body-secondary">Rapport des observations publiées pour une collectivité : chiffres clés, statistiques, lieux où les observations se répètent (observations similaires regroupées) et carte. Il s'ouvre dans un nouvel onglet, en <strong>document</strong> (A4) ou en <strong>diaporama</strong> (16:9), et s'enregistre en PDF avec le bouton « Imprimer / PDF » (fonction d'impression du navigateur). Les observations archivées sont comptées.</p>

<?php if ($_SESSION['role'] == 'citystaff' && count($report_cities) == 0) { ?>
<div class="alert alert-info">Aucune ville n'est associée à votre compte : demandez à un administrateur de vous en attribuer.</div>
<?php } else { ?>
<form method="GET" action="report.php" target="_blank" class="card shadow-sm" data-report-form>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-lg-6">
        <label for="report_title" class="form-label fw-semibold">Titre du rapport <span class="text-body-secondary small fw-normal">(facultatif)</span></label>
        <input type="text" class="form-control" id="report_title" name="title" maxlength="120" placeholder="Ex. : Bilan des observations — Ville de Testville">
      </div>
      <div class="col-sm-6 col-lg-3">
        <label for="report_from" class="form-label fw-semibold">Du</label>
        <input type="date" class="form-control" id="report_from" name="from" value="<?= h(date('Y-m-d', strtotime('-1 year +1 day'))) ?>" required>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label for="report_to" class="form-label fw-semibold">Au</label>
        <input type="date" class="form-control" id="report_to" name="to" value="<?= h(date('Y-m-d')) ?>" required>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2" aria-label="Périodes rapides">
        <span class="small text-body-secondary align-self-center me-1">Périodes :</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-report-period="3m">3 derniers mois</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-report-period="12m">12 derniers mois</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-report-period="year">Année en cours</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-report-period="lastyear">Année précédente</button>
      </div>

      <fieldset class="col-lg-6">
        <legend class="form-label fw-semibold fs-6">Villes <span class="text-body-secondary small fw-normal">(aucune cochée : <?= $_SESSION['role'] == 'citystaff' ? 'toutes vos villes' : 'toute l\'instance' ?>)</span></legend>
        <div class="report-checks border rounded p-2">
          <?php foreach ($report_cities as $city_id => $city_name) { ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="cities[]" value="<?= intval($city_id) ?>" id="report_city_<?= intval($city_id) ?>">
            <label class="form-check-label" for="report_city_<?= intval($city_id) ?>"><?= h($city_name) ?></label>
          </div>
          <?php } ?>
          <?php if (count($report_cities) == 0) { ?><p class="small text-body-secondary mb-0">Aucune ville configurée.</p><?php } ?>
        </div>
      </fieldset>

      <fieldset class="col-lg-6">
        <legend class="form-label fw-semibold fs-6">Types d'observation <span class="text-body-secondary small fw-normal">(aucun coché : tous)</span></legend>
        <div class="report-checks border rounded p-2">
          <?php foreach ($report_categories as $cat_id => $cat_name) { ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="categories[]" value="<?= intval($cat_id) ?>" id="report_cat_<?= intval($cat_id) ?>">
            <label class="form-check-label" for="report_cat_<?= intval($cat_id) ?>"><?= h($cat_name) ?></label>
          </div>
          <?php } ?>
        </div>
      </fieldset>

      <div class="col-sm-6 col-lg-4">
        <label for="report_radius" class="form-label fw-semibold">Regrouper les observations similaires</label>
        <select class="form-select" id="report_radius" name="radius">
          <option value="25">Même catégorie, à moins de 25 m</option>
          <option value="50" selected>Même catégorie, à moins de 50 m</option>
          <option value="100">Même catégorie, à moins de 100 m</option>
          <option value="200">Même catégorie, à moins de 200 m</option>
        </select>
        <div class="form-text">Les observations de la même catégorie dans la même rue sont aussi regroupées.</div>
      </div>
      <fieldset class="col-sm-6 col-lg-4">
        <legend class="form-label fw-semibold fs-6">Format</legend>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="format" value="document" id="report_format_document" checked>
          <label class="form-check-label" for="report_format_document"><i class="bi bi-file-earmark-text"></i> Document (A4, PDF)</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="format" value="slides" id="report_format_slides">
          <label class="form-check-label" for="report_format_slides"><i class="bi bi-easel"></i> Diaporama (16:9)</label>
        </div>
      </fieldset>
    </div>
  </div>
  <div class="card-footer d-flex justify-content-end">
    <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-bar-graph"></i> Générer le rapport</button>
  </div>
</form>
<?php } ?>
