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

/* Defines acls for this page used by roles */
$actions_acl = array(
    "delete" => array(
        "access" => array(
            'admin'
        )
    ),
    "resolve" => array(
        "access" => array(
            'admin',
            'citystaff'
        )
    ),
    "manageobs" => array(
        "access" => array(
            'admin',
            'citystaff'
        )
    ),
    "edit" => array(
        "access" => array(
            'admin',
            'citystaff'
        )
    )
);

// Filter cities for the current role
$filter_by_city = '';
$role_cityIds   = array();
$is_citystaff   = (isset($_SESSION['role']) && $_SESSION['role'] == 'citystaff');

if ($is_citystaff) {
    $role_cityIds = getRoleCityIds($db, isset($_SESSION['login']) ? $_SESSION['login'] : '');

    // Filter obs by city when the current user has the citystaff role (#240).
    // A citystaff without any city sees nothing.
    if (!empty($role_cityIds)) {
        $filter_by_city .= "obs_list.obs_city IN (" . implode(",", $role_cityIds) . ") AND ";
    } else {
        $filter_by_city .= "0 AND ";
    }
}

/* Can the current user act on this resolution? A citystaff only on resolutions with observations in their cities (#240) */
function resolutionAllowedForRole($resolutionid)
{
    global $db, $is_citystaff, $role_cityIds;
    $resolutionid = intval($resolutionid);
    if ($resolutionid <= 0) {
        return false;
    }
    if (!$is_citystaff) {
        return true;
    }
    if (empty($role_cityIds)) {
        return false;
    }
    $query = mysqli_query($db, "SELECT COUNT(*) FROM obs_resolutions_tokens
                                INNER JOIN obs_list ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid
                                WHERE obs_resolutions_tokens.restok_resolutionid = " . $resolutionid . "
                                AND obs_list.obs_city IN (" . implode(",", $role_cityIds) . ")");
    return $query && mysqli_fetch_array($query)[0] > 0;
}

/* Can the current user attach this observation? A citystaff only observations in their cities */
function observationAllowedForRole($obsid)
{
    global $db, $is_citystaff, $role_cityIds;
    $obsid = intval($obsid);
    if ($obsid <= 0) {
        return false;
    }
    if (!$is_citystaff) {
        return true;
    }
    if (empty($role_cityIds)) {
        return false;
    }
    $query = mysqli_query($db, "SELECT COUNT(*) FROM obs_list
                                WHERE obs_id = " . $obsid . "
                                AND obs_city IN (" . implode(",", $role_cityIds) . ")");
    return $query && mysqli_fetch_array($query)[0] > 0;
}

function resolutionNotAllowedAlert()
{
    echo '<div class="alert alert-danger" role="alert"><i class="bi bi-shield-x"></i> Action non autorisée sur cette résolution</div>';
}

$urlsuffix = "";

/* Forms handling */
if (isset($_POST['obsadd']) && in_array($_SESSION['role'], $actions_acl['manageobs']['access'])) {
    $tokentoadd   = isset($_POST['obstoken']) ? trim((string) $_POST['obstoken']) : '';
    $resolutionid = isset($_POST['resolutionid']) ? intval($_POST['resolutionid']) : 0;
    $tokentoadd_sql = mysqli_real_escape_string($db, $tokentoadd);

    if (!resolutionAllowedForRole($resolutionid)) {
        resolutionNotAllowedAlert();
    } elseif ($tokentoadd !== '' && isTokenExists($tokentoadd_sql)) {
        $obsidtoadd = intval(getObsIdByToken($tokentoadd_sql));
        if (!observationAllowedForRole($obsidtoadd)) {
            echo '<div class="alert alert-danger" role="alert">Observation <strong>' . h($tokentoadd) . '</strong> hors de vos villes</div>';
        } else {
            addObsToResolution($obsidtoadd, $resolutionid);
            flushImagesCacheResolution($resolutionid);
            audit_log('resolution_add_observation', 'resolution:' . $resolutionid, 'obs:' . $tokentoadd);
            echo '<div class="alert alert-success" role="alert">Observation <strong>' . h($tokentoadd) . '</strong> ajoutée à la résolution</div>';
        }
    } else {
        echo '<div class="alert alert-warning" role="alert">Observation <strong>' . h($tokentoadd) . '</strong> introuvable</div>';
    }
}

if (isset($_POST['resolution_id']) && in_array($_SESSION['role'], $actions_acl['edit']['access'])) {
    $resolutionid = intval($_POST['resolution_id']);

    if (!resolutionAllowedForRole($resolutionid)) {
        resolutionNotAllowedAlert();
    } else {
        $resolution_updatefields = array();
        $date_error              = false;

        // The date is only sent for resolutions waiting validation or resolved
        if (isset($_POST['post_date']) && isset($_POST['post_heure'])) {
            $resolutiontime = parseAdminDateTime((string) $_POST['post_date'], (string) $_POST['post_heure']);
            if ($resolutiontime === False) {
                $date_error = true;
            } else {
                $resolution_updatefields['resolution_time'] = intval($resolutiontime);
            }
        }

        if ($date_error) {
            echo '<div class="alert alert-danger" role="alert">Format de date incorrect</div>';
        } else {
            /* Only these columns can be edited: field names are never used as SQL as is */
            if (isset($_POST['resolution_comment'])) {
                $resolution_updatefields['resolution_comment'] = mysqli_real_escape_string($db, (string) $_POST['resolution_comment']);
            }
            if (isset($_POST['resolution_status']) && is_numeric($_POST['resolution_status'])) {
                $posted_status = intval($_POST['resolution_status']);
                $current_query  = mysqli_query($db, "SELECT resolution_status FROM obs_resolutions WHERE resolution_id = " . intval($resolutionid) . " LIMIT 1");
                $current_row    = $current_query ? mysqli_fetch_array($current_query) : null;
                $current_status = $current_row ? intval($current_row['resolution_status']) : -1;
                if (!isset($status_list[$posted_status]) || !in_array($_SESSION['role'], $status_list[$posted_status]['roles'])
                    || ($posted_status != $current_status && (!isset($status_list[$current_status]) || !in_array($posted_status, $status_list[$current_status]['nextstatus'])))) {
                    exit('Not allowed');
                }
                $resolution_updatefields['resolution_status'] = $posted_status;
            }

            if (!empty($resolution_updatefields)) {
                try {
                    updateResolution($resolution_updatefields, $resolutionid);
                    audit_log('resolution_edit', 'resolution:' . $resolutionid, implode(',', array_keys($resolution_updatefields)));
                    echo '<div class="alert alert-success" role="alert">Résolution <strong>' . intval($resolutionid) . '</strong> mise à jour</div>';
                } catch (Exception $e) {
                    echo '<div class="alert alert-danger" role="alert">' . h($e->getMessage()) . '</div>';
                }
            }
        }
    }
}

if (isset($_POST['resolution_add']) && $_POST['resolution_add'] != 0 && is_numeric($_POST['resolution_add']) && isset($_POST['obs_id']) && is_numeric($_POST['obs_id'])
    && in_array($_SESSION['role'], $actions_acl['manageobs']['access'])) {
    $add_resolutionid = intval($_POST['resolution_add']);
    $add_obsid        = intval($_POST['obs_id']);
    if (resolutionAllowedForRole($add_resolutionid) && observationAllowedForRole($add_obsid)) {
        addObsToResolution($add_obsid, $add_resolutionid);
        flushImagesCacheResolution($add_resolutionid);
        audit_log('resolution_add_observation', 'resolution:' . $add_resolutionid, 'obs_id:' . $add_obsid);
    } else {
        resolutionNotAllowedAlert();
    }
}


/* Actions links */
if (isset($_GET['action']) && isset($_GET['resolutionid']) && is_numeric($_GET['resolutionid']) && !isset($_POST['resolutionid'])) {
    $action       = (string) $_GET['action'];
    $resolutionid = intval($_GET['resolutionid']);

    if (!resolutionAllowedForRole($resolutionid)) {
        resolutionNotAllowedAlert();
    } elseif ($action == "deleteobs" && isset($_GET['obsid']) && is_numeric($_GET['obsid']) && in_array($_SESSION['role'], $actions_acl['manageobs']['access'])
              && !observationAllowedForRole($_GET['obsid'])) {
        resolutionNotAllowedAlert();
    } elseif ($action == "deleteobs" && isset($_GET['obsid']) && is_numeric($_GET['obsid']) && in_array($_SESSION['role'], $actions_acl['manageobs']['access'])) {
        $delobsid    = intval($_GET['obsid']);
        $delobstoken = getTokenByObsid($delobsid);
        flushImagesCacheResolution($resolutionid);
        delObsToResolution($delobsid, $resolutionid);
        if ($delobstoken !== False) {
            delete_token_cache($delobstoken);
        }
        audit_log('resolution_remove_observation', 'resolution:' . $resolutionid, 'obs:' . ($delobstoken !== False ? $delobstoken : $delobsid));
        echo '<div class="alert alert-success" role="alert">Observation retirée de la résolution <strong>' . intval($resolutionid) . '</strong></div>';
    } elseif ($action == 'delete' && in_array($_SESSION['role'], $actions_acl['delete']['access'])) {
        flushImagesCacheResolution($resolutionid);
        delResolution($resolutionid);
        audit_log('resolution_delete', 'resolution:' . $resolutionid);
        echo '<div class="alert alert-success" role="alert">Résolution <strong>' . intval($resolutionid) . '</strong> supprimée</div>';
    } elseif ($action == 'resolve' && isset($_GET['new_status']) && is_numeric($_GET['new_status']) && in_array($_SESSION['role'], $actions_acl['resolve']['access'])) {
        $new_status = intval($_GET['new_status']);
        if (isset($status_list[$new_status]) && in_array($_SESSION['role'], $status_list[$new_status]['roles'])) {
            try {
                if (updateResolution(array('resolution_status' => $new_status), $resolutionid)) {
                    audit_log('resolution_status', 'resolution:' . $resolutionid, 'new_status:' . $new_status);
                    echo '<div class="alert alert-success" role="alert">Résolution <strong>' . intval($resolutionid) . '</strong> mise à jour</div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-danger" role="alert">' . h($e->getMessage()) . '</div>';
            }
        } else {
            exit('Not allowed');
        }
    }
}

$duplicateids = getDuplicateObsIdsInResolutions();
if (count($duplicateids) > 0) {
    echo '<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle"></i> <strong>' . intval(count($duplicateids)) . '</strong> observation(s) présente(s) dans plusieurs résolutions</div>';
}

// Tab filter process
if (isset($_GET['resolved']) && is_numeric($_GET['resolved'])) {
    $resolved = intval($_GET['resolved']);
} else {
    $resolved = 2;
}

$resolvecount = array(
    0 => 0,
    1 => 0,
    2 => 0,
    3 => 0,
    4 => 0
);
// One count per resolution (a resolution can hold several observations)
$query_count_tabs = mysqli_query($db, "SELECT obs_resolutions.resolution_status, COUNT(DISTINCT obs_resolutions.resolution_id) AS nb FROM obs_list
                                             INNER JOIN obs_resolutions_tokens ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid
                                             INNER JOIN obs_resolutions        ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid
                                             WHERE $filter_by_city 1
                                             GROUP BY obs_resolutions.resolution_status");
while ($query_count_tabs && $result_count_tabs = mysqli_fetch_array($query_count_tabs)) {
    $resolvecount[intval($result_count_tabs['resolution_status'])] = intval($result_count_tabs['nb']);
}

$tabs = array(
    2 => array('icon' => 'eye', 'name' => 'Prises en compte'),
    3 => array('icon' => 'clock', 'name' => 'En cours de résolution'),
    4 => array('icon' => 'person-check', 'name' => 'Indiquées résolues'),
    1 => array('icon' => 'check2-square', 'name' => 'Résolues')
);

/* Status change links: status => label and icon */
$status_links = array(
    1 => array('icon' => 'check2-square', 'name' => 'Résolution validée', 'class' => 'btn-outline-success'),
    2 => array('icon' => 'eye', 'name' => 'Problème pris en compte', 'class' => 'btn-outline-info'),
    3 => array('icon' => 'clock', 'name' => 'En cours de résolution', 'class' => 'btn-outline-primary'),
    4 => array('icon' => 'person-check', 'name' => 'Résolution à valider', 'class' => 'btn-outline-warning')
);

/* Pagination */
if (isset($_GET['pagenb']) && is_numeric($_GET['pagenb']) && intval($_GET['pagenb']) > 0) {
    $pagenb = intval($_GET['pagenb']);
} else {
    $pagenb = 1;
}

$maxobsperpage = 10;
$offset        = ($pagenb - 1) * $maxobsperpage;

$base_query = "FROM obs_list
               INNER JOIN obs_resolutions_tokens ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid
               INNER JOIN obs_resolutions        ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid
               WHERE $filter_by_city obs_resolutions.resolution_status='" . intval($resolved) . "'";

$countpage_query = mysqli_query($db, "SELECT COUNT(DISTINCT obs_resolutions.resolution_id) $base_query");
$nbrows          = $countpage_query ? intval(mysqli_fetch_array($countpage_query)[0]) : 0;
$nbpages         = intval(ceil($nbrows / $maxobsperpage));

$query_resolution = mysqli_query($db, "SELECT DISTINCT obs_resolutions.* $base_query ORDER BY obs_resolutions.resolution_time DESC LIMIT " . intval($offset) . "," . intval($maxobsperpage));

$page_url   = '?page=' . urlencode($page_name);
$tab_url    = $page_url . '&resolved=' . intval($resolved);
$can_edit   = in_array($_SESSION['role'], $actions_acl['edit']['access']);
$can_manage = in_array($_SESSION['role'], $actions_acl['manageobs']['access']);
$can_delete = in_array($_SESSION['role'], $actions_acl['delete']['access']);
$can_status = in_array($_SESSION['role'], $actions_acl['resolve']['access']);
?>

<ul class="nav nav-tabs mb-3">
<?php foreach ($tabs as $tab_status => $tab) { ?>
  <li class="nav-item">
    <a class="nav-link<?= $resolved == $tab_status ? ' active' : '' ?>"<?= $resolved == $tab_status ? ' aria-current="page"' : '' ?> href="<?= h($page_url . '&resolved=' . intval($tab_status) . $urlsuffix) ?>">
      <i class="bi bi-<?= h($tab['icon']) ?>"></i> <?= h($tab['name']) ?>
      <span class="badge rounded-pill text-bg-<?= $resolved == $tab_status ? 'primary' : 'secondary' ?> ms-1"><?= intval($resolvecount[$tab_status]) ?></span>
    </a>
  </li>
<?php } ?>
</ul>

<div class="card shadow-sm mb-3">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle table-admin mb-0">
        <thead class="table-light">
          <tr>
            <th scope="col">Token</th>
            <th scope="col">Photo</th>
            <th scope="col">Informations</th>
            <th scope="col">Observations</th>
            <th scope="col" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
<?php
$has_rows = false;
while ($query_resolution && $result_resolution = mysqli_fetch_array($query_resolution)) {
    $has_rows       = true;
    $res_id         = intval($result_resolution['resolution_id']);
    $res_token      = (string) $result_resolution['resolution_token'];
    $res_time       = intval($result_resolution['resolution_time']);
    $currentstatus  = intval($result_resolution['resolution_status']);
    $date           = $res_time > 0 ? date('d/m/Y', $res_time) : '';
    $heure          = $res_time > 0 ? date('H:i', $res_time) : '';
    $photo_url      = 'photo.php?type=resolution&token=' . urlencode($res_token);
    $form_id        = 'resolution-form-' . $res_id;
    $action_url     = $tab_url . '&resolutionid=' . $res_id . $urlsuffix;
?>
          <tr>
            <td><code><?= h($res_token) ?></code></td>
            <td>
<?php if ($result_resolution['resolution_withphoto'] == 1) { ?>
              <a href="<?= h($photo_url) ?>" target="_blank" rel="noopener"><img class="obs-thumb" src="<?= h($photo_url) ?>" alt="Photo de la résolution <?= h($res_token) ?>" loading="lazy" /></a>
<?php } else { ?>
              <span class="text-body-secondary"><i class="bi bi-image"></i> Pas de photo</span>
<?php } ?>
            </td>
            <td>
              <form id="<?= h($form_id) ?>" action="<?= h($tab_url . $urlsuffix) ?>" method="POST">
                <?= csrf_field() ?>
                <div class="mb-2">
                  <label class="form-label small fw-semibold mb-1" for="<?= h($form_id) ?>-comment">Commentaire</label>
                  <input type="text" class="form-control form-control-sm" id="<?= h($form_id) ?>-comment" name="resolution_comment" value="<?= h($result_resolution['resolution_comment']) ?>"<?= $can_edit ? '' : ' readonly' ?> />
                </div>
<?php if ($currentstatus == 4 || $currentstatus == 1) { ?>
                <div class="mb-2">
                  <label class="form-label small fw-semibold mb-1" for="<?= h($form_id) ?>-date">Date/heure</label>
                  <div class="row g-2">
                    <div class="col-7">
                      <input type="text" class="form-control form-control-sm" id="<?= h($form_id) ?>-date" name="post_date" value="<?= h($date) ?>" placeholder="jj/mm/aaaa" required<?= $can_edit ? '' : ' readonly' ?> />
                    </div>
                    <div class="col-5">
                      <input type="text" class="form-control form-control-sm" name="post_heure" value="<?= h($heure) ?>" placeholder="hh:mm" aria-label="Heure" required<?= $can_edit ? '' : ' readonly' ?> />
                    </div>
                  </div>
                </div>
<?php } ?>
<?php if ($can_edit) { ?>
                <input type="hidden" name="resolution_id" value="<?= $res_id ?>" />
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-save"></i> Mettre à jour</button>
<?php } ?>
              </form>
            </td>
            <td>
              <ul class="list-unstyled mb-2">
<?php
    foreach (getResolutionObservations($res_id) as $resolution_obsid) {
        $resolution_obsid = intval($resolution_obsid);
        $obs_token        = getTokenByObsid($resolution_obsid);
        $obs_token        = ($obs_token === False) ? '' : (string) $obs_token;
        $is_duplicate     = in_array($resolution_obsid, $duplicateids);
?>
                <li class="d-flex align-items-center gap-1 mb-1">
                  <a href="<?= h('index.php?page=observations&filtertoken=' . urlencode($obs_token) . '&filtertype=uniq') ?>"<?= $is_duplicate ? ' class="fw-bold text-danger" title="Observation présente dans plusieurs résolutions"' : '' ?>><?php if ($is_duplicate) { ?><i class="bi bi-exclamation-triangle"></i> <?php } ?><?= h($obs_token) ?></a>
<?php if ($can_manage) { ?>
                  <a class="btn btn-sm btn-link text-danger p-0 ms-1" href="<?= h($action_url . '&action=deleteobs&obsid=' . $resolution_obsid) ?><?= h(csrf_query()) ?>" data-confirm="Retirer l'observation de cette résolution ?" title="Retirer de la résolution" aria-label="Retirer <?= h($obs_token) ?> de la résolution"><i class="bi bi-trash"></i></a>
<?php } ?>
                </li>
<?php
    }
?>
              </ul>
<?php if ($can_manage) { ?>
              <form action="<?= h($tab_url . $urlsuffix) ?>" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="obsadd" value="1" />
                <input type="hidden" name="resolutionid" value="<?= $res_id ?>" />
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control" name="obstoken" value="" placeholder="Token" aria-label="Token de l'observation à ajouter" />
                  <button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg"></i> Ajouter obs</button>
                </div>
              </form>
<?php } ?>
            </td>
            <td class="text-end">
              <div class="d-flex flex-column align-items-end gap-1">
<?php
    if ($can_status && isset($status_list[$currentstatus])) {
        foreach ($status_links as $link_status => $link) {
            if (in_array($_SESSION['role'], $status_list[$link_status]['roles']) && in_array($link_status, $status_list[$currentstatus]['nextstatus'])) {
?>
                <a class="btn btn-sm <?= h($link['class']) ?>" href="<?= h($action_url . '&action=resolve&new_status=' . intval($link_status)) ?><?= h(csrf_query()) ?>"><i class="bi bi-<?= h($link['icon']) ?>"></i> <?= h($link['name']) ?></a>
<?php
            }
        }
    }
    if ($can_delete) {
?>
                <a class="btn btn-sm btn-outline-danger" href="<?= h($action_url . '&action=delete') ?><?= h(csrf_query()) ?>" data-confirm="Merci de valider la suppression"><i class="bi bi-trash"></i> Supprimer</a>
<?php } ?>
              </div>
            </td>
          </tr>
<?php
}
if (!$has_rows) {
?>
          <tr>
            <td colspan="5" class="text-center text-body-secondary py-4">Aucune résolution</td>
          </tr>
<?php } ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer text-body-secondary small">
    <strong><?= intval($nbrows) ?></strong> résolution(s)
  </div>
</div>

<?php
if ($nbpages > 1) {
?>
<nav aria-label="Pagination des résolutions">
  <ul class="pagination pagination-sm flex-wrap">
    <li class="page-item<?= $pagenb <= 1 ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= h($tab_url . '&pagenb=' . max(1, $pagenb - 1) . $urlsuffix) ?>" aria-label="Précédent"><i class="bi bi-chevron-left"></i></a>
    </li>
<?php
    for ($i = 1; $i <= $nbpages; $i++) {
?>
    <li class="page-item<?= $pagenb == $i ? ' active' : '' ?>"<?= $pagenb == $i ? ' aria-current="page"' : '' ?>><a class="page-link" href="<?= h($tab_url . '&pagenb=' . $i . $urlsuffix) ?>"><?= intval($i) ?></a></li>
<?php
    }
?>
    <li class="page-item<?= $pagenb >= $nbpages ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= h($tab_url . '&pagenb=' . min($nbpages, $pagenb + 1) . $urlsuffix) ?>" aria-label="Suivant"><i class="bi bi-chevron-right"></i></a>
    </li>
  </ul>
</nav>
<?php
}
?>
