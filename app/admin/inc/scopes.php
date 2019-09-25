<?php
if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'],$menu[$page_name]['access']))) {
  exit('Not allowed');
}


if (isset($_GET['action']) && !isset($_POST['scope_id'])) {
  if ($_GET['action'] == 'add') {
    mysqli_query($db,"INSERT INTO obs_scopes (scope_name,
                                              scope_display_name,
                                              scope_department,
                                              scope_coordinate_lat_min,
                                              scope_coordinate_lat_max,
                                              scope_coordinate_lon_min,
                                              scope_coordinate_lon_max,
                                              scope_map_center_string,
                                              scope_map_zoom,
                                              scope_contact_email,
                                              scope_sharing_content_text,
                                              scope_twitter,
                                              scope_twitteraccountid,
                                              scope_twittercontent,
                                              scope_umap_url)
                                     VALUES ('xx_scope',
                                             'Nouveau Scope',
                                             '00',
                                             '0.00',
                                             '0.00',
                                             '0.00',
                                             '0.00',
                                             '0.00,0.00',
                                             '15',
                                             'email@domaine.com',
                                             '',
                                             '',
                                             '0',
                                             '',
                                             '')");
    echo '<div class="alert alert-success" role="alert">Scope ajouté, merci de remplir les champs correspondants</div>';
  }
  if(isset($_GET['scopeid']) && is_numeric($_GET['scopeid'])) {
    if ($_GET['action'] == 'delete') {
      $scopeid = mysqli_real_escape_string($db,$_GET['scopeid']);
      mysqli_query($db,"DELETE FROM obs_scopes WHERE scope_id = '".$scopeid."'");
      echo '<div class="alert alert-success" role="alert">Scope <strong>'.$scopeid.'</strong> supprimé</div>';
    }
  }
}
if (isset($_POST['scope_id'])) {
  $update = "";
  foreach ($_POST as $key => $value) {
    if (preg_match('/scope_(?:.*)$/',$key)) {
      $key = mysqli_real_escape_string($db,$key);
      $value = mysqli_real_escape_string($db,$value);
      $update .= $key . "='".$value."',";
    }
  }
  $update = rtrim($update,',');
  $scopeid = mysqli_real_escape_string($db,$_POST['scope_id']);
  mysqli_query($db,"UPDATE obs_scopes SET ". $update . " WHERE scope_id='".$scopeid."'");
  echo '<div class="alert alert-success" role="alert">Scope <strong>'.$scopeid.'</strong> mis à jour</div>';
}

$twitterlist = array();
$query_twitter = mysqli_query($db, "SELECT * FROM obs_twitteraccounts");
while ($result_twitter = mysqli_fetch_array($query_twitter)) {
  $twitterlist[] = $result_twitter['ta_id'];
}
$query_scopes = mysqli_query($db, "SELECT * FROM obs_scopes");

?>
<h2>Liste</h2>
<p><a href="?page=<?=$page_name ?>&action=add">Ajouter un scope</a></p>

<?php
while ($result_scopes = mysqli_fetch_array($query_scopes)) {
?>

<h3><?=$result_scopes['scope_display_name'] ?></h3>
<a href="?page=<?=$page_name ?>&action=delete&scopeid=<?=$result_scopes['scope_id'] ?>" onclick="return confirm('Are you sure?')">Supprimer</a>

<form action="" method="POST">

<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th>Paramètre</th>
        <th>Valeur</th>
      </tr>
    <tbody>
      <tr>
        <td>Identifiant</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_name" value="<?=$result_scopes['scope_name'] ?>" required>
        </td>
      </tr>
      <tr>
        <td>Nom affiché</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_display_name" value="<?=$result_scopes['scope_display_name'] ?>" required />
        </td>
      </tr>
      <tr>
        <td>Departement</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_department" value="<?=$result_scopes['scope_department'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Latitude minimale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lat_min" value="<?=$result_scopes['scope_coordinate_lat_min'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Latitude maximale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lat_max" value="<?=$result_scopes['scope_coordinate_lat_max'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Longitude minimale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lon_min" value="<?=$result_scopes['scope_coordinate_lon_min'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Longitude maximale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lon_max" value="<?=$result_scopes['scope_coordinate_lon_max'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Coordonées centre du scope</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_map_center_string" value="<?=$result_scopes['scope_map_center_string'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Zoom cartes</td>
	      <td>
          <select name="scope_map_zoom" class=" custom-select">
<?php
  for ($i = 1; $i <= 20; $i++) {
    if ($i == $result_scopes['scope_map_zoom']) {
      $selected = "selected";
    }
    else {
      $selected = "";
    }
    echo '<option '.$selected.' >'.$i.'</option>';
  }
?>
           </select>
         </td>
       </tr>
       <tr>
         <td>Email Contact</td>
         <td>
           <input type="text" class="form-control-plaintext" name="scope_contact_email" value="<?=$result_scopes['scope_contact_email'] ?>" />
         </td>
       </tr>
       <tr>
         <td>Text de partage par défaut</td>
         <td>
           <textarea class="form-control-plaintext" name="scope_sharing_content_text" rows="5"><?=$result_scopes['scope_sharing_content_text'] ?></textarea>
         </td>
       </tr>
       <tr>
         <td>Compte Twitter affiché</td>
         <td>
           <input type="text" class="form-control-plaintext" name="scope_twitter" value="<?=$result_scopes['scope_twitter'] ?>" />
         </td>
       </tr>
       <tr>
   	     <td>Identifiant compte twitter</td>
     	   <td>
           <select name="scope_twitteraccountid" class=" custom-select">
<?php
  foreach ($twitterlist as $twitterid) {
    if ($twitterid == $result_scopes['scope_twitteraccountid']) {
      $selected = "selected";
    }
    else {
      $selected = "";
    }

   echo '<option '.$selected.' >'.$twitterid.'</option>';
 }
?>
          </select>
        </td>
      </tr>
      <tr>
        <td>Contenu des tweets autos :
          <ul>
            <li><strong>[COMMENT] : </strong>Commentaire observation</li>
            <li><strong>[TOKEN] : </strong>Identifiant observation</li>
            <li><strong>[COORDINATES_LON] : </strong>Longitude</li>
            <li><strong>[COORDINATES_LAT] : </strong>Latitude</li>
          </ul>
        </td>
        <td>
          <textarea class="form-control-plaintext" name="scope_twittercontent" rows="5"><?=$result_scopes['scope_twittercontent'] ?></textarea>
        </td>
      </tr>
      <tr>
        <td>URL carte externe</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_umap_url" value="<?=$result_scopes['scope_umap_url'] ?>" />
        </td>
      </tr>
    </tbody>
  </table>
</div>
<input type="hidden" name="scope_id" value="<?=$result_scopes['scope_id'] ?>" />
<button class="btn btn-primary" type="submit">Valider édition</button></form>

<br />
<?php
}
?>
