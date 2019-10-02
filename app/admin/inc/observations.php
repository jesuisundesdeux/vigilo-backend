<?php
if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'],$menu[$page_name]['access']))) {
  exit('Not allowed');
}

$actions_acl = array("delete" => array("access" => array('admin')),
                     "resolve" => array("access" => array('admin','citystaff')),
                     "approve" => array("access" => array('admin')),
                     "cleancache" => array("access" => array('admin')),
                     "edit" => array("access" => array('admin')));

$urlsuffix="";

if (isset($_GET['action']) && isset($_GET['obsid']) && is_numeric($_GET['obsid']) && !isset($_POST['obs_id'])) {
  $obsid = mysqli_real_escape_string($db,$_GET['obsid']);
  $token = mysqli_real_escape_string($db,$_GET['token']);

  if ($_GET['action'] == 'delete' && in_array($_SESSION['role'],$actions_acl['delete']['access'])) {

    mysqli_query($db,"DELETE FROM obs_list WHERE obs_id = '".$obsid."'");
    echo '<div class="alert alert-success" role="alert">Observation <strong>'.$obsid.'</strong> supprimée</div>';

  }
  elseif ($_GET['action'] == 'approve' && in_array($_SESSION['role'],$actions_acl['approve']['access'])) {
    if(isset($_GET['approveto']) && is_numeric($_GET['approveto'])) {
      $approveto = $_GET['approveto'];
    }
    else {
      $approveto = 1;
    }
    delete_token_cache($token);
    mysqli_query($db, "UPDATE obs_list SET obs_approved='".$approveto."' WHERE obs_id='".$obsid."'");
    echo '<div class="alert alert-success" role="alert">Observation <strong>'.$obsid.'</strong> approuvée/desapprouvée</div>';
  }
  elseif ($_GET['action'] == 'cleancache' && in_array($_SESSION['role'],$actions_acl['cleancache']['access'])) {
    delete_token_cache($token);
    delete_map_cache($token);
  }
  elseif ($_GET['action'] == 'resolve' && isset($_GET['new_status']) && in_array($_SESSION['role'],$actions_acl['resolve']['access'])) {
      $new_status = $_GET['new_status'];
      if (is_numeric($new_status) && in_array($_SESSION['role'],$status_list[$new_status]['roles'])) {
          // We collect the role_id
          $role_query = mysqli_query($db,"SELECT role_id FROM obs_roles WHERE role_login = '".$_SESSION['login']."'");
          if ($role_result = mysqli_fetch_array($role_query)) {
            $role_id = $role_result['role_id'];
          }
          else {
            $role_id = 0;
          }
          $comment = '';
          $time = time();
          mysqli_query($db, "INSERT INTO obs_status_update (status_update_obsid,status_update_status,status_update_comment,status_update_time,status_update_roleid)
                            VALUES ('".$obsid."','".$new_status."','".$comment."','".$time."','".$role_id."')");
          mysqli_query($db, "UPDATE obs_list SET obs_status = '".$new_status."' WHERE obs_id = $obsid ");

          if($new_status == 1 || $new_status == 0) {
            delete_token_cache($token);
          }
      }
      else {
        exit('Not allowed');
      }
  }
  else {
    exit('Not allowed');
  }

}

if (isset($_POST['obs_id']) && in_array($_SESSION['role'],$actions_acl['edit']['access'])) {
  $update = "";
  $obstime = strptime($_POST['post_date'].' '.$_POST['post_heure'],'%d/%m/%Y %H:%M');
  if(!$obstime || strlen($_POST['post_date']) != 10 || strlen($_POST['post_heure']) != 5) {
    echo '<div class="alert alert-danger" role="alert">Format de date incorrect</div>';
  }
  else {
    $obstime = mktime($obstime['tm_hour'],$obstime['tm_min'],0,$obstime['tm_mon']+1,$obstime['tm_mday'],$obstime['tm_year']+1900);

    $update = "obs_time='".$obstime."',";
    foreach ($_POST as $key => $value) {
      if(preg_match('/obs_(?:.*)$/',$key)) {
        $key = mysqli_real_escape_string($db,$key);
        $value = mysqli_real_escape_string($db,$value);
        $update .= $key . "='".$value."',";
      }
    }
    $update = rtrim($update,',');
    $obsid = mysqli_real_escape_string($db,$_POST['obs_id']);
    mysqli_query($db,"UPDATE obs_list SET ". $update . " WHERE obs_id='".$obsid."'");

    echo '<div class="alert alert-success" role="alert">Observation <strong>'.$obsid.'</strong> mise à jour</div>';
  }
}

if (isset($_GET['approved']) && is_numeric($_GET['approved'])) {
  $approved = $_GET['approved'];
}
else {
  $approved = 1;
}
if (isset($_GET['resolved']) && is_numeric($_GET['resolved'])) {
    $resolved = mysqli_real_escape_string($db,$_GET['resolved']);
}
else {
    $resolved = 0;
}

$tabapproved[0] = "";
$tabapproved[1] = "";
$tabapproved[2] = "";
$tabapproved[$approved] = "active";

$tabresolved[0] = "";
$tabresolved[1] = "";
$tabresolved[2] = "";
$tabresolved[3] = "";
$tabresolved[4] = "";
$tabresolved[$resolved] = "active";


if (isset($_GET['pagenb']) && is_numeric($_GET['pagenb'])) {
  $pagenb = $_GET['pagenb'];
}
else {
  $pagenb = 1;
}

$maxobsperpage = 100;
$offset = ($pagenb-1) * $maxobsperpage;
$tokenlist = '';
$addresslist = '';

// To check the good radio button
$filterTypeUniqueChecked = "checked";
$filterTypeSimilarChecked = "";
if (isset($_GET['filtertype'])) {
    if ($_GET['filtertype'] == "similar") {
        $filterTypeUniqueChecked = "";
        $filterTypeSimilarChecked = "checked";
    }
}

$searchtoken = "";
$searchaddress = "";
if (isset($_GET['filtertoken']) && !empty($_GET['filtertoken']) && $_GET['filtertype'] == "similar") {
  $searchtoken = mysqli_real_escape_string($db,$_GET['filtertoken']);
  $filter = array('distance' => 300,
                'fdistance' => 1,
                'fcategorie' => 1,
                'faddress' => 1);
  $similar = sameas($db,$searchtoken , $filter);
  $tokenlist = "AND obs_token IN ('".implode("','",$similar)."')";
  $urlsuffix .= "&filtertype=similar&filtertoken=".$searchtoken;
}
elseif(isset($_GET['filtertoken']) && !empty($_GET['filtertoken']) && $_GET['filtertype'] == "uniq") {
  $searchtoken = mysqli_real_escape_string($db,$_GET['filtertoken']);
  $tokenlist = "AND obs_token = '".$searchtoken."'";
  $urlsuffix .= "&filtertype=uniq&filtertoken=".$searchtoken;
}

if(isset($_GET['filteraddress']) && !empty($_GET['filteraddress'])) {
  $searchaddress = mysqli_real_escape_string($db,$_GET['filteraddress']);
  $addresslist = "AND LOWER(obs_address_string) LIKE LOWER('%".$searchaddress."%')";
  $urlsuffix .= "&filtertype=uniq&filteraddress=".$searchaddress;
}

$countpage_query = mysqli_query($db,"SELECT count(*) FROM obs_list WHERE obs_approved='".$approved."' AND obs_complete=1 AND obs_status='".$resolved."' ".$tokenlist." ".$addresslist);
$nbrows = mysqli_fetch_array($countpage_query)[0];
$nbpages = ceil($nbrows / $maxobsperpage);
$query_obs = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_approved='".$approved."' AND obs_complete=1 AND obs_status='".$resolved."' ".$tokenlist." ".$addresslist." ORDER BY obs_time DESC LIMIT ".$offset .",".$maxobsperpage);

?>
<h3>Recherche</h3>
<form method="GET" action="">
  <input type="hidden" name="page" value="<?=$page_name ?>" />
  <div class="form-group row">
    <label for="searchToken" class="col-sm-2 col-form-label">Token</label>
    <div class="col-sm-10">
      <input type="text" class="form-control" name="filtertoken" id="searchToken" value="<?=$searchtoken ?>">
    </div>
  </div>
  <div class="form-group row">
    <label for="searchAddress" class="col-sm-2 col-form-label">Ville ou adresse</label>
    <div class="col-sm-10">
      <input type="text" class="form-control" name="filteraddress" id="searchAddress" value="<?=$searchaddress ?>">
    </div>
  </div>
  <fieldset class="form-group">
    <div class="row">
      <legend class="col-form-label col-sm-2 pt-0">Type</legend>
      <div class="col-sm-10">
        <div class="form-check">
          <input class="form-check-input" type="radio" name="filtertype" id="gridRadios1" value="uniq" <?=$filterTypeUniqueChecked ?>>
          <label class="form-check-label" for="gridRadios1">
            Unique
          </label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="filtertype" id="gridRadios2" value="similar" <?=$filterTypeSimilarChecked ?>>
          <label class="form-check-label" for="gridRadios2">
            Similaires
          </label>
        </div>
      </div>
    </div>
  </fieldset>
  <div class="form-group row">
    <div class="col-sm-10">
      <button type="submit" class="btn btn-primary">Recherche</button>
    </div>
  </div>
</form>
<h2>Liste</h2>

<?php
if(in_array($_SESSION['role'],$actions_acl['approve']['access'])) {
?>
<ul class="nav nav-tabs">
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[1] ?>" href="?page=<?=$page_name ?>&approved=1<?=$urlsuffix ?>"><span data-feather="check"></span> Approuvées</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[0] ?>" href="?page=<?=$page_name ?>&approved=0<?=$urlsuffix ?>"><span data-feather="clock"></span> A qualifier</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[2] ?>" href="?page=<?=$page_name ?>&approved=2<?=$urlsuffix ?>"><span data-feather="x"></span> Désapprouvées</a>
  </li>
</ul>
<?php
}

// Si l'onglet actif est "Observations approuvées"
if ($tabapproved[1] == "active" && in_array($_SESSION['role'],$actions_acl['resolve']['access'])) { ?>
<br />
<ul class="nav nav-tabs">
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[0] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=0<?=$urlsuffix ?>">Non prises en compte</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[2] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=2<?=$urlsuffix ?>"><span data-feather="eye"></span> Prises en compte</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[3] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=3<?=$urlsuffix ?>"><span data-feather="clock"></span> En cours de résolution</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[4] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=4<?=$urlsuffix ?>"><span data-feather="user-check"></span> Indiquées résolues</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[1] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=1<?=$urlsuffix ?>"><span data-feather="check-square"></span> Résolues</a>
  </li>
</ul>
<?php } ?>
<br />
<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th>Token</th>
        <th>Photo</th>
        <th>Commentaire</th>
        <th>Adresse</th>
        <th>Date / Heure</th>
        <th> </th>
        <th> </th>
      </tr>
    </thead>
    <tbody>
<?php
while ($result_obs = mysqli_fetch_array($query_obs)) {
$date = date('d/m/Y',$result_obs['obs_time']);
$heure = date('H:i',$result_obs['obs_time']);
?>
      <form action="?page=observations<?=$urlsuffix ?>" method="POST">
      <tr>
        <td><?=$result_obs['obs_token'] ?></td>
        <td>
          <img src="/generate_panel.php?s=200&token=<?=$result_obs['obs_token'] ?>" />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="obs_comment" value="<?=$result_obs['obs_comment'] ?>" />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="obs_address_string" value="<?=$result_obs['obs_address_string'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="post_date" value="<?=$date ?>" required />
          <input type="text" class="form-control-plaintext" name="post_heure" value="<?=$heure ?>" required />
        </td>
        <td>
            <?php // Droits réservés aux admins : approuver/désapprouver/résoudre/supprimer une observation
          if (in_array($_SESSION['role'],$actions_acl['edit']['access'])) { ?>
            <input type="hidden" name="obs_id" value="<?=$result_obs['obs_id'] ?>" />
            <button class="btn btn-primary" type="submit">Valider édition</button><br /><?php } ?>
          <?php  if (in_array($_SESSION['role'],$actions_acl['approve']['access'])) { ?>
            <a href="?page=<?=$page_name ?>&action=approve&approveto=1&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>"><span data-feather="check"></span> Approuver</a><br />
            <a href="?page=<?=$page_name ?>&action=approve&approveto=2&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>"><span data-feather="x"></span> Désapprouver</a><br />
          <?php }
          if (in_array($_SESSION['role'],$actions_acl['delete']['access'])) { ?>
            <a href="?page=<?=$page_name ?>&action=delete&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>" onclick="return confirm('Merci de valider la suppression')"><span data-feather="delete"></span> Supprimer</a><br />
          <?php }
          if (in_array($_SESSION['role'],$actions_acl['cleancache']['access'])) { ?>
            <a href="?page=<?=$page_name ?>&action=cleancache&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>">Effacer cache</a><br />
          <?php }
          if (in_array($_SESSION['role'],$actions_acl['resolve']['access'])) {
            $currentstatus = $result_obs['obs_status'];
            if (in_array($_SESSION['role'],$status_list[0]['roles']) && in_array(0,$status_list[$currentstatus]['nextstatus'])) { ?>
              <a href="?page=<?=$page_name ?>&action=resolve&new_status=0&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>">Observation non résolue</a><br />
            <?php }
            if (in_array($_SESSION['role'],$status_list[1]['roles']) && in_array(1,$status_list[$currentstatus]['nextstatus'])) { ?>
              <a href="?page=<?=$page_name ?>&action=resolve&new_status=1&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>"><span data-feather="check-square"></span> Observation résolue</a><br />
            <?php }
            if (in_array($_SESSION['role'],$status_list[2]['roles']) && in_array(2,$status_list[$currentstatus]['nextstatus'])) { ?>
            <a href="?page=<?=$page_name ?>&action=resolve&new_status=2&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>"><span data-feather="eye"></span> Prendre en compte l'observation</a><br />
            <?php }
            if (in_array($_SESSION['role'],$status_list[3]['roles']) && in_array(3,$status_list[$currentstatus]['nextstatus'])) {  ?>
            <a href="?page=<?=$page_name ?>&action=resolve&new_status=3&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>"><span data-feather="clock"></span> Observation en cours de résolution</a><br />
            <?php }
            if (in_array($_SESSION['role'],$status_list[4]['roles']) && in_array(4,$status_list[$currentstatus]['nextstatus'])) {  ?>
            <a href="?page=<?=$page_name ?>&action=resolve&new_status=4&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>"><span data-feather="check-square"></span> Observation résolue</a>
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

<p><strong><?=$nbrows ?></strong> observations</p>

<nav aria-label="...">
  <ul class="pagination">

<?php
if ($nbpages > 1) {
  if ($pagenb == 1) {
    $previous_disabled = "disabled";
  }
  else {
    $previous_disabled = "";
  }
?>
    <li class="page-item <?=$previous_disabled ?>">
      <a class="page-link" href="?page=<?=$page_name?>&approved=<?=$approved ?>&pagenb=<?=$pagenb-1 ?><?=$urlsuffix ?>" tabindex="-1">Previous</a>
    </li>

<?php
for ($i=1;$i<=$nbpages;$i++) {
  if ($pagenb == $i) {
    $active = "active";
  }
  else {
    $active = "";
  }
?>
   <li class="page-item <?=$active ?>"><a class="page-link" href="?page=<?=$page_name?>&approved=<?=$approved ?>&pagenb=<?=$i ?><?=$urlsuffix ?>"><?=$i ?></a></li>
<?php
}
if($pagenb == $nbpages) {
  $next_disabled = "disabled";
}
else {
  $next_disabled = "";
}
?>
    <li class="page-item <?=$next_disabled ?>">
      <a class="page-link" href="?page=<?=$page_name?>&approved=<?=$approved ?>&pagenb=<?=$pagenb+1 ?><?=$urlsuffix ?>">Next</a>
    </li>
  </ul>
</nav>
<?php
}
?>
<br />
