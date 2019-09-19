<?php
if (!isset($page_name)) {
  exit('Not allowed');
}

if (isset($_GET['action']) && isset($_GET['obsid']) && is_numeric($_GET['obsid']) && !isset($_POST['obs_id'])) {
  $obsid = mysqli_real_escape_string($db,$_GET['obsid']);
  if ($_GET['action'] == 'delete') {
    mysqli_query($db,"DELETE FROM obs_list WHERE obs_id = '".$obsid."'");
    echo '<div class="alert alert-success" role="alert">Observation <strong>'.$obsid.'</strong> supprimée</div>';
  }
  elseif ($_GET['action'] == 'approve') {
    if(isset($_GET['approveto']) && is_numeric($_GET['approveto'])) {
      $approveto = $_GET['approveto'];
    }
    else {
      $approveto = 1;
    }
    mysqli_query($db, "UPDATE obs_list SET obs_approved='".$approveto."' WHERE obs_id='".$obsid."'");
    echo '<div class="alert alert-success" role="alert">Observation <strong>'.$obsid.'</strong> approuvée/desapprouvée</div>';
  }

}

if (isset($_POST['obs_id'])) {
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

$tabapproved[0] = "";
$tabapproved[1] = "";
$tabapproved[2] = "";
$tabapproved[$approved] = "active";


if (isset($_GET['pagenb']) && is_numeric($_GET['pagenb'])) {
  $pagenb = $_GET['pagenb'];
}
else {
  $pagenb = 1;
}

$maxobsperpage = 100;
$offset = ($pagenb-1) * $maxobsperpage;

$countpage_query = mysqli_query($db,"SELECT count(*) FROM obs_list WHERE obs_approved='".$approved."' AND obs_complete=1");
$nbrows = mysqli_fetch_array($countpage_query)[0];
$nbpages = ceil($nbrows / $maxobsperpage);


$query_obs = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_approved='".$approved."' AND obs_complete=1 ORDER BY obs_time DESC LIMIT ".$offset .",".$maxobsperpage);

?>

<h2>Liste</h2>
<ul class="nav nav-tabs">
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[1] ?>" href="?page=<?=$page_name ?>&approved=1">Approuvées</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[0] ?>" href="?page=<?=$page_name ?>&approved=0">Non approuvées</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=$tabapproved[2] ?>" href="?page=<?=$page_name ?>&approved=2">Désapprouvées</a>
  </li>
</ul>
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
      <form action="" method="POST">
      <tr>
        <td><?=$result_obs['obs_token'] ?></td>
        <td>
          <img src="/generate_panel.php?s=150&token=<?=$result_obs['obs_token'] ?>" />
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
            <?php // Droits réservés aux admins
            if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') { ?>
          <input type="hidden" name="obs_id" value="<?=$result_obs['obs_id'] ?>" />
          <button class="btn btn-primary" type="submit">Valider édition</button><br />
          <a href="?page=<?=$page_name ?>&action=approve&approveto=1&obsid=<?=$result_obs['obs_id'] ?>">Approuver</a><br />
          <a href="?page=<?=$page_name ?>&action=approve&approveto=2&obsid=<?=$result_obs['obs_id'] ?>">Désapprouver</a><br />
          <a href="?page=<?=$page_name ?>&action=delete&obsid=<?=$result_obs['obs_id'] ?>" onclick="return confirm('Merci de valider la suppression')">Supprimer</a>
          <?php } ?>
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
      <a class="page-link" href="?page=<?=$page_name?>&approved=<?=$approved ?>&pagenb=<?=$pagenb-1 ?>" tabindex="-1">Previous</a>
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
   <li class="page-item <?=$active ?>"><a class="page-link" href="?page=<?=$page_name?>&approved=<?=$approved ?>&pagenb=<?=$i ?>"><?=$i ?></a></li>
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
      <a class="page-link" href="?page=<?=$page_name?>&approved=<?=$approved ?>&pagenb=<?=$pagenb+1 ?>">Next</a>
    </li>
  </ul>
</nav>
<?php
}
?>
<br />
