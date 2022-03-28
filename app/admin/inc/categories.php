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

if (isset($_POST['categorie_form'])) {
    $categorie_infos = array();
    $categorie_infos['categorie_local_resolvable'] = 0;
    foreach ($_POST as $key => $value) {
      if (preg_match('/categorie_(?:.*)$/', $key)) {
        $key   = mysqli_real_escape_string($db, $key);
        $value = mysqli_real_escape_string($db, $value);

        if ($key == 'categorie_local_resolvable') {
          $value = 1;
        }
        if ($key == 'categorie_enabled') {
          $value = 1;
        }
        $categorie_infos[$key] = $value;
      }
    }

    if ($_POST['categorie_form'] == 'add') {
        mysqli_query($db, "INSERT INTO obs_categories_local (categorie_local_name_fr,
                                                categorie_local_name_en,
                                                categorie_local_color,
                                                categorie_local_resolvable)
                                   VALUES ('".$categorie_infos['categorie_local_name_fr']."',
                                           '".$categorie_infos['categorie_local_name_en']."',
                                           '".$categorie_infos['categorie_local_color']."',
                                           '".$categorie_infos['categorie_local_resolvable']."')");
        $categorie_id = mysqli_insert_id($db);
     
        echo '<div class="alert alert-success" role="alert">Catégorie <strong>' . $categorie_id . '</strong> ajoutée</div>';
    }
    elseif ($_POST['categorie_form'] == 'update') {
       if($_POST['categorie_type'] == 'internal') {
           mysqli_query($db, "UPDATE obs_categories_local SET 
                                   categorie_local_name_fr='".$categorie_infos['categorie_local_name_fr']."',
                                   categorie_local_name_en='".$categorie_infos['categorie_local_name_en']."',
                                   categorie_local_color='".$categorie_infos['categorie_local_color']."',
                                   categorie_local_resolvable='".$categorie_infos['categorie_local_resolvable']."'
                              WHERE categorie_local_id=".$categorie_infos['categorie_id']);
           $categorie_internal = 1;
        }
        else {
           $categorie_internal = 0;
        }

        mysqli_query($db,"DELETE FROM obs_categories WHERE categorie_id='". $categorie_infos['categorie_id'] ."'");
        if ($categorie_infos['categorie_enabled']) {
            if (!$categorie_infos['categorie_order']) {
              $categorie_order = 100;
            }
            else {
              $categorie_order = $categorie_infos['categorie_order'];
            }
            mysqli_query($db,"INSERT INTO obs_categories (categorie_isinternal, 
                                                          categorie_id, 
                                                          categorie_order)
                                 VALUES('".$categorie_internal."',
                                        '".$categorie_infos['categorie_id']."',
                                        '".$categorie_order."')");
        }
        echo '<div class="alert alert-success" role="alert">Catégorie <strong>' . $categorie_infos['categorie_id'] . '</strong> modifiée</div>';

    }
    elseif ($_POST['categorie_form'] == 'delete') {
        mysqli_query($db, "DELETE FROM obs_categories_local WHERE categorie_local_id=".$categorie_infos['categorie_id']);
        mysqli_query($db, "DELETE FROM obs_categories WHERE categorie_id=".$categorie_infos['categorie_id']);
        mysqli_query($db, "UPDATE obs_list SET obs_categorie=100 WHERE obs_categorie=".$categorie_infos['categorie_id']);

        echo '<div class="alert alert-success" role="alert">Catégorie <strong>' . $categorie_infos['categorie_id'] . '</strong> supprimée</div>';
    }
}

$query_categories_enabled = mysqli_query($db, "SELECT * FROM obs_categories");
$categories_enabled = array();
while($result_categories_enabled = mysqli_fetch_array($query_categories_enabled)) {
  $categories_enabled[$result_categories_enabled['categorie_id']] = $result_categories_enabled;
}
$query_categories_local = mysqli_query($db, "SELECT * FROM obs_categories_local ORDER by categorie_local_id");

?>
<h2>Liste</h2>
<div class="table-responsive">
  <table class="table table-striped table-sm">
     <thead>
       <tr>
         <th># ID</th>
         <th>Type</th>
         <th>Ordre</th>
         <th>Nom FR</th>
         <th>Nom EN</th>
         <th>Couleur</th>
         <th>Résolvable</th>
         <th>Activé ?</th>
         <th> / </th>
         <th> / </th>
       </tr>
     </thead>
     <tbody>
<?php
foreach (getCategoriesExternal() as $catext_order => $catext_value) {
  if (!isset($catext_value['catdisable']) || $catext_value['catdisable'] != 'true') {
    if (isset($categories_enabled[$catext_value['catid']])) {
      $catenabled = True;
      $catenabled_ck = "checked";
    }
    else {
      $catenabled = False;
      $catenabled_ck = "";
    }

    if($catext_value['catresolvable'] == 'true') {
      $resolvable = 'X';
    }
    else {
      $resolvable = '';
    }
?>
      <form action="" method="POST">
         <tr>
           <td>#<?=$catext_value['catid'] ?><input type="hidden" name="categorie_id" value="<?=$catext_value['catid'] ?>"</td>
           <td><em>Centralisée</em><input type="hidden" name="categorie_type" value="external" /></td>
           <td>
           <?php
           if ($catenabled) {
               $cat_order_value = $categories_enabled[$catext_value['catid']]['categorie_order']; ?>

             <input type="text" size="5" class="form-control-plaintext" name="categorie_order" value="<?=$cat_order_value ?>" required />
           <?php }
           else {
             echo "<em>N/A</em>";
           } ?>
           </td>
           <td><em><?=$catext_value['catname'] ?></em></td>
           <td><em><?=$catext_value['catname_en_US'] ?></em></td>
           <td><em><?=$catext_value['catcolor'] ?></em></td>
           <td><?=$resolvable ?></td>
           <td><input type="checkbox" name="categorie_enabled"  <?= $catenabled_ck ?>></td>
           <td><button class="btn btn-primary" type="submit" name="categorie_form" value="update" />Modifier</button></td>
           <td></td>
       </tr>
     </form>
<?php
  }
}
while ($result_categories_local = mysqli_fetch_array($query_categories_local)) {
    if (isset($categories_enabled[$result_categories_local['categorie_local_id']])) {
      $catenabled = True;
      $catenabled_ck = "checked";
    }
    else {
      $catenabled = False;
      $catenabled_ck = "";
    }

    $isresolvable_ck = "";
    if ($result_categories_local['categorie_local_resolvable'] == 1) {
        $isresolvable_ck = "checked";
    }

?>
      <form action="" method="POST">
         <tr>
           <td><input type="hidden" name="categorie_id" value="<?= $result_categories_local['categorie_local_id'] ?>" />#<?= $result_categories_local['categorie_local_id'] ?></td>
           <td><em>Interne</em><input type="hidden" name="categorie_type" value="internal" /></td>
           <td>
           <?php
           if ($catenabled) { 
               $cat_order_value = $categories_enabled[$result_categories_local['categorie_local_id']]['categorie_order']; ?>

             <input type="text" size="5" class="form-control-plaintext" name="categorie_order" value="<?=$cat_order_value ?>" required />
           <?php } 
           else { 
             echo "<em>N/A</em>";
           } ?>
           </td>
           <td><input type="text" class="form-control-plaintext" name="categorie_local_name_fr" value="<?= $result_categories_local['categorie_local_name_fr'] ?>" required /></td>
           <td><input type="text" class="form-control-plaintext" name="categorie_local_name_en" value="<?= $result_categories_local['categorie_local_name_en'] ?>" required /></td>
           <td><input type="text" size="10" class="form-control-plaintext" name="categorie_local_color" value="<?= $result_categories_local['categorie_local_color'] ?>" required  /></td>
           <td><input type="checkbox" name="categorie_local_resolvable"  <?= $isresolvable_ck ?>></td>
           <td><input type="checkbox" name="categorie_enabled" <?= $catenabled_ck ?>></td>
           <td><button class="btn btn-primary" type="submit" name="categorie_form" value="update" />Modifier</button></td>
           <td><button class="btn btn-primary" type="submit" name="categorie_form" value="delete" />Supprimer</button></td>
       </tr>
      </form>
<?php
}
?>
     <form action="" method="POST">
         <tr>
           <td># </td>
           <td><em>Interne</em></td>
           <td><em>N/A</em></td>
           <td><input type="text" class="form-control-plaintext" name="categorie_local_name_fr" required  /></td>
           <td><input type="text" class="form-control-plaintext" name="categorie_local_name_en" required  /></td>
           <td><input type="text" size="10" class="form-control-plaintext" name="categorie_local_color" value="black" required /></td>
           <td><input type="checkbox" name="categorie_local_resolvable" /></td>
           <td></td>
           <td><button class="btn btn-primary" type="submit" name="categorie_form" value="add" />Ajouter</button></td>
           <td></td>
       </tr>
     </form>
   </tbody>
  </table>
</div>

