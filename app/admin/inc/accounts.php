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

if (isset($_GET['action']) && !isset($_POST['role_id'])) {
    if ($_GET['action'] == 'add') {
        mysqli_query($db, "INSERT INTO obs_roles (role_key,
                                            role_name,
                                            role_owner,
                                            role_login,
                                            role_password)
                               VALUES ('',
                                      'guest',
                                      '',
                                      '',
                                      '')");
        echo '<div class="alert alert-success" role="alert">Compte ajouté, merci de remplir les champs correspondants</div>';
    }
    
    if (isset($_GET['roleid']) && is_numeric($_GET['roleid'])) {
        if ($_GET['action'] == 'delete') {
            $roleid = mysqli_real_escape_string($db, $_GET['roleid']);
            mysqli_query($db, "DELETE FROM obs_roles WHERE role_id = '" . $roleid . "'");
            echo '<div class="alert alert-success" role="alert">Compte <strong>' . $roleid . '</strong> supprimé</div>';
        }
    }
}
if (isset($_POST['role_id'])) {
    $update = "";
    foreach ($_POST as $key => $value) {
        if (preg_match('/role_(?:.*)$/', $key)) {
            $key   = mysqli_real_escape_string($db, $key);
            $value = mysqli_real_escape_string($db, $value);
            if ($key == 'role_password') {
                if ($value != '') {
                    $value = hash('sha256', $value);
                }
            }
            if (!($key == 'role_password' && $value == '')) {
                $update .= $key . "='" . $value . "',";
            }
        }
    }
    $update = rtrim($update, ',');
    $roleid = mysqli_real_escape_string($db, $_POST['role_id']);
    mysqli_query($db, "UPDATE obs_roles SET " . $update . " WHERE role_id='" . $roleid . "'");
    
    echo '<div class="alert alert-success" role="alert">Compte <strong>' . $roleid . '</strong> mis à jour</div>';
}
if (isset($_POST['role_city']) && isset($_POST['role_id'])) {
    $rolecity = json_encode(array_filter(explode(",", trim(preg_replace('/\s*,\s*/',',',mysqli_real_escape_string($db, $_POST['role_city']))))));
    $roleid   = mysqli_real_escape_string($db, $_POST['role_id']);
    mysqli_query($db, "UPDATE obs_roles SET role_city = '" . $rolecity . "' WHERE role_id='" . $roleid . "'");
}
$query_cities = mysqli_query($db, "SELECT city_name, city_id FROM obs_cities");

$query_role = mysqli_query($db, "SELECT * FROM obs_roles");
?>
<h2>Liste</h2>
<p><a href="?page=<?= $page_name ?>&action=add">Ajouter un compte</a></p>

<div class="table-responsive">
  <table class="table table-striped table-sm">
     <thead>
         <tr>
         <th># ID</th>
         <th>Clé</th>
         <th>Role</th>
         <th>Nom utilisateur</th>
         <th>Login</th>
         <th>Mot de passe</th>
         <th>Ville</th>
         <th> </th>
           <th> </th>
       </tr>
     </thead>
     <tbody>
<?php
while ($result_role = mysqli_fetch_array($query_role)) {
?>
      <form action="" method="POST">
         <tr>
           <td>#<?= $result_role['role_id'] ?></td>
           <td><input type="text" class="form-control-plaintext" name="role_key" value="<?= $result_role['role_key'] ?>"  /></td>
             <td>
             <select name="role_name" class=" custom-select">
<?php
    foreach (array(
        'guest',
        'admin',
        'citystaff',
        'moderator'
    ) as $value) {
        if ($value == $result_role['role_name']) {
            $selected = "selected";
        } else {
            $selected = "";
        }
        echo '<option ' . $selected . ' >' . $value . '</option>';
    }
?>
          </select>
         </td>
         <td>
           <input type="text" class="form-control-plaintext" name="role_owner" value="<?= $result_role['role_owner'] ?>" />
         </td>
           <td>
           <input type="text" class="form-control-plaintext" name="role_login" value="<?= $result_role['role_login'] ?>" />
         </td>
            <td>
           <input type="password" class="form-control-plaintext" name="role_password" />
         </td>
         <td>
            <?php
    if ($result_role['role_name'] == 'citystaff') {
        $role_cities = json_decode($result_role['role_city']);
?>
               <small><span class="text-info">Séparées par ,</span></small>
                <br/>
                <?php
        echo '<input type="text" class="form-control-plaintext" name="role_city" value="';
        $array_role_cities = (array) $role_cities;
        $last_role_city = array_pop($array_role_cities);
        foreach ($array_role_cities as $role_city) {
            echo $role_city . ", ";
        }
        echo $last_role_city;
        echo '">';
    } else {
        echo '<small><span class="text-info">n\'est pas citystaff</span></small>';
    }
?>
        </td>
         <td>
           <input type="hidden" name="role_id" value="<?= $result_role['role_id'] ?>" />
           <button class="btn btn-primary" type="submit">Valider édition</button>
         </td>
         <td>
           <a href="?page=<?= $page_name ?>&action=delete&roleid=<?= $result_role['role_id'] ?>" onclick="return confirm('Merci de valider la suppression?')">Supprimer</a>
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
