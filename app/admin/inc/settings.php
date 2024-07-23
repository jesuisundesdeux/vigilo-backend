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

if (isset($config['SAAS_MODE']) && $config['SAAS_MODE']) {
    echo '<div class="alert alert-warning" role="alert">La configuration n\'est pas accessible en SaaS</div>';
} else {
    
    if (isset($_POST['config_param'])) {
        foreach ($_POST as $key => $value) {
            if (preg_match('/config_param_(?:.*)$/', $key)) {
                $key   = str_replace('config_param_', '', mysqli_real_escape_string($db, $key));
                $value = mysqli_real_escape_string($db, $value);
                
                if ($key == "vigilo_shownonapproved") {
                    $value = 1;
                }
                
                $update = "UPDATE obs_config SET config_value='" . $value . "' WHERE config_param='" . $key . "'";
                mysqli_query($db, $update);
            }
        }
        if (!array_key_exists('config_param_vigilo_shownonapproved', $_POST)) {
            mysqli_query($db, "UPDATE obs_config SET config_value='0' WHERE config_param='vigilo_shownonapproved'");
        }
        
        echo '<div class="alert alert-success" role="alert">Configuration mise à jour</div>';
    }
    
    $query_config = mysqli_query($db, "SELECT * FROM obs_config");
    while ($result_config = mysqli_fetch_array($query_config)) {
        $param                            = $result_config['config_param'];
        $config['config_param_' . $param] = $result_config['config_value'];
    }
    $shownonapproved_ck = "";
    if ($config['config_param_vigilo_shownonapproved'] == 1) {
        $shownonapproved_ck = "checked";
    }
?>

<h2>Liste</h2>

<form action="" method="POST">

<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th width="400px">Paramètre</th>
        <th>Valeur</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>URL base</td>
        <td>
          <input type="text" class="form-control-plaintext" name="config_param_vigilo_urlbase" value="<?= $config['config_param_vigilo_urlbase'] ?>" required />
        </td>
     </tr>
     <tr>
       <td>Protocole (http ou https)</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_http_proto" value="<?= $config['config_param_vigilo_http_proto'] ?>" required />
       </td>
     </tr>
     <tr>
       <td>Nom instance</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_name" value="<?= $config['config_param_vigilo_name'] ?>" required />
       </td>
     </tr>
     <tr height="50px">
       <td>Afficher observations non modérées</td>
       <td>
           <input type="checkbox" class="form-check-input" name="config_param_vigilo_shownonapproved" id="defaultCheck1" <?= $shownonapproved_ck ?>>
       </td>
     </tr>
     <tr>
       <td>Panel</td>
       <td>
         <select class="form-control" name="config_param_vigilo_panel">
        <?php
    if ($handle = opendir('../panels')) {
        while (false !== ($entry = readdir($handle))) {
            $selected = '';
            if ($entry != "." && $entry != "..") {
                if ($config['config_param_vigilo_panel'] == $entry) {
                    $selected = 'selected';
                }
                echo '<option name="' . $entry . '" ' . $selected . '>' . $entry . '</option>';
            }
        }
        closedir($handle);
    }
?>
         </select>
       </td>
     </tr>
     <tr>
       <td>Langue</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_language" value="<?= $config['config_param_vigilo_language'] ?>" required />
       </td>
     </tr>
     <tr>
       <td>Clé Mapquest</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_mapquest_api" value="<?= $config['config_param_vigilo_mapquest_api'] ?>" required />
       </td>
     </tr>
     <tr>
       <td>Nombre d'heure max pour Tweeter observations</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_social_media_expiry_time" value="<?= $config['config_param_social_media_expiry_time'] ?>" required />
        </td>
      </tr>
      <tr>
        <td>Charset</td>
        <td>
          <input type="text" class="form-control-plaintext" name="config_param_mysql_charset" value="<?= $config['config_param_mysql_charset'] ?>" required />
        </td>
      </tr>
      <tr>
        <td>Timezone</td>
        <td>
          <input type="text" class="form-control-plaintext" name="config_param_vigilo_timezone" value="<?= $config['config_param_vigilo_timezone'] ?>" required />
        </td>
      </tr>
    </tbody>
  </table>
</div>
<input type="hidden" name="config_param" value="1" />
<button class="btn btn-primary" type="submit">Valider édition</button>

</form>

<br />
<?php
}
?>
