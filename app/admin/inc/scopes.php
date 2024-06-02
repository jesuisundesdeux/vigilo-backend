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

if (isset($_GET['action']) && !isset($_POST['scope_id'])) {
    if ($_GET['action'] == 'add' && (!isset($config['SAAS_MODE']) || !$config['SAAS_MODE'])) {
        mysqli_query($db, "INSERT INTO obs_scopes (scope_name,
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
                                              scope_socialname,
                                              scope_socialmediaaccountid,
                                              scope_socialcontent,
                                              scope_umap_url,
                                              scope_nominatim_urlbase)
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
                                             '',
                                             'https://nominatim.openstreetmap.org')");
        echo '<div class="alert alert-success" role="alert">Scope ajouté, merci de remplir les champs correspondants</div>';
    } elseif ($_GET['action'] == 'delete' && isset($_GET['scopeid']) && is_numeric($_GET['scopeid']) && (!isset($config['SAAS_MODE']) || !$config['SAAS_MODE'])) {
        $scopeid = mysqli_real_escape_string($db, $_GET['scopeid']);
        mysqli_query($db, "DELETE FROM obs_scopes WHERE scope_id = '" . $scopeid . "'");
        echo '<div class="alert alert-success" role="alert">Scope <strong>' . $scopeid . '</strong> supprimé</div>';
    } elseif (isset($config['SAAS_MODE']) && $config['SAAS_MODE']) {
        echo '<div class="alert alert-warning" role="alert">Cette fonctionnalité n\'est pas accessible en SaaS</div>';
    }
}

if (isset($_POST['scope_id'])) {
    $update = "";
    foreach ($_POST as $key => $value) {
        if (preg_match('/scope_(?:.*)$/', $key)) {
            $key   = mysqli_real_escape_string($db, $key);
            $value = mysqli_real_escape_string($db, $value);
            $update .= $key . "='" . $value . "',";
        }
    }
    $update  = rtrim($update, ',');
    $scopeid = mysqli_real_escape_string($db, $_POST['scope_id']);
    mysqli_query($db, "UPDATE obs_scopes SET " . $update . " WHERE scope_id='" . $scopeid . "'");
    echo '<div class="alert alert-success" role="alert">Scope <strong>' . $scopeid . '</strong> mis à jour</div>';
}

$sociallist   = array(0 => "-- Pas de réseau social --");
$query_social = mysqli_query($db, "SELECT * FROM obs_social_media_accounts");
// TODO: adapt for mastodon
while ($result_social = mysqli_fetch_array($query_social)) {
    $social_id = $result_social['ta_id'];
    $sociallist[$social_id] = '#' . $social_id;
}
$query_scopes = mysqli_query($db, "SELECT * FROM obs_scopes");

?>
<h2>Liste</h2>
<p><a href="?page=<?= $page_name ?>&action=add">Ajouter un scope</a></p>

<?php
while ($result_scopes = mysqli_fetch_array($query_scopes)) {
?>

<h3><?= $result_scopes['scope_display_name'] ?></h3>
<a href="?page=<?= $page_name ?>&action=delete&scopeid=<?= $result_scopes['scope_id'] ?>" onclick="return confirm('Are you sure?')">Supprimer</a>

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
          <input type="text" class="form-control-plaintext" name="scope_name" value="<?= $result_scopes['scope_name'] ?>" required>
        </td>
      </tr>
      <tr>
        <td>Nom affiché</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_display_name" value="<?= $result_scopes['scope_display_name'] ?>" required />
        </td>
      </tr>
      <tr>
        <td>Departement</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_department" value="<?= $result_scopes['scope_department'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Latitude minimale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lat_min" value="<?= $result_scopes['scope_coordinate_lat_min'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Latitude maximale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lat_max" value="<?= $result_scopes['scope_coordinate_lat_max'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Longitude minimale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lon_min" value="<?= $result_scopes['scope_coordinate_lon_min'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Longitude maximale (DD)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_coordinate_lon_max" value="<?= $result_scopes['scope_coordinate_lon_max'] ?>" />
        </td>
      </tr>
      <tr>
        <td>Coordonées centre du scope</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_map_center_string" value="<?= $result_scopes['scope_map_center_string'] ?>" />
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
        } else {
            $selected = "";
        }
        echo '<option ' . $selected . ' >' . $i . '</option>';
    }
?>
          </select>
         </td>
       </tr>
       <tr>
         <td>Email Contact</td>
         <td>
           <input type="text" class="form-control-plaintext" name="scope_contact_email" value="<?= $result_scopes['scope_contact_email'] ?>" />
         </td>
       </tr>
       <tr>
         <td>Text de partage par défaut</td>
         <td>
           <textarea class="form-control-plaintext" name="scope_sharing_content_text" rows="5"><?= $result_scopes['scope_sharing_content_text'] ?></textarea>
         </td>
       </tr>
       <tr>
         <td>Compte affiché</td>
         <td>
           <input type="text" class="form-control-plaintext" name="scope_socialname" value="<?= $result_scopes['scope_socialname'] ?>" />
         </td>
       </tr>
       <tr>
             <td>Identifiant compte</td>
           <td>
           <select name="scope_socialmediaaccountid" class=" custom-select">
<?php
    foreach ($sociallist as $socialid => $socialname) {
        if ($socialid == $result_scopes['scope_socialmediaaccountid']) {
            $selected = "selected";
        } else {
            $selected = "";
        }
        
        echo '<option value='.$socialid.' ' . $selected . '>' . $socialname . '</option>';
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
            <li><strong>[CATEGORY] : </strong>Categorie</li>
            <li><strong>[CITY] : </strong>Nom de la ville</li>
            <li><strong>[CITYHASHTAG] : </strong>Hashtag de la ville</li>
            
          </ul>
        </td>
        <td>
          <textarea class="form-control-plaintext" name="scope_socialcontent" rows="5"><?= $result_scopes['scope_socialcontent'] ?></textarea>
        </td>
      </tr>
      <tr>
        <td>URL carte externe</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_umap_url" value="<?= $result_scopes['scope_umap_url'] ?>" />
        </td>
      </tr>
      <tr>
        <td>URLbase Nominatim (reverse geocoding)</td>
        <td>
          <input type="text" class="form-control-plaintext" name="scope_nominatim_urlbase" value="<?= $result_scopes['scope_nominatim_urlbase'] ?>" />
        </td>
      </tr>

    </tbody>
  </table>
</div>
<input type="hidden" name="scope_id" value="<?= $result_scopes['scope_id'] ?>" />
<button class="btn btn-primary" type="submit">Valider édition</button></form>

<br />
<?php
}
?>
