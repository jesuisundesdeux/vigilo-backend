<?php
if(isset($_GET['action']) && !isset($_POST['scope_id'])) {
  if($_GET['action'] == 'add') {
	  mysqli_query($db,"INSERT INTO obs_scopes (scope_name,
		                                    scope_department,
						    scope_display_name,
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
                                      '00',
                                      'Nouveau Scope',
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
                                      '')") or die(mysqli_error($db));
    echo '<div class="alert alert-success" role="alert">Scope ajouté, merci de remplir les champs correspondants</div>';
  }
  elseif($_GET['action'] == 'delete') {
    $scopeid = mysqli_real_escape_string($db,$_GET['scopeid']);
    mysqli_query($db,"DELETE FROM obs_scopes WHERE scope_id = '".$scopeid."'") or die(mysqli_error($db));
    echo '<div class="alert alert-success" role="alert">Scope <strong>'.$scopeid.'</strong> supprimé</div>';
  }
}
if(isset($_POST['scope_id'])) {
    $update = "";
    foreach($_POST as $key => $value) {
       if(preg_match('/scope_(?:.*)$/',$key)) {
        $key = mysqli_real_escape_string($db,$key);
        $value = mysqli_real_escape_string($db,$value);
        $update .= $key . "='".$value."',";
      }
    }
    $update = rtrim($update,',');
    $scopeid = mysqli_real_escape_string($db,$_POST['scope_id']);
    mysqli_query($db,"UPDATE obs_scopes SET ". $update . " WHERE scope_id='".$scopeid."'");
    echo '<div class="alert alert-success" role="alert">
	  Scope <strong>'.$scopeid.'</strong> mis à jour
	      </div>';
}

$twitterlist = array();
$query_twitter = mysqli_query($db, "SELECT * FROM obs_twitteraccounts");
while($result_twitter = mysqli_fetch_array($query_twitter)) {
  $twitterlist[] = $result_twitter['ta_id']; 
}
echo '<h2>Liste</h2>';
echo '<p><a href="?page=scopes&action=add">Ajouter un scope</a></p>';
$query_scopes = mysqli_query($db, "SELECT * FROM obs_scopes");
while($result_scopes = mysqli_fetch_array($query_scopes)) {
 echo '<h3>'.$result_scopes['scope_display_name'].'</h3>';
 echo '<a href="?page=scopes&action=delete&scopeid='.$result_scopes['scope_id'].'" onclick="return confirm(\'Are you sure?\')">Supprimer</a>';

 echo '<form action="" method="POST">';

 echo '<div class="table-responsive">
        <table class="table table-striped table-sm">
        <thead>
          <tr>
            <th>Paramètre</th>
            <th>Valeur</th>
        </th>
        <tbody>';
  echo '<tr>
           <td>Identifiant</td>
           <td><input type="text" class="form-control-plaintext" name="scope_name" value="'.$result_scopes['scope_name'].'" /></td>
         </tr>';
  echo '<tr>
           <td>Nom affiché</td>
           <td><input type="text" class="form-control-plaintext" name="scope_display_name" value="'.$result_scopes['scope_display_name'].'" /></td>
         </tr>';
  echo '<tr>
           <td>Departement</td>
           <td><input type="text" class="form-control-plaintext" name="scope_department" value="'.$result_scopes['scope_department'].'" /></td>
         </tr>';

  echo '<tr>
           <td>Latitude minimale (DD)</td>
           <td><input type="text" class="form-control-plaintext" name="scope_coordinate_lat_min" value="'.$result_scopes['scope_coordinate_lat_min'].'" /></td>
	 </tr>';

   echo '<tr>
           <td>Latitude maximale (DD)</td>
           <td><input type="text" class="form-control-plaintext" name="scope_coordinate_lat_max" value="'.$result_scopes['scope_coordinate_lat_max'].'" /></td>
         </tr>';
   echo '<tr>
           <td>Longitude minimale (DD)</td>
           <td><input type="text" class="form-control-plaintext" name="scope_coordinate_lon_min" value="'.$result_scopes['scope_coordinate_lon_min'].'" /></td>
         </tr>';
   echo '<tr>
           <td>Longitude maximale (DD)</td>
           <td><input type="text" class="form-control-plaintext" name="scope_coordinate_lon_max" value="'.$result_scopes['scope_coordinate_lon_max'].'" /></td>
         </tr>';
   echo '<tr>
           <td>Coordonées centre du scope</td>
           <td><input type="text" class="form-control-plaintext" name="scope_map_center_string" value="'.$result_scopes['scope_map_center_string'].'" /></td>
         </tr>';
   echo '<tr>
           <td>Zoom cartes</td>
	   <td><select name="scope_map_zoom" class=" custom-select">';
   for($i = 1; $i <= 20; $i++) {
     if($i == $result_scopes['scope_map_zoom']) {
       $selected = "selected";
     }
     else {
       $selected = "";
     }
     echo '<option '.$selected.' >'.$i.'</option>';

  }
   echo '</select></td></tr>';
   echo '<tr>
           <td>Email Contact</td>
           <td><input type="text" class="form-control-plaintext" name="scope_contact_email" value="'.$result_scopes['scope_contact_email'].'" /></td>
         </tr>';
   echo '<tr>
           <td>Text de partage par défaut</td>
           <td> <textarea class="form-control-plaintext" name="scope_sharing_content_text" rows="3">'.$result_scopes['scope_sharing_content_text'].'</textarea></td>
         </tr>';
   echo '<tr>
           <td>Compte Twitter affiché</td>
           <td><input type="text" class="form-control-plaintext" name="scope_twitter" value="'.$result_scopes['scope_twitter'].'" /></td>
         </tr>';
   echo '<tr>
	   <td>Identifiant compte twitter</td>
	   <td><select name="scope_twitteraccountid" class=" custom-select">';

   foreach($twitterlist as $twitterid) {
     if($twitterid == $result_scopes['scope_twitteraccountid']) {
       $selected = "selected";
     }
     else {
       $selected = "";
     }
   echo '<option '.$selected.' >'.$twitterid.'</option>';
   }

   echo '</selected</td></tr>';
   echo '<tr>
           <td>Contenu des tweets autos </td>
           <td><textarea class="form-control-plaintext" name="scope_twittercontent" rows="3">'.$result_scopes['scope_twittercontent'].'</textarea></td>
         </tr>';

   echo '<tr>
           <td>URL carte externe</td>
           <td><input type="text" class="form-control-plaintext" name="scope_umap_url" value="'.$result_scopes['scope_umap_url'].'" /></td>
         </tr>';

echo '</tbody></table></div>
<input type="hidden" name="scope_id" value="'.$result_scopes['scope_id'].'" />
<button class="btn btn-primary" type="submit">Valider édition</button></form>';

echo "<br />";
} 
