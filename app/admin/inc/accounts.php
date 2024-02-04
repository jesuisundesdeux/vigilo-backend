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

if (isset($_GET['ask_pwd_update'])) {
    echo '<div class="alert alert-danger" role="alert">Pour des raisons de sécurité, merci de mettre à jour le mot de passe de votre compte</div>';
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
    // Initialize update string
    $update = "";

    // Process POST data
    foreach ($_POST as $key => $value) {
        if (preg_match('/role_(?:.*)$/', $key)) {
            $key = mysqli_real_escape_string($db, $key);
            $value = mysqli_real_escape_string($db, $value);

            // Special handling for password field
            if ($key == 'role_password' && $value != '') {
                $value = password_hash($value, PASSWORD_DEFAULT);
            }

            // Append the other fields to the update string except empty password updates
            if (!($key == 'role_password' && $value == '')) {
                if ($key == 'role_city') {
                    // Process and update role_city separately
                    $sanitizedRoleCityArray = isset($_POST['role_city']) && is_array($_POST['role_city']) ? $_POST['role_city'] : [];
                    $cleanedRoleCity = json_encode(array_filter($sanitizedRoleCityArray));
                    $update .= "role_city='" . $cleanedRoleCity . "',";
                } else {
                    $update .= $key . "='" . $value . "',";
                }
            }
        }
    }

    // Trim trailing comma
    $update = rtrim($update, ',');

    // Sanitize role_id input
    $roleId = mysqli_real_escape_string($db, $_POST['role_id']);

    // Update the database
    $updateQuery = "UPDATE obs_roles SET " . $update . " WHERE role_id='" . $roleId . "'";
    mysqli_query($db, $updateQuery);

    // Display success message
    echo '<div class="alert alert-success" role="alert">Compte <strong>' . $roleId . '</strong> mis à jour</div>';
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
                    
                    // Fetch all cities from the database
                    $city_query = mysqli_query($db, "SELECT city_name FROM obs_cities");
                    $cities = mysqli_fetch_all($city_query, MYSQLI_ASSOC);

                    // Create a select field
                    echo '<select class="custom-select" name="role_city[]" multiple>';

                    // Populate select options
                    foreach ($cities as $city) {
                        $selected = in_array($city['city_name'], $role_cities) ? 'selected' : '';
                        echo '<option value="' . $city['city_name'] . '" ' . $selected . '>' . $city['city_name'] . '</option>';
                    }

                    echo '</select>';
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
