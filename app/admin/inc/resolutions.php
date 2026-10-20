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

/* Moves a resolution to a new status if the role and the transition allow it. Returns '' or the reason of the refusal */
function resolutionSetStatus($resolutionid, $new_status)
{
    global $db, $status_list;
    $query   = mysqli_query($db, "SELECT resolution_status FROM obs_resolutions WHERE resolution_id = " . intval($resolutionid) . " LIMIT 1");
    $row     = $query ? mysqli_fetch_array($query) : null;
    $current = $row ? intval($row['resolution_status']) : -1;
    if (!isset($status_list[$new_status]) || !in_array($_SESSION['role'], $status_list[$new_status]['roles'])
        || !isset($status_list[$current]) || ($new_status != $current && !in_array($new_status, $status_list[$current]['nextstatus']))) {
        return 'changement d\'état non autorisé';
    }
    try {
        updateResolution(array('resolution_status' => $new_status), $resolutionid);
    } catch (Exception $e) {
        return $e->getMessage();
    }
    audit_log('resolution_status', 'resolution:' . intval($resolutionid), 'new_status:' . intval($new_status));
    resolutionStatusWebhooks($resolutionid, $current, $new_status);
    return '';
}

/* Webhooks of a change of status (resolution.status_changed); failures are shown */
function resolutionStatusWebhooks($resolutionid, $before, $after)
{
    global $db;
    foreach (webhooks_on_resolution_status($db, $resolutionid, $before, $after) as $hook_id => $delivery) {
        if ($delivery[1] !== '') {
            echo '<div class="alert alert-warning" role="alert">Webhook <strong>#' . intval($hook_id) . '</strong> en échec pour la résolution <strong>' . intval($resolutionid) . '</strong> : ' . h($delivery[1]) . ' (voir la page Webhooks).</div>';
        }
    }
}

/* Search: token or comment of the resolution, or token of one of its observations */
$search_q   = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$search_sql = '';
if ($search_q !== '') {
    $like       = mysqli_real_escape_string($db, addcslashes($search_q, '%_\\'));
    $search_sql = " AND (obs_resolutions.resolution_token LIKE '%" . $like . "%' OR obs_resolutions.resolution_comment LIKE '%" . $like . "%'
                    OR obs_resolutions.resolution_id IN (SELECT search_tokens.restok_resolutionid FROM obs_resolutions_tokens search_tokens
                       INNER JOIN obs_list search_obs ON search_obs.obs_id = search_tokens.restok_observationid
                       WHERE search_obs.obs_token LIKE '%" . $like . "%'))";
}
$urlsuffix = $search_q !== '' ? '&q=' . urlencode($search_q) : '';

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
                    $status_before = webhook_resolution_state($db, $resolutionid);
                    updateResolution($resolution_updatefields, $resolutionid);
                    audit_log('resolution_edit', 'resolution:' . $resolutionid, implode(',', array_keys($resolution_updatefields)));
                    if (isset($resolution_updatefields['resolution_status'])) {
                        resolutionStatusWebhooks($resolutionid, $status_before, $resolution_updatefields['resolution_status']);
                    }
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
        $error = resolutionSetStatus($resolutionid, intval($_GET['new_status']));
        if ($error === '') {
            echo '<div class="alert alert-success" role="alert">Résolution <strong>' . intval($resolutionid) . '</strong> mise à jour</div>';
        } else {
            echo '<div class="alert alert-danger" role="alert">' . h($error) . '</div>';
        }
    }
}

/* Bulk actions on the checked resolutions (bulk_ids[]): status_<n> or delete */
$bulk_actions = array();
if (in_array($_SESSION['role'], $actions_acl['resolve']['access'])) {
    foreach (array(2 => 'Problème pris en compte', 3 => 'En cours de résolution', 4 => 'Résolution à valider', 1 => 'Résolution validée') as $bulk_status => $bulk_label) {
        if (in_array($_SESSION['role'], $status_list[$bulk_status]['roles'])) {
            $bulk_actions['status_' . $bulk_status] = 'Passer à « ' . $bulk_label . ' »';
        }
    }
}
if (in_array($_SESSION['role'], $actions_acl['delete']['access'])) {
    $bulk_actions['delete'] = 'Supprimer';
}
if (isset($_POST['bulk_action'])) {
    $bulk_key = (string) $_POST['bulk_action'];
    $bulk_ids = array();
    if (isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
        $bulk_ids = array_slice(array_unique(array_map('intval', $_POST['bulk_ids'])), 0, 1000);
    }
    if (!isset($bulk_actions[$bulk_key])) {
        echo '<div class="alert alert-danger" role="alert">Action non autorisée</div>';
    } else {
        $done   = 0;
        $errors = array();
        foreach ($bulk_ids as $bulk_id) {
            if (!resolutionAllowedForRole($bulk_id)) {
                $errors[] = '#' . $bulk_id . ' : hors de vos villes';
                continue;
            }
            if ($bulk_key == 'delete') {
                flushImagesCacheResolution($bulk_id);
                delResolution($bulk_id);
                audit_log('resolution_delete', 'resolution:' . $bulk_id);
                $done++;
            } else {
                $error = resolutionSetStatus($bulk_id, intval(substr($bulk_key, 7)));
                if ($error === '') {
                    $done++;
                } else {
                    $token = mysqli_query($db, "SELECT resolution_token FROM obs_resolutions WHERE resolution_id = " . $bulk_id . " LIMIT 1");
                    $token = $token ? mysqli_fetch_array($token) : null;
                    $errors[] = ($token ? $token['resolution_token'] : '#' . $bulk_id) . ' : ' . $error;
                }
            }
        }
        echo '<div class="alert alert-success" role="alert">' . h($bulk_actions[$bulk_key]) . ' : <strong>' . intval($done) . '</strong> résolution(s)</div>';
        if (!empty($errors)) {
            echo '<div class="alert alert-warning" role="alert">Non modifiée(s) :<ul class="mb-0">';
            foreach ($errors as $error) {
                echo '<li>' . h($error) . '</li>';
            }
            echo '</ul></div>';
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
                                             WHERE $filter_by_city 1 $search_sql
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
    1 => array('icon' => 'check2-square', 'name' => 'Résolution validée', 'short' => 'Valider', 'class' => 'btn-outline-success'),
    2 => array('icon' => 'eye', 'name' => 'Problème pris en compte', 'short' => 'Pris en compte', 'class' => 'btn-outline-info'),
    3 => array('icon' => 'clock', 'name' => 'En cours de résolution', 'short' => 'En cours', 'class' => 'btn-outline-primary'),
    4 => array('icon' => 'person-check', 'name' => 'Résolution à valider', 'short' => 'À valider', 'class' => 'btn-outline-warning')
);

/* Pagination */
if (isset($_GET['pagenb']) && is_numeric($_GET['pagenb']) && intval($_GET['pagenb']) > 0) {
    $pagenb = intval($_GET['pagenb']);
} else {
    $pagenb = 1;
}

$maxobsperpage = 25;
$offset        = ($pagenb - 1) * $maxobsperpage;

$base_query = "FROM obs_list
               INNER JOIN obs_resolutions_tokens ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid
               INNER JOIN obs_resolutions        ON obs_resolutions.resolution_id = obs_resolutions_tokens.restok_resolutionid
               WHERE $filter_by_city obs_resolutions.resolution_status='" . intval($resolved) . "'" . $search_sql;

$countpage_query = mysqli_query($db, "SELECT COUNT(DISTINCT obs_resolutions.resolution_id) $base_query");
$nbrows          = $countpage_query ? intval(mysqli_fetch_array($countpage_query)[0]) : 0;
$nbpages         = intval(ceil($nbrows / $maxobsperpage));

$query_resolution = mysqli_query($db, "SELECT DISTINCT obs_resolutions.* $base_query ORDER BY obs_resolutions.resolution_time DESC LIMIT " . intval($offset) . "," . intval($maxobsperpage));
$resolutions = array();
while ($query_resolution && $row = mysqli_fetch_array($query_resolution)) {
    $resolutions[intval($row['resolution_id'])] = $row;
}

/* Observations of the displayed resolutions, in one query */
$resolution_obs = array();
if ($resolutions) {
    $obs_query = mysqli_query($db, "SELECT obs_resolutions_tokens.restok_resolutionid, obs_list.obs_id, obs_list.obs_token
                                    FROM obs_resolutions_tokens INNER JOIN obs_list ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid
                                    WHERE obs_resolutions_tokens.restok_resolutionid IN (" . implode(',', array_keys($resolutions)) . ")
                                    ORDER BY obs_list.obs_time DESC");
    while ($obs_query && $row = mysqli_fetch_assoc($obs_query)) {
        $resolution_obs[intval($row['restok_resolutionid'])][intval($row['obs_id'])] = (string) $row['obs_token'];
    }
}

$page_url   = '?page=' . urlencode($page_name);
$tab_url    = $page_url . '&resolved=' . intval($resolved);
$list_url   = $tab_url . ($pagenb > 1 ? '&pagenb=' . $pagenb : '') . $urlsuffix;
$can_edit   = in_array($_SESSION['role'], $actions_acl['edit']['access']);
$can_manage = in_array($_SESSION['role'], $actions_acl['manageobs']['access']);
$can_delete = in_array($_SESSION['role'], $actions_acl['delete']['access']);
$can_status = in_array($_SESSION['role'], $actions_acl['resolve']['access']);
// Observations shown as chips, the others behind "+ n"
$chips_shown = 12;
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

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <form method="GET" action="" class="d-flex gap-2 flex-grow-1" role="search" style="max-width: 32rem;">
    <input type="hidden" name="page" value="<?= h($page_name) ?>" />
    <input type="hidden" name="resolved" value="<?= intval($resolved) ?>" />
    <label class="visually-hidden" for="resolution-search">Rechercher</label>
    <input type="search" class="form-control form-control-sm" id="resolution-search" name="q" value="<?= h($search_q) ?>" placeholder="Token de la résolution ou d'une observation, commentaire" />
    <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit"><i class="bi bi-search"></i> Rechercher</button>
    <?php if ($search_q !== '') { ?><a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?= h($tab_url) ?>"><i class="bi bi-x-lg"></i><span class="visually-hidden">Réinitialiser</span></a><?php } ?>
  </form>
  <span class="text-body-secondary small ms-auto"><strong><?= intval($nbrows) ?></strong> résolution(s)</span>
</div>

<?php if (!empty($bulk_actions) && $nbrows > 0) { ?>
<form method="POST" action="<?= h($list_url) ?>" id="bulk-form" class="card card-body shadow-sm mb-3 py-2" data-bulk>
  <?= csrf_field() ?>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <div class="form-check mb-0 me-2">
      <input class="form-check-input" type="checkbox" id="bulk-all" data-bulk-all />
      <label class="form-check-label" for="bulk-all">Tout sélectionner</label>
    </div>
    <span class="text-body-secondary small me-2"><strong data-bulk-count>0</strong> sélectionnée(s)</span>
    <label class="visually-hidden" for="bulk-action">Action sur la sélection</label>
    <select class="form-select form-select-sm w-auto" name="bulk_action" id="bulk-action">
      <option value="">Action sur la sélection…</option>
      <?php foreach ($bulk_actions as $bulk_key => $bulk_label) { ?>
      <option value="<?= h($bulk_key) ?>"<?= $bulk_key == 'delete' ? ' data-confirm="Supprimer %n résolution(s) ?"' : '' ?>><?= h($bulk_label) ?></option>
      <?php } ?>
    </select>
    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2-all"></i> Appliquer</button>
  </div>
</form>
<?php } ?>

<div class="card shadow-sm mb-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin table-compact table-stack-md mb-0">
      <thead class="table-light">
        <tr>
          <th scope="col">Résolution</th>
          <th scope="col">Commentaire</th>
          <th scope="col">Observations</th>
          <th scope="col" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
<?php
foreach ($resolutions as $res_id => $result_resolution) {
    $res_token      = (string) $result_resolution['resolution_token'];
    $res_time       = intval($result_resolution['resolution_time']);
    $currentstatus  = intval($result_resolution['resolution_status']);
    $photo_url      = 'photo.php?type=resolution&token=' . urlencode($res_token);
    $action_url     = $list_url . '&resolutionid=' . $res_id;
    $observations   = isset($resolution_obs[$res_id]) ? $resolution_obs[$res_id] : array();
    $with_date      = ($currentstatus == 4 || $currentstatus == 1);
    $fill           = array('resolution_id' => $res_id, 'resolution_comment' => (string) $result_resolution['resolution_comment'], 'with_date' => $with_date,
                            'post_date' => $res_time > 0 ? date('d/m/Y', $res_time) : '', 'post_heure' => $res_time > 0 ? date('H:i', $res_time) : '');
?>
        <tr>
          <td class="text-nowrap">
            <div class="d-flex align-items-center gap-2">
<?php if (!empty($bulk_actions)) { ?>
              <input class="form-check-input" type="checkbox" name="bulk_ids[]" value="<?= $res_id ?>" form="bulk-form" aria-label="Sélectionner <?= h($res_token) ?>" />
<?php } ?>
<?php if ($result_resolution['resolution_withphoto'] == 1) { ?>
              <a href="<?= h($photo_url) ?>" data-photo data-photo-title="Résolution <?= h($res_token) ?>"><img class="obs-thumb obs-thumb-sm" src="<?= h($photo_url) ?>" alt="Photo de la résolution <?= h($res_token) ?>" loading="lazy" /></a>
<?php } ?>
              <div>
                <code><?= h($res_token) ?></code>
                <div class="small text-body-secondary"><?= $res_time > 0 ? h(date('d/m/Y H:i', $res_time)) : 'Sans date' ?></div>
              </div>
            </div>
          </td>
          <td class="text-break"><?= trim((string) $result_resolution['resolution_comment']) !== '' ? h($result_resolution['resolution_comment']) : '<span class="text-body-secondary">—</span>' ?></td>
          <td>
            <div class="d-flex flex-wrap align-items-center gap-1 obs-chips">
              <span class="badge rounded-pill text-bg-secondary" title="Nombre d'observations"><?= count($observations) ?></span>
<?php
    $n = 0;
    foreach ($observations as $obs_id => $obs_token) {
        $n++;
        if ($n == $chips_shown + 1) {
            echo '<details class="obs-chips-more"><summary class="badge rounded-pill text-bg-light border">+ ' . (count($observations) - $chips_shown) . '</summary><div class="d-flex flex-wrap gap-1 mt-1">';
        }
        $is_duplicate = in_array($obs_id, $duplicateids);
?>
              <span class="obs-chip<?= $is_duplicate ? ' obs-chip-duplicate' : '' ?>"<?= $is_duplicate ? ' title="Observation présente dans plusieurs résolutions"' : '' ?>>
                <a href="<?= h('index.php?page=observations&filtertoken=' . urlencode($obs_token) . '&filtertype=uniq') ?>"><?php if ($is_duplicate) { ?><i class="bi bi-exclamation-triangle"></i> <?php } ?><?= h($obs_token) ?></a>
<?php if ($can_manage) { ?>
                <a class="obs-chip-remove" href="<?= h($action_url . '&action=deleteobs&obsid=' . $obs_id) ?><?= h(csrf_query()) ?>" data-confirm="Retirer l'observation <?= h($obs_token) ?> de cette résolution ?" title="Retirer de la résolution" aria-label="Retirer <?= h($obs_token) ?> de la résolution"><i class="bi bi-x"></i></a>
<?php } ?>
              </span>
<?php
    }
    if ($n > $chips_shown) {
        echo '</div></details>';
    }
    if ($can_manage) {
?>
              <form action="<?= h($list_url) ?>" method="POST" class="obs-chip-add">
                <?= csrf_field() ?>
                <input type="hidden" name="obsadd" value="1" />
                <input type="hidden" name="resolutionid" value="<?= $res_id ?>" />
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control" name="obstoken" value="" placeholder="Token" required aria-label="Token de l'observation à ajouter à <?= h($res_token) ?>" />
                  <button class="btn btn-outline-primary" type="submit" title="Ajouter l'observation"><i class="bi bi-plus-lg"></i><span class="visually-hidden">Ajouter l'observation</span></button>
                </div>
              </form>
<?php } ?>
            </div>
          </td>
          <td class="text-end">
            <div class="d-flex flex-wrap flex-lg-nowrap justify-content-end gap-1">
<?php
    if ($can_status && isset($status_list[$currentstatus])) {
        foreach ($status_links as $link_status => $link) {
            if (in_array($_SESSION['role'], $status_list[$link_status]['roles']) && in_array($link_status, $status_list[$currentstatus]['nextstatus'])) {
?>
              <a class="btn btn-sm <?= h($link['class']) ?>" href="<?= h($action_url . '&action=resolve&new_status=' . intval($link_status)) ?><?= h(csrf_query()) ?>" title="Passer à « <?= h($link['name']) ?> »"><i class="bi bi-<?= h($link['icon']) ?>"></i><span class="d-none d-lg-inline"> <?= h($link['short']) ?></span></a>
<?php
            }
        }
    }
    if ($can_edit) {
?>
              <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#resolutionModal"
                      data-title="Modifier la résolution <?= h($res_token) ?>" data-fill="<?= h(json_encode($fill)) ?>" title="Modifier"><i class="bi bi-pencil"></i><span class="visually-hidden">Modifier</span></button>
<?php
    }
    if ($can_delete) {
?>
              <a class="btn btn-sm btn-outline-danger" href="<?= h($action_url . '&action=delete') ?><?= h(csrf_query()) ?>" data-confirm="Supprimer la résolution <?= h($res_token) ?> ?" title="Supprimer"><i class="bi bi-trash"></i><span class="visually-hidden">Supprimer</span></a>
<?php } ?>
            </div>
          </td>
        </tr>
<?php
}
if (!$resolutions) {
?>
        <tr>
          <td colspan="4" class="text-center text-body-secondary py-4"><?= $search_q !== '' ? 'Aucune résolution ne correspond à la recherche' : 'Aucune résolution' ?></td>
        </tr>
<?php } ?>
      </tbody>
    </table>
  </div>
</div>

<?= admin_pagination($tab_url . $urlsuffix . '&pagenb=', $pagenb, $nbpages, 'Pagination des résolutions') ?>

<?php if ($can_edit) { ?>
<div class="modal fade" id="resolutionModal" tabindex="-1" aria-labelledby="resolutionModalTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="<?= h($list_url) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="resolution_id" value="" />
      <div class="modal-header">
        <h2 class="modal-title h5" id="resolutionModalTitle">Modifier la résolution</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="resolution-comment">Commentaire</label>
          <input type="text" class="form-control" id="resolution-comment" name="resolution_comment" value="" />
        </div>
        <div class="row g-2" data-fill-show="with_date">
          <div class="col-7">
            <label class="form-label" for="resolution-date">Date</label>
            <input type="text" class="form-control" id="resolution-date" name="post_date" value="" placeholder="jj/mm/aaaa" required />
          </div>
          <div class="col-5">
            <label class="form-label" for="resolution-time">Heure</label>
            <input type="text" class="form-control" id="resolution-time" name="post_heure" value="" placeholder="hh:mm" required />
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php } ?>
