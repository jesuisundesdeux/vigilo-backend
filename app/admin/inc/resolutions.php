<?php
if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'],$menu[$page_name]['access']))) {
  exit('Not allowed');
}

/* Defines acls for this page used by roles */
$actions_acl = array("delete" => array("access" => array('admin')),
                     "resolve" => array("access" => array('admin','citystaff')),
                     "approve" => array("access" => array('admin')),
                     "cleancache" => array("access" => array('admin')),
                     "edit" => array("access" => array('admin')));


$urlsuffix="";

/* Forms handling */
if (isset($_POST['obsadd'])) {
  $tokentoadd = mysqli_real_escape_string($db,$_POST['obstoken']);
  $resolutionid = mysqli_real_escape_string($db,$_POST['resolutionid']);
  if(isTokenExists($db,$tokentoadd)) {
    addObsToResolution($db,getObsIdByToken($db,$tokentoadd),$resolutionid);
  }
}

/* Actions links */
if (isset($_GET['action']) && isset($_GET['resolutionid']) && is_numeric($_GET['resolutionid']) && !isset($_POST['resolutionid'])) {
  $action = $_GET['action'];
  $resolutionid = mysqli_real_escape_string($db,$_GET['resolutionid']);

  if($action == "deleteobs" && is_numeric($_GET['resolutionid'])) {
    delObsToResolution($db,$_GET['obsid'],$_GET['resolutionid']); 
  }
  elseif ($action == 'delete' && in_array($_SESSION['role'],$actions_acl['delete']['access'])) {
    delResolution($db,$resolutionid);
    echo '<div class="alert alert-success" role="alert">Resolution <strong>'.$resolutionid.'</strong> supprimée</div>';

  }

  elseif ($action == 'resolve' && is_numeric($_GET['new_status']) && in_array($_SESSION['role'],$actions_acl['resolve']['access'])) {
    $new_status = $_GET['new_status'];
    if (in_array($_SESSION['role'],$status_list[$new_status]['roles'])) {
	    mysqli_query($db, "UPDATE obs_resolutions SET resolution_status = '".$new_status."' WHERE resolution_id = '".$resolutionid."'");
        /*if($new_status == 1 || $new_status == 0) {
          delete_token_cache($token);
      }*/
    }
    else {
      exit('Not allowed');
    }
  }
}
// Tab filter process
if (isset($_GET['resolved']) && is_numeric($_GET['resolved'])) {
  $resolved = mysqli_real_escape_string($db,$_GET['resolved']);
}
else {
  $resolved = 2;
}


$tabresolved[1] = "";
$tabresolved[2] = "";
$tabresolved[3] = "";
$tabresolved[4] = "";
$tabresolved[$resolved] = "active";

$resolvecount = array(0=>0,1=>0,2=>0,3=>0,4=>0);
$query_count_tabs = mysqli_query($db, "SELECT resolution_status FROM obs_resolutions");
while ($result_count_tabs = mysqli_fetch_array($query_count_tabs)) {
  $resolvecount[$result_count_tabs['resolution_status']]++;
}

?>
<h2>Liste</h2>

<ul class="nav nav-tabs">
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[2] ?>" href="?page=<?=$page_name ?>&resolved=2<?=$urlsuffix ?>"><span data-feather="eye"></span> Prises en compte <span class="badge badge-info"><?=$resolvecount[2] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[3] ?>" href="?page=<?=$page_name ?>&resolved=3<?=$urlsuffix ?>"><span data-feather="clock"></span> En cours de résolution <span class="badge badge-info"><?=$resolvecount[3] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[4] ?>" href="?page=<?=$page_name ?>&resolved=4<?=$urlsuffix ?>"><span data-feather="user-check"></span> Indiquées résolues <span class="badge badge-info"><?=$resolvecount[4] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[1] ?>" href="?page=<?=$page_name ?>&resolved=1<?=$urlsuffix ?>"><span data-feather="check-square"></span> Résolues <span class="badge badge-info"><?=$resolvecount[1] ?></span></a>
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
$query_resolution = mysqli_query($db,"SELECT * FROM obs_resolutions WHERE resolution_status='".$resolved."'");

while ($result_resolution = mysqli_fetch_array($query_resolution)) {
$date = date('d/m/Y',$result_resolution['resolution_time']);
$heure = date('H:i',$result_resolution['resolution_time']);
?>
      <tr>
        <td><?=$result_resolution['resolution_token'] ?></td>
	<td>
<?php 
if ($result_resolution['resolution_withphoto'] == 1) { ?>
	<a href="/get_photo.php?typoe=resolution&token=<?=$result_resolution['resolution_token'] ?>" target="_blank"><img width="200px" src="/get_photo.php?type=resolution&token=<?=$result_resolution['resolution_token'] ?>" /></a>
<?php
} 
else { ?>
	Pas de photo
<?php
} ?>
        </td>
	<td>
          <form action="?page=resolutions<?=$urlsuffix ?>" method="POST">
            <label for="obs_comment"><strong>Commentaire</strong></label>
            <input type="text" class="form-control-plaintext" name="obs_comment" value="<?=$result_resolution['resolution_comment'] ?>" />
            <label for="post_date"><strong>Date/heure</strong></label>
	    <input type="text" class="form-control-plaintext" name="post_date" value="<?=$date ?>" required />
	    <input type="text" class="form-control-plaintext" name="post_heure" value="<?=$heure ?>" required />
            <button class="btn btn-primary" type="submit">Mettre à jour</button>

          </form>
  </td>
  <td>
<?php
  $observations_query = mysqli_query($db,"SELECT obs_id,obs_token 
                                                                FROM obs_resolutions_tokens 
                                                                LEFT JOIN obs_list 
                                                                ON obs_list.obs_id = obs_resolutions_tokens.restok_observationid 
                                                                WHERE restok_resolutionid='".$result_resolution['resolution_id']."'");
while($observations_result = mysqli_fetch_array($observations_query)) {
?>
	<a href="index.php?page=observations&filtertoken=<?=$observations_result['obs_token'] ?>&filtertype=uniq">
          <?=$observations_result['obs_token'] ?>
        </a> 
	<a href="?page=<?=$page_name ?>&action=deleteobs&resolutionid=<?=$result_resolution['resolution_id'] ?>&obsid=<?=$observations_result['obs_id'] ?>">
          <span data-feather="trash-2"></span>
</a>
<br />
<?php
}
?>
	<form action="?page=resolutions<?=$urlsuffix ?>" method="POST">
          <input type="hidden" name="obsadd" value="1" />
	  <input type="hidden" name="resolutionid" value="<?=$result_resolution['resolution_id'] ?>" />
          <input type="text" class="form-control-plaintext" name="obstoken" value=""/>
          <button class="btn btn-primary" type="submit">Ajouter obs</button>
        </form>

  </td>
  <td>
	  <?php
           if (in_array($_SESSION['role'],$actions_acl['delete']['access'])) { ?>
            <a href="?page=<?=$page_name ?>&action=delete&resolutionid=<?=$result_resolution['resolution_id'] ?><?=$urlsuffix ?>" onclick="return confirm('Merci de valider la suppression')"><span data-feather="delete"></span> Supprimer</a><br />
<?php
	  }
          if (in_array($_SESSION['role'],$actions_acl['resolve']['access'])) {
            $currentstatus = $result_resolution['resolution_status'];
            if (in_array($_SESSION['role'],$status_list[1]['roles']) && in_array(1,$status_list[$currentstatus]['nextstatus'])) { ?>
              <a href="?page=<?=$page_name ?>&action=resolve&new_status=1&resolutionid=<?=$result_resolution['resolution_id'] ?><?=$urlsuffix ?>"><span data-feather="check-square"></span> Résolution validée</a><br />
            <?php }
            if (in_array($_SESSION['role'],$status_list[2]['roles']) && in_array(2,$status_list[$currentstatus]['nextstatus'])) { ?>
	    <a href="?page=<?=$page_name ?>&action=resolve&new_status=2&resolutionid=<?=$result_resolution['resolution_id'] ?><?=$urlsuffix ?>"><span data-feather="eye"></span> Problème pris en compte</a><br />
            <?php }
            if (in_array($_SESSION['role'],$status_list[3]['roles']) && in_array(3,$status_list[$currentstatus]['nextstatus'])) {  ?>
            <a href="?page=<?=$page_name ?>&action=resolve&new_status=3&resolutionid=<?=$result_resolution['resolution_id'] ?><?=$urlsuffix ?>"><span data-feather="clock"></span> En cours de résolution</a><br />
            <?php }
            if (in_array($_SESSION['role'],$status_list[4]['roles']) && in_array(4,$status_list[$currentstatus]['nextstatus'])) {  ?>
            <a href="?page=<?=$page_name ?>&action=resolve&new_status=4&resolutionid=<?=$result_resolution['resolution_id'] ?><?=$urlsuffix ?>"><span data-feather="check-square"></span> Résolution à valider</a>
            <?php
            }
          } ?>

  </td>
      </tr>
      </form>
<?php
}
?>
    </tbody>
  </table>
</div>

  <br />
