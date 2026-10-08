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
    exit('Not allowed');
}

$is_admin = ($_SESSION['role'] == 'admin');

/* Filter cities for the citystaff role, like the observations page */
$city_filter = '';
if ($_SESSION['role'] == 'citystaff') {
    $role_cityIds = getRoleCityIds($db, isset($_SESSION['login']) ? $_SESSION['login'] : '');

    // A citystaff without any city sees nothing
    $city_filter = empty($role_cityIds) ? ' AND 0' : ' AND obs_list.obs_city IN (' . implode(',', $role_cityIds) . ')';
}

/* Observation counts */
$count_total          = 0;
$count_complete       = 0;
$count_approved       = 0;
$count_disapprove     = 0;
$count_waitingapprove = 0;

$count_query = mysqli_query($db, "SELECT obs_complete, obs_approved, COUNT(*) AS nb FROM obs_list WHERE 1" . $city_filter . " GROUP BY obs_complete, obs_approved");
while ($count_query && $count_result = mysqli_fetch_array($count_query)) {
    $nb = intval($count_result['nb']);
    $count_total += $nb;
    if ($count_result['obs_complete'] == 1) {
        $count_complete += $nb;
        if ($count_result['obs_approved'] == 1) {
            $count_approved += $nb;
        } elseif ($count_result['obs_approved'] == 2) {
            $count_disapprove += $nb;
        } else {
            $count_waitingapprove += $nb;
        }
    }
}

/* Latest observations waiting for moderation */
$pending_list  = array();
$pending_query = mysqli_query($db, "SELECT obs_token, obs_time, obs_comment, obs_categorie FROM obs_list
                                    WHERE obs_complete=1 AND obs_approved=0" . $city_filter . "
                                    ORDER BY obs_time DESC LIMIT 5");
while ($pending_query && $pending_result = mysqli_fetch_array($pending_query)) {
    $pending_list[] = $pending_result;
}

/* Resolutions waiting for validation (status 4) */
$resolutions_tovalidate = 0;
$resolutions_query = mysqli_query($db, "SELECT COUNT(DISTINCT obs_resolutions.resolution_id) FROM obs_resolutions
                                        INNER JOIN obs_resolutions_tokens ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid
                                        INNER JOIN obs_list ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid
                                        WHERE obs_resolutions.resolution_status=4" . $city_filter);
if ($resolutions_query) {
    $resolutions_tovalidate = intval(mysqli_fetch_array($resolutions_query)[0]);
}

$stats = array(
    array('label' => 'Observations totales', 'value' => $count_total, 'icon' => 'collection', 'color' => 'primary'),
    array('label' => 'Complètes', 'value' => $count_complete, 'icon' => 'clipboard-check', 'color' => 'info'),
    array('label' => 'Approuvées', 'value' => $count_approved, 'icon' => 'check-circle', 'color' => 'success'),
    array('label' => 'Désapprouvées', 'value' => $count_disapprove, 'icon' => 'x-circle', 'color' => 'danger'),
    array('label' => 'En attente de modération', 'value' => $count_waitingapprove, 'icon' => 'hourglass-split', 'color' => 'warning')
);

if (file_exists('../install.php')) {
?>
<div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
  <i class="bi bi-exclamation-octagon-fill"></i>
  <div><strong>Installation !</strong> Veuillez supprimer le fichier ./install.php</div>
</div>
<?php
}
?>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-5 g-3 mb-4">
<?php foreach ($stats as $stat) { ?>
  <div class="col">
    <div class="card stat-card h-100 shadow-sm border-start border-4 border-<?= h($stat['color']) ?>">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="text-body-secondary small text-uppercase"><?= h($stat['label']) ?></div>
          <div class="display-6"><?= intval($stat['value']) ?></div>
        </div>
        <i class="bi bi-<?= h($stat['icon']) ?> fs-1 text-<?= h($stat['color']) ?> opacity-75" aria-hidden="true"></i>
      </div>
    </div>
  </div>
<?php } ?>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-hourglass-split"></i> Dernières observations en attente de modération</span>
        <a class="btn btn-sm btn-outline-primary" href="?page=observations&amp;approved=0">Tout voir</a>
      </div>
<?php if (empty($pending_list)) { ?>
      <div class="card-body text-body-secondary">Aucune observation en attente de modération.</div>
<?php } else { ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle table-admin table-stack-md mb-0">
          <thead>
            <tr>
              <th scope="col">Token</th>
              <th scope="col">Date</th>
              <th scope="col">Commentaire</th>
              <th scope="col">Catégorie</th>
              <th scope="col" class="text-end"></th>
            </tr>
          </thead>
          <tbody>
<?php
    foreach ($pending_list as $pending) {
        $pending_token = (string) $pending['obs_token'];
        $pending_time  = intval($pending['obs_time']);
        $pending_url   = '?page=observations&approved=0&filtertype=uniq&filtertoken=' . urlencode($pending_token);
?>
            <tr>
              <td><code><?= h($pending_token) ?></code></td>
              <td class="text-nowrap"><?= $pending_time > 0 ? h(date('d/m/Y H:i', $pending_time)) : '' ?></td>
              <td class="text-break"><?= h($pending['obs_comment']) ?></td>
              <td><span class="badge text-bg-secondary"><?= intval($pending['obs_categorie']) ?></span></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h($pending_url) ?>"><i class="bi bi-eye"></i> Voir</a></td>
            </tr>
<?php } ?>
          </tbody>
        </table>
      </div>
<?php } ?>
    </div>
  </div>

  <div class="col-lg-4 d-flex flex-column gap-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 card-title"><i class="bi bi-person-check"></i> Résolutions à valider</h2>
        <p class="display-6 mb-2"><?= intval($resolutions_tovalidate) ?></p>
        <a class="btn btn-sm btn-outline-primary" href="?page=resolutions&amp;resolved=4">Voir les résolutions</a>
      </div>
    </div>

<?php if ($is_admin) { ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 card-title"><i class="bi bi-info-circle"></i> Version</h2>
        <p class="mb-2">Vigilo backend <strong><?= h(BACKEND_VERSION) ?></strong></p>
        <a class="btn btn-sm btn-outline-secondary" href="?page=update"><i class="bi bi-arrow-repeat"></i> Mises à jour</a>
      </div>
    </div>
<?php } ?>
  </div>
</div>
