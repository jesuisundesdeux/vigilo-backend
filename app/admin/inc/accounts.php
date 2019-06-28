<?php
if(isset($_GET['action']) && !isset($_POST['role_id'])) {
  if($_GET['action'] == 'add') {
	  mysqli_query($db,"INSERT INTO obs_roles (role_key,
		                                    role_name,
						    role_owner,
						    role_login,
                                                    role_password)
                              VALUES ('".uniqid()."',
                                      'guest',
                                      '',
                                      '',
                                      '')") or die(mysqli_error($db));
    echo '<div class="alert alert-success" role="alert">Compte ajouté, merci de remplir les champs correspondants</div>';
  }
  elseif($_GET['action'] == 'delete') {
    $roleid = mysqli_real_escape_string($db,$_GET['roleid']);
    mysqli_query($db,"DELETE FROM obs_roles WHERE role_id = '".$roleid."'") or die(mysqli_error($db));
    echo '<div class="alert alert-success" role="alert">Compte <strong>'.$roleid.'</strong> supprimé</div>';
  }
}
if(isset($_POST['role_id'])) {
    $update = "";
    foreach($_POST as $key => $value) {
       if(preg_match('/role_(?:.*)$/',$key)) {
        $key = mysqli_real_escape_string($db,$key);
	$value = mysqli_real_escape_string($db,$value);
	if($key == 'role_password') {
    	  if($value != '') { 
	    $value = hash('sha256',$value);
	  }
	}
	if(!($key == 'role_password' && $value == '')) { 
          $update .= $key . "='".$value."',"; 
	}
      }
    }
    $update = rtrim($update,',');
    $roleid = mysqli_real_escape_string($db,$_POST['role_id']);
    mysqli_query($db,"UPDATE obs_roles SET ". $update . " WHERE role_id='".$roleid."'") or die(mysqli_error($db));
    echo $update;
    echo '<div class="alert alert-success" role="alert">
	  Compte <strong>'.$roleid.'</strong> mis à jour
	      </div>';
}

echo '<h2>Liste</h2>';
echo '<p><a href="?page=accounts&action=add">Ajouter un compte</a></p>';
$query_role = mysqli_query($db, "SELECT * FROM obs_roles");

echo '<div class="table-responsive">
      <table class="table table-striped table-sm">
      <thead>
	 <tr>
           <th># ID</th>
           <th>Clé</th>
           <th>Role</th>
           <th>Nom utilisateur</th>
           <th>Login</th>
           <th>Mot de passe</th>
           <th> </th>
	   <th> </th>
         </tr>
       </thead><tbody>';

while($result_role = mysqli_fetch_array($query_role)) {

 echo '<form action="" method="POST">';
 echo '<tr>
           <td>#'.$result_role['role_id'].' </td>
           <td><input type="text" class="form-control-plaintext" name="role_key" value="'.$result_role['role_key'].'" /></td>
	   <td><select name="role_name" class=" custom-select">';
  foreach(array('guest','admin','citystaff') as $value) {
    if($value == $result_role['role_name']) {
      $selected = "selected";
    }
    else {
      $selected= "";
    }
    echo '<option '.$selected.' >'.$value.'</option>';
  }
 echo '</select></td>'; 
 echo '<td><input type="text" class="form-control-plaintext" name="role_owner" value="'.$result_role['role_owner'].'" /></td>
	<td><input type="text" class="form-control-plaintext" name="role_login" value="'.$result_role['role_login'].'" /></td>
	<td><input type="text" class="form-control-plaintext" name="role_password" /></td>
       <td><input type="hidden" name="role_id" value="'.$result_role['role_id'].'" /><button class="btn btn-primary" type="submit">Valider édition</button></td>
       <td><a href="?page=accounts&action=delete&roleid='.$result_role['role_id'].'" onclick="return confirm(\'Are you sure?\')">Supprimer</a></td>
	 </tr>';
  echo '<form>';

}
echo '</tbody></table></div>';

echo "<br />";
