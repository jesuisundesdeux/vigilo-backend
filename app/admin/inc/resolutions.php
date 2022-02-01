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

if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'], $menu[$page_name]['access']))) {
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


$urlsuffix = "";

/* Forms handling */
if (isset($_POST['obsadd']) && in_array($_SESSION['role'], $actions_acl['manageobs']['access'])) {
    $tokentoadd   = mysqli_real_escape_string($db, $_POST['obstoken']);
    $resolutionid = mysqli_real_escape_string($db, $_POST['resolutionid']);
    if (isTokenExists($db, $tokentoadd)) {
        addObsToResolution($db, getObsIdByToken($db, $tokentoadd), $resolutionid);
    }
}
if (isset($_POST['resolution_id']) && in_array($_SESSION['role'], $actions_acl['edit']['access'])) {
    $resolutionid = mysqli_real_escape_string($db, $_POST['resolution_id']);
    $update       = "";
    
    $resolutiontime = 0;
    if (isset($_POST['post_date']) && isset($_POST['post_heure'])) {
        $resolutiontime = strptime($_POST['post_date'] . ' ' . $_POST['post_heure'], '%d/%m/%Y %H:%M');
        $resolutiontime = mktime($resolutiontime['tm_hour'], $resolutiontime['tm_min'], 0, $resolutiontime['tm_mon'] + 1, $resolutiontime['tm_mday'], $resolutiontime['tm_year'] + 1900);
    }
    
    if ($resolutiontime != 0 && (strlen($_POST['post_date']) != 10 || strlen($_POST['post_heure']) != 5)) {
        echo '<div class="alert alert-danger" role="alert">Format de date incorrect</div>';
    } else {
        $update = "resolution_time='" . $resolutiontime . "',";
        
        foreach ($_POST as $key => $value) {
            if (preg_match('/resolution_(?:.*)$/', $key)) {
                $key   = mysqli_real_escape_string($db, $key);
                $value = mysqli_real_escape_string($db, $value);
                $update .= $key . "='" . $value . "',";
            }
        }
        
        $update = rtrim($update, ',');
        mysqli_query($db, "UPDATE obs_resolutions SET " . $update . " WHERE resolution_id='" . $resolutionid . "'");
        echo mysqli_error($db);
        
        echo '<div class="alert alert-success" role="alert">Resolution <strong>' . $resolutionid . '</strong> mise à jour</div>';
    }
}
if (isset($_POST['resolution_add']) && $_POST['resolution_add'] != 0 && is_numeric($_POST['resolution_add'])) {
    addObsToResolution($db, $_POST['obs_id'], $_POST['resolution_add']);
}


/* Actions links */
if (isset($_GET['action']) && isset($_GET['resolutionid']) && is_numeric($_GET['resolutionid']) && !isset($_POST['resolutionid'])) {
    $action       = $_GET['action'];
    $resolutionid = mysqli_real_escape_string($db, $_GET['resolutionid']);
    
    if ($action == "deleteobs" && is_numeric($_GET['resolutionid']) && in_array($_SESSION['role'], $actions_acl['manageobs']['access'])) {
        delObsToResolution($db, $_GET['obsid'], $_GET['resolutionid']);
    } elseif ($action == 'delete' && in_array($_SESSION['role'], $actions_acl['delete']['access'])) {
        delResolution($db, $resolutionid);
        echo '<div class="alert alert-success" role="alert">Resolution <strong>' . $resolutionid . '</strong> supprimée</div>';
        
    } elseif ($action == 'resolve' && is_numeric($_GET['new_status']) && in_array($_SESSION['role'], $actions_acl['resolve']['access'])) {
        $new_status = $_GET['new_status'];
        if (in_array($_SESSION['role'], $status_list[$new_status]['roles'])) {
            $resolutiontime_query  = mysqli_query($db, "SELECT resolution_time FROM obs_resolutions WHERE resolution_id='" . $resolutionid . "' LIMIT 1");
            $resolutiontime_result = mysqli_fetch_array($resolutiontime_query);
            if ($resolutiontime_result['resolution_time'] == 0 && $new_status == 1) {
                echo '<div class="alert alert-danger" role="alert">La date doit être renseignée pour valider une resolution</div>';
            } else {
                mysqli_query($db, "UPDATE obs_resolutions SET resolution_status = '" . $new_status . "' WHERE resolution_id = '" . $resolutionid . "'");
                if ($new_status == 1 || $new_status == 0) {
                    flushImagesCacheResolution($db, $resolutionid);
                }
            }
        } else {
            exit('Not allowed');
        }
    }
}

// Check duplicate observations in resolutions
$obsduplicate_query = mysqli_query($db, "select restok_observationid,count(*) as nb from obs_resolutions_tokens group by restok_observationid");
$duplicateids       = array();
while ($obsduplicate_result = mysqli_fetch_array($obsduplicate_query)) {
    if ($obsduplicate_result['nb'] > 1) {
        $duplicateids[] = $obsduplicate_result['restok_observationid'];
    }
}
if (count($duplicateids) > 0) {
    echo '<div class="alert alert-warning" role="alert"><strong>' . count($duplicateids) . '</strong> observation(s) présente(s) dans plusieurs resolutions</div>';
}

// Tab filter process
if (isset($_GET['resolved']) && is_numeric($_GET['resolved'])) {
    $resolved = mysqli_real_escape_string($db, $_GET['resolved']);
} else {
    $resolved = 2;
}

$tabresolved[1]         = "";
$tabresolved[2]         = "";
$tabresolved[3]         = "";
$tabresolved[4]         = "";
$tabresolved[$resolved] = "active";

$resolvecount     = array(
    0 => 0,
    1 => 0,
    2 => 0,
    3 => 0,
    4 => 0
);
$query_count_tabs = mysqli_query($db, "SELECT resolution_status FROM obs_resolutions");
while ($result_count_tabs = mysqli_fetch_array($query_count_tabs)) {
    $resolvecount[$result_count_tabs['resolution_status']]++;
}

?>
<h2>Liste</h2>

<ul class="nav nav-tabs">
  <li class="nav-item">
    <a class="nav-link <?= $tabresolved[2] ?>" href="?page=<?= $page_name ?>&resolved=2<?= $urlsuffix ?>"><span data-feather="eye"></span> Prises en compte <span class="badge badge-info"><?= $resolvecount[2] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tabresolved[3] ?>" href="?page=<?= $page_name ?>&resolved=3<?= $urlsuffix ?>"><span data-feather="clock"></span> En cours de résolution <span class="badge badge-info"><?= $resolvecount[3] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tabresolved[4] ?>" href="?page=<?= $page_name ?>&resolved=4<?= $urlsuffix ?>"><span data-feather="user-check"></span> Indiquées résolues <span class="badge badge-info"><?= $resolvecount[4] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tabresolved[1] ?>" href="?page=<?= $page_name ?>&resolved=1<?= $urlsuffix ?>"><span data-feather="check-square"></span> Résolues <span class="badge badge-info"><?= $resolvecount[1] ?></span></a>
  </li>
</ul>

<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th width="100px">Token</th>
        <th width="150px">Photo</th>
        <th width="100px">Informations</th>
        <th width="100px">Observations</th>
        <th width="300px"> </th>
      </tr>
    </thead>
    <tbody>
<?php
/* Pagination */
if (isset($_GET['pagenb']) && is_numeric($_GET['pagenb'])) {
    $pagenb = $_GET['pagenb'];
} else {
    $pagenb = 1;
}

$maxobsperpage = 10;
$offset        = ($pagenb - 1) * $maxobsperpage;

$countpage_query = mysqli_query($db, "SELECT count(*) FROM obs_resolutions WHERE resolution_status='" . $resolved . "'");
$nbrows          = mysqli_fetch_array($countpage_query)[0];
$nbpages         = ceil($nbrows / $maxobsperpage);

$query_resolution = mysqli_query($db, "SELECT * FROM obs_resolutions WHERE resolution_status='" . $resolved . "' ORDER BY resolution_time DESC  LIMIT " . $offset . "," . $maxobsperpage);

while ($result_resolution = mysqli_fetch_array($query_resolution)) {
    $date  = date('d/m/Y', $result_resolution['resolution_time']);
    $heure = date('H:i', $result_resolution['resolution_time']);
?>
     <tr>
        <td><?= $result_resolution['resolution_token'] ?></td>
    <td>
<?php
    if ($result_resolution['resolution_withphoto'] == 1) {
?>
   <a href="<?=$config['HTTP_PROTOCOL'] ?>://<?=$config['URLBASE'] ?>/get_photo.php?type=resolution&token=<?= $result_resolution['resolution_token'] ?>" target="_blank"><img width="200px" src="<?=$config['HTTP_PROTOCOL'] ?>://<?=$config['URLBASE'] ?>/get_photo.php?type=resolution&token=<?= $result_resolution['resolution_token'] ?>" /></a>
<?php
    } else {
?>
   Pas de photo
<?php
    }
?>
       </td>
    <td>
          <form action="?page=resolutions&resolved=<?= $resolved ?><?= $urlsuffix ?>" method="POST">
            <label for="obs_comment"><strong>Commentaire</strong></label>
        <input type="text" class="form-control-plaintext" name="resolution_comment" value="<?= $result_resolution['resolution_comment'] ?>" />
            <?php
    if ($result_resolution['resolution_status'] == 4 || $result_resolution['resolution_status'] == 1) {
?>
           <label for="post_date"><strong>Date/heure</strong></label>
        <input type="text" class="form-control-plaintext" name="post_date" value="<?= $date ?>" required />
        <input type="text" class="form-control-plaintext" name="post_heure" value="<?= $heure ?>" required />
 
<?php
    }
    if (in_array($_SESSION['role'], $actions_acl['edit']['access'])) {
?>
           <input type="hidden" name="resolution_id" value="<?= $result_resolution['resolution_id'] ?>" />
            <button class="btn btn-primary" type="submit">Mettre à jour</button>
<?php
    }
?>
         </form>
  </td>
  <td>
<?php
    $observations_query = mysqli_query($db, "SELECT obs_id,obs_token 
                                                                FROM obs_resolutions_tokens 
                                                                LEFT JOIN obs_list 
                                                                ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid 
                                                                WHERE restok_resolutionid='" . $result_resolution['resolution_id'] . "'");
    while ($observations_result = mysqli_fetch_array($observations_query)) {
?>
   <a href="index.php?page=observations&filtertoken=<?= $observations_result['obs_token'] ?>&filtertype=uniq">
<?php
        if (in_array($observations_result['obs_id'], $duplicateids)) {
?>
     <strong><font color="red"><?= $observations_result['obs_token'] ?></font></strong>
<?php
        } else {
?>
     <?= $observations_result['obs_token'] ?>
<?php
        }
?>

    </a> 
<?php
        if (in_array($_SESSION['role'], $actions_acl['manageobs']['access'])) {
?>
   <a href="?page=<?= $page_name ?>&action=deleteobs&resolved=<?= $resolved ?>&resolutionid=<?= $result_resolution['resolution_id'] ?>&obsid=<?= $observations_result['obs_id'] ?>">
      <span data-feather="trash-2"></span>
<?php
        }
?>
</a>
<br />
<?php
    }
    if (in_array($_SESSION['role'], $actions_acl['manageobs']['access'])) {
?>
   <form action="?page=resolutions&resolved=<?= $resolved ?><?= $urlsuffix ?>" method="POST">
          <input type="hidden" name="obsadd" value="1" />
      <input type="hidden" name="resolutionid" value="<?= $result_resolution['resolution_id'] ?>" />
          <input type="text" class="form-control-plaintext" name="obstoken" value=""/>
          <button class="btn btn-primary" type="submit">Ajouter obs</button>
        </form>
<?php
    }
?>
 </td>
  <td>
      <?php
    if (in_array($_SESSION['role'], $actions_acl['delete']['access'])) {
?>
           <a href="?page=<?= $page_name ?>&action=delete&resolved=<?= $resolved ?>&resolutionid=<?= $result_resolution['resolution_id'] ?><?= $urlsuffix ?>" onclick="return confirm('Merci de valider la suppression')"><span data-feather="delete"></span> Supprimer</a><br />
<?php
    }
    if (in_array($_SESSION['role'], $actions_acl['resolve']['access'])) {
        $currentstatus = $result_resolution['resolution_status'];
        if (in_array($_SESSION['role'], $status_list[1]['roles']) && in_array(1, $status_list[$currentstatus]['nextstatus'])) {
?>
         <a href="?page=<?= $page_name ?>&action=resolve&new_status=1&resolved=<?= $resolved ?>&resolutionid=<?= $result_resolution['resolution_id'] ?><?= $urlsuffix ?>"><span data-feather="check-square"></span> Résolution validée</a><br />
            <?php
        }
        if (in_array($_SESSION['role'], $status_list[2]['roles']) && in_array(2, $status_list[$currentstatus]['nextstatus'])) {
?>
       <a href="?page=<?= $page_name ?>&action=resolve&new_status=2&resolved=<?= $resolved ?>&resolutionid=<?= $result_resolution['resolution_id'] ?><?= $urlsuffix ?>"><span data-feather="eye"></span> Problème pris en compte</a><br />
            <?php
        }
        if (in_array($_SESSION['role'], $status_list[3]['roles']) && in_array(3, $status_list[$currentstatus]['nextstatus'])) {
?>
           <a href="?page=<?= $page_name ?>&action=resolve&new_status=3&resolved=<?= $resolved ?>&resolutionid=<?= $result_resolution['resolution_id'] ?><?= $urlsuffix ?>"><span data-feather="clock"></span> En cours de résolution</a><br />
            <?php
        }
        if (in_array($_SESSION['role'], $status_list[4]['roles']) && in_array(4, $status_list[$currentstatus]['nextstatus'])) {
?>
           <a href="?page=<?= $page_name ?>&action=resolve&new_status=4&resolved=<?= $resolved ?>&resolutionid=<?= $result_resolution['resolution_id'] ?><?= $urlsuffix ?>"><span data-feather="check-square"></span> Résolution à valider</a>
            <?php
        }
    }
?>

  </td>
      </tr>
      </form>
<?php
}
?>
   </tbody>
  </table>
</div>
<p><strong><?= $nbrows ?></strong> resolutions</p>

<nav aria-label="...">
  <ul class="pagination">

<?php
if ($nbpages > 1) {
    if ($pagenb == 1) {
        $previous_disabled = "disabled";
    } else {
        $previous_disabled = "";
    }
?>
   <li class="page-item <?= $previous_disabled ?>">
      <a class="page-link" href="?page=<?= $page_name ?>&resolved=<?= $resolved ?>&pagenb=<?= $pagenb - 1 ?><?= $urlsuffix ?>" tabindex="-1">Previous</a>
    </li>

<?php
    for ($i = 1; $i <= $nbpages; $i++) {
        if ($pagenb == $i) {
            $active = "active";
        } else {
            $active = "";
        }
?>
  <li class="page-item <?= $active ?>"><a class="page-link" href="?page=<?= $page_name ?>&resolved=<?= $resolved ?>&pagenb=<?= $i ?><?= $urlsuffix ?>"><?= $i ?></a></li>
<?php
    }
    if ($pagenb == $nbpages) {
        $next_disabled = "disabled";
    } else {
        $next_disabled = "";
    }
?>
   <li class="page-item <?= $next_disabled ?>">
      <a class="page-link" href="?page=<?= $page_name ?>&resolved=<?= $resolved ?>&pagenb=<?= $pagenb + 1 ?><?= $urlsuffix ?>">Next</a>
    </li>
  </ul>
</nav>
<?php
}
?>
<br />

