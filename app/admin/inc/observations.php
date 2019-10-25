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

/* Observations list */
if (isset($_GET['action']) && isset($_GET['obsid']) && is_numeric($_GET['obsid']) && !isset($_POST['obs_id'])) {
  $obsid = mysqli_real_escape_string($db,$_GET['obsid']);
  $token = mysqli_real_escape_string($db,$_GET['token']);

  // Delete button actions
  if ($_GET['action'] == 'delete' && in_array($_SESSION['role'],$actions_acl['delete']['access'])) {
    delete_token_cache($token);
    delete_map_cache($token);
    mysqli_query($db,"DELETE FROM obs_list WHERE obs_id = '".$obsid."'");
    echo '<div class="alert alert-success" role="alert">Observation <strong>'.$obsid.'</strong> supprimée</div>';

  }
  // Approve buttons actions
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
  // Clean cache actions
  elseif ($_GET['action'] == 'cleancache' && in_array($_SESSION['role'],$actions_acl['cleancache']['access'])) {
    delete_token_cache($token);
    delete_map_cache($token);
  }
  // Resolve buttons actions
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

// Observation update actions
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

// Tab filter process
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


/* Observations quality check */
if (in_array($_SESSION['role'],$actions_acl['edit']['access'])) {
  $obswithoutcity = array("pbaddress" => array(),"cityunknown" => array(),"readytoimport" => array());
  
  $city_query = mysqli_query($db,"SELECT * FROM obs_cities ORDER BY city_name");
  $citylist = array();
  $citylistname = array();
  while($city_result = mysqli_fetch_array($city_query)) {
    $cityid = $city_result['city_id'];
    $citylist[$cityid] = flatstring($city_result['city_name']);
    $citylistname[$cityid] = $city_result['city_name'];
  }
  
  $obswithoutcity_query = mysqli_query($db, "SELECT obs_token,obs_address_string,obs_cityname FROM obs_list WHERE obs_city=0 AND obs_complete=1");
  while ($obswithoutcity_result = mysqli_fetch_array($obswithoutcity_query)) {
    $token = $obswithoutcity_result['obs_token'];
    preg_match('/^([^,]*),([^,]*)$/',$obswithoutcity_result['obs_address_string'],$cityInadress);

    if (count($cityInadress) == 3 &&  empty($obswithoutcity_result['obs_cityname'])) {
      $cityname = trim($cityInadress[2]);
      $address = mysqli_real_escape_string($db,$cityInadress[1]);
      $cityid = array_search(flatstring($cityname), $citylist);
      $obswithoutcity['readytoimport'][] = $token;
      if (isset($_GET['importcityfromadress']) && $_GET['importcityfromadress'] == "1") {
         if ($cityid) {
           mysqli_query($db, "UPDATE obs_list SET obs_address_string='".$address."', obs_city='".$cityid."' WHERE obs_token='".$token."'"); 
         }
         else {
           mysqli_query($db, "UPDATE obs_list SET obs_address_string='".$address."', obs_cityname='".$cityname."' WHERE obs_token='".$token."'"); 
         }
      }
    }
    elseif(!empty($obswithoutcity_result['obs_cityname'])) {
      $cityname = $obswithoutcity_result['obs_cityname'];
      $cityid = array_search(flatstring($cityname), $citylist);
      if ($cityid) {
        $obswithoutcity['readytoimport'][] = $token;
	if (isset($_GET['importcityfromadress']) && $_GET['importcityfromadress'] == "1") {
	  mysqli_query($db, "UPDATE obs_list SET obs_cityname='', obs_city='".$cityid."' WHERE obs_token='".$token."'");
        }
      }
      else {
        $obswithoutcity['cityunknown'][] = $token;
      }
        
    }
    else {
      $obswithoutcity['pbaddress'][] = $token;
    }
  }
  if(count($obswithoutcity['pbaddress']) > 0 || count($obswithoutcity['cityunknown']) > 0 || count($obswithoutcity['readytoimport'])) {
  ?>
  <div class="alert alert-warning" role="alert">
  <strong>Observations sans villes configurées : </strong><br />
  <strong><?=count($obswithoutcity['pbaddress']) ?></strong> adresses qui ne sont pas au format "Rue, Ville" impossible à importer <a href="?page=observations&filterpbaddress=1">Afficher</a><br />
  <strong><?=count($obswithoutcity['cityunknown']) ?></strong> villes non référencées <a href="?page=observations&filtercityunknown=1">Afficher</a><br />
  <strong><?=count($obswithoutcity['readytoimport']) ?></strong> importations possibles ! <a href="?page=observations&importcityfromadress=1">Importer</a>
  </div>
  <?php
  }
}

/* Find part */
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
$searchcity = "";
$querysearch = "";
$searchcategorie = 0;

if (isset($_GET['filtertoken']) && !empty($_GET['filtertoken']) && $_GET['filtertype'] == "similar") {
  $searchtoken = mysqli_real_escape_string($db,$_GET['filtertoken']);
  $filter = array('distance' => 300,
                'fdistance' => 1,
                'fcategorie' => 1,
                'faddress' => 1);
  $similar = sameas($db,$searchtoken , $filter);
  $querysearch .= " AND obs_token IN ('".implode("','",$similar)."')";
  $urlsuffix .= "&filtertype=similar&filtertoken=".$searchtoken;
}
elseif (isset($_GET['filtertoken']) && !empty($_GET['filtertoken']) && $_GET['filtertype'] == "uniq") {
  $searchtoken = mysqli_real_escape_string($db,$_GET['filtertoken']);
  $querysearch .= " AND obs_token = '".$searchtoken."'";
  $urlsuffix .= "&filtertype=uniq&filtertoken=".$searchtoken;
}

if (isset($_GET['filteraddress']) && !empty($_GET['filteraddress'])) {
  $searchaddress = mysqli_real_escape_string($db,$_GET['filteraddress']);
  $querysearch .= " AND LOWER(obs_address_string) LIKE LOWER('%".$searchaddress."%')";
  $urlsuffix .= "&filteraddress=".$searchaddress;
}

if (isset($_GET['searchcity']) && is_numeric($_GET['searchcity'])) {
  if($_GET['searchcity'] != 0) {
    $searchcity = $_GET['searchcity'];
    $querysearch .= " AND obs_city='".$searchcity."'";
    $urlsuffix .= "&searchcity=".$searchcity;
  }
}

if (isset($_GET['filterpbaddress']) && $_GET['filterpbaddress'] == "1") {
  $querysearch .= " AND obs_token IN ('".implode("','",$obswithoutcity['pbaddress'])."')";
  $urlsuffix .= "&filterpbaddress=1";
}
elseif (isset($_GET['filtercityunknown']) && $_GET['filtercityunknown'] == "1") {
  $querysearch .= " AND obs_token IN ('".implode("','",$obswithoutcity['cityunknown'])."')";
  $urlsuffix .= "&filtercityunknown=1";
}

if (isset($_GET['searchcategory']) && $_GET['searchcategory'] != 0 && is_numeric($_GET['searchcategory'])) {
  $querysearch .= " AND obs_categorie='".$_GET['searchcategory']."'";
  $urlsuffix .= "&searchcategory=".$_GET['searchcategory'] ;
  $searchcategory = $_GET['searchcategory'];
}

/* Filter cities for the current role */
if (isset($_SESSION['role']) && $_SESSION['role'] == 'citystaff') {
    $role_query = mysqli_query($db, "SELECT role_city FROM obs_roles WHERE role_login = '".$_SESSION['login']."'");
    while ($role_result = mysqli_fetch_array($role_query)) {
        $role_cities = json_decode($role_result['role_city']);
        foreach ((array) $role_cities as $city) {
            $city_query = mysqli_query($db, "SELECT city_name FROM obs_cities WHERE city_id = $city");
            $role_citynames[] = mysqli_fetch_array($city_query)['city_name'];
        }
    }
}

/* Pagination */
if (isset($_GET['pagenb']) && is_numeric($_GET['pagenb'])) {
  $pagenb = $_GET['pagenb'];
}
else {
  $pagenb = 1;
}

$maxobsperpage = 100;
$offset = ($pagenb-1) * $maxobsperpage;

$countpage_query = mysqli_query($db,"SELECT count(*) FROM obs_list WHERE obs_approved='".$approved."' AND obs_complete=1 AND obs_status='".$resolved."' ".$querysearch);
$nbrows = mysqli_fetch_array($countpage_query)[0];
$nbpages = ceil($nbrows / $maxobsperpage);
$query_obs = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_approved='".$approved."' AND obs_complete=1 AND obs_status='".$resolved."' ".$querysearch." ORDER BY obs_time DESC LIMIT ".$offset .",".$maxobsperpage);

$approvedcount = array(0=>0,1=>0,2=>0);
$resolvecount = array(0=>0,1=>0,2=>0,3=>0,4=>0);
$query_count_tabs = mysqli_query($db, "SELECT obs_approved,obs_status FROM obs_list WHERE obs_complete=1".$querysearch);
while ($result_count_tabs = mysqli_fetch_array($query_count_tabs)) {
  $approvedcount[$result_count_tabs['obs_approved']]++;
  if($result_count_tabs['obs_approved'] == 1) {
    $resolvecount[$result_count_tabs['obs_status']]++;
  }
}

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
  <fieldset class="form-group">
    <div class="row">
      <legend class="col-form-label col-sm-2 pt-0">Type</legend>
      <div class="col-sm-10">
        <div class="form-check">
          <input class="form-check-input" type="radio" name="filtertype" id="gridRadios1" value="uniq" <?=$filterTypeUniqueChecked ?>>
          <label class="form-check-label" for="gridRadios1">Unique</label>
        </div>
         <div class="form-check">
          <input class="form-check-input" type="radio" name="filtertype" id="gridRadios2" value="similar" <?=$filterTypeSimilarChecked ?>>
          <label class="form-check-label" for="gridRadios2">Similaires</label>
        </div>
      </div>
    </div>
  </fieldset>
  <div class="form-group row">
    <label for="searchAddress" class="col-sm-2 col-form-label">Rue</label>
    <div class="col-sm-10">
      <input type="text" class="form-control" name="filteraddress" id="searchAddress" value="<?=$searchaddress ?>">
    </div>
  </div>
  <div class="form-group row">
    <label for="searchcity" class="col-sm-2 col-form-label">Ville</label>
    <div class="col-sm-10">
      <select class="form-control" name="searchcity" id="searchcity">
      <?php
      $citylistnametmp = $citylistname;
      $citylistnametmp[0] = "---";

      foreach ($citylistnametmp as $selectcityid => $selectcityname) {
        if ($searchcity == $selectcityid) {
          echo '<option value="'.$selectcityid.'" selected>'.$selectcityname.'</option>';
        }
        else {
          echo '<option value="'.$selectcityid.'">'.$selectcityname.'</option>';
              }
            }
     ?>
      </select>
    </div>
  </div>
  <div class="form-group row">
    <label for="searchcategory" class="col-sm-2 col-form-label">Categorie</label>
    <div class="col-sm-10">
      <select class="form-control" name="searchcategory" id="searchcategory">
      <?php
      $categorielist = getCategoriesList();
      $categorielist[0] = array("catid" => 0, "catname" => "---");;
      foreach ($categorielist as $categorie) {
        if ($searchcategory == $categorie['catid']) {
          echo '<option value="'.$categorie['catid'].'" selected>'.$categorie['catname'].'</option>';
        }
        else {
          echo '<option value="'.$categorie['catid'].'">'.$categorie['catname'].'</option>';
              }
            }
     ?>
      </select>
    </div>
  </div>

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
    <a class="nav-link <?=$tabapproved[1] ?>" href="?page=<?=$page_name ?>&approved=1<?=$urlsuffix ?>"><span data-feather="check"></span> Approuvées <span class="badge badge-info"><?=$approvedcount[1] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[0] ?>" href="?page=<?=$page_name ?>&approved=0<?=$urlsuffix ?>"><span data-feather="clock"></span> A qualifier <span class="badge badge-info"><?=$approvedcount[0] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[2] ?>" href="?page=<?=$page_name ?>&approved=2<?=$urlsuffix ?>"><span data-feather="x"></span> Désapprouvées <span class="badge badge-info"><?=$approvedcount[2] ?></span></a>
  </li>
</ul>
<?php
}

// Si l'onglet actif est "Observations approuvées"
if ($tabapproved[1] == "active" && in_array($_SESSION['role'],$actions_acl['resolve']['access'])) { ?>
<br />
<ul class="nav nav-tabs">
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[0] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=0<?=$urlsuffix ?>">Non prises en compte <span class="badge badge-info"><?=$resolvecount[0] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[2] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=2<?=$urlsuffix ?>"><span data-feather="eye"></span> Prises en compte <span class="badge badge-info"><?=$resolvecount[2] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[3] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=3<?=$urlsuffix ?>"><span data-feather="clock"></span> En cours de résolution <span class="badge badge-info"><?=$resolvecount[3] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[4] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=4<?=$urlsuffix ?>"><span data-feather="user-check"></span> Indiquées résolues <span class="badge badge-info"><?=$resolvecount[4] ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabresolved[1] ?>" href="?page=<?=$page_name ?>&approved=1&resolved=1<?=$urlsuffix ?>"><span data-feather="check-square"></span> Résolues <span class="badge badge-info"><?=$resolvecount[1] ?></span></a>
  </li>
</ul>
<?php } ?>
<br />
<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th width="100px">Token</th>
        <th width="150px">Photo</th>
        <th>Précisions</th>
        <th width="300px">Localisation</th>
        <th width="100px">Date / Heure</th>
        <th width="300px"> </th>
      </tr>
    </thead>
    <tbody>
<?php
while ($result_obs = mysqli_fetch_array($query_obs)) {
$date = date('d/m/Y',$result_obs['obs_time']);
$heure = date('H:i',$result_obs['obs_time']);
$highlight_city = "";
if ( (isset($role_cities) && in_array($result_obs['obs_city'], $role_cities) )
    || ( isset($role_citynames) && in_array($result_obs['obs_cityname'], $role_citynames) ) ) {
    $highlight_city = "table-info";
}
?>
      <form action="?page=observations<?=$urlsuffix ?>" method="POST">
      <tr class="<?=$highlight_city ?>">
        <td><?=$result_obs['obs_token'] ?></td>
        <td>
          <a href="/generate_panel.php?s=800&token=<?=$result_obs['obs_token'] ?>" target="_blank"><img src="/generate_panel.php?s=200&token=<?=$result_obs['obs_token'] ?>" /></a>
        </td>
        <td>
          <label for="obs_comment"><strong>Commentaire</strong></label>
          <input type="text" class="form-control-plaintext" name="obs_comment" value="<?=$result_obs['obs_comment'] ?>" />
          <label for="obs_categorie"><strong>Catégorie</strong></label>
          <select class="form-control" name="obs_categorie">
<?php
               foreach ($categorielist as $categorie) {
                 if ($result_obs['obs_categorie'] == $categorie['catid']) {
                   echo '<option value="'.$categorie['catid'].'" selected>'.$categorie['catname'].'</option>';
                 }
                 else {
                   echo '<option value="'.$categorie['catid'].'">'.$categorie['catname'].'</option>';
                 }
               }
     ?>
              </select>

        </td>
	<td>
	  <div class="form-group">
          <label for="obs_address_string"><strong>Rue</strong></label>
	  <input type="text" class="form-control-plaintext" name="obs_address_string" value="<?=$result_obs['obs_address_string'] ?>" required />
           <?php

if (!empty($result_obs['obs_cityname'])) { ?>
            <label for="obs_cityname"><strong>Ville</strong></label>
            <input type="text" class="form-control-plaintext" name="obs_cityname" value="<?=$result_obs['obs_cityname'] ?>" required />
<?php
}
else { ?>
          <label for="obs_city"><strong>Ville</strong></label>
          <select class="form-control" name="obs_city" id="obs_city"><br />
<?php 
#$citylistnametmp = $citylistname;
  foreach ($citylistname as $selectcityid => $selectcityname) {
    if ($result_obs['obs_city'] == $selectcityid) {
      echo '<option value="'.$selectcityid.'" selected>'.$selectcityname.'</option>';
    }
    else {
      echo '<option value="'.$selectcityid.'">'.$selectcityname.'</option>';
    }
  }
  if ($result_obs['obs_city'] == 0) {
    echo '<option value="0" selected>---</option>';
  }

}

?>

	  </select><br />
          <a href="https://www.openstreetmap.org/?mlat=<?=$result_obs['obs_coordinates_lat'] ?>&mlon=<?=$result_obs['obs_coordinates_lon'] ?>#map=16/<?=$result_obs['obs_coordinates_lat'] ?>/<?=$result_obs['obs_coordinates_lon'] ?>&layers=N">Afficher sur une carte</a>
          </div>
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
            <a href="?page=<?=$page_name ?>&action=delete&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>" onclick="return confirm('Merci de valider la suppression')"><span data-feather="delete"></span> Supprimer</a><br />
          <?php }
          if (in_array($_SESSION['role'],$actions_acl['cleancache']['access'])) { ?>
            <a href="?page=<?=$page_name ?>&action=cleancache&token=<?=$result_obs['obs_token'] ?>&obsid=<?=$result_obs['obs_id'] ?><?=$urlsuffix ?>"><span data-feather="hard-drive"></span> Effacer cache</a><br />
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
