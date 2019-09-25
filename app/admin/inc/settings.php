<?php
if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'],$menu[$page_name]['access']))) {
  exit('Not allowed');
}


if (isset($_POST['config_param'])) {
  foreach ($_POST as $key => $value) {
    if (preg_match('/config_param_(?:.*)$/',$key)) {
      $key = str_replace('config_param_','',mysqli_real_escape_string($db,$key));
      $value = mysqli_real_escape_string($db,$value);

      $update = "UPDATE obs_config SET config_value='".$value."' WHERE config_param='".$key."'";
      mysqli_query($db,$update);
    }
  }

  echo '<div class="alert alert-success" role="alert">Configuration mise à jour</div>';
}

$query_config = mysqli_query($db, "SELECT * FROM obs_config");
while ($result_config = mysqli_fetch_array($query_config)) {
  $param = $result_config['config_param'];
  $config['config_param_'.$param] = $result_config['config_value'];
}
?>

<h2>Liste</h2>

<form action="" method="POST">

<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th>Paramètre</th>
        <th>Valeur</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>URL base</td>
        <td>
          <input type="text" class="form-control-plaintext" name="config_param_vigilo_urlbase" value="<?=$config['config_param_vigilo_urlbase'] ?>" required />
        </td>
  	  </tr>
     <tr>
       <td>Protocole d'accès</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_http_proto" value="<?=$config['config_param_vigilo_http_proto'] ?>" required />
       </td>
     </tr>
     <tr>
       <td>Nom instance</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_name" value="<?=$config['config_param_vigilo_name'] ?>" required />
       </td>
     </tr>
     <tr>
       <td>Langue</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_language" value="<?=$config['config_param_vigilo_language'] ?>" required />
       </td>
     </tr>
     <tr>
       <td>Clé Mapquest</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_vigilo_mapquest_api" value="<?=$config['config_param_vigilo_mapquest_api'] ?>" required />
       </td>
     </tr>
     <tr>
       <td>Nombre d'heure max pour Tweeter observations</td>
       <td>
         <input type="text" class="form-control-plaintext" name="config_param_twitter_expiry_time" value="<?=$config['config_param_twitter_expiry_time'] ?>" required />
        </td>
      </tr>
      <tr>
        <td>Charset</td>
        <td>
          <input type="text" class="form-control-plaintext" name="config_param_mysql_charset" value="<?=$config['config_param_mysql_charset'] ?>" required />
        </td>
      </tr>
      <tr>
        <td>Timezone</td>
        <td>
          <input type="text" class="form-control-plaintext" name="config_param_vigilo_timezone" value="<?=$config['config_param_vigilo_timezone'] ?>" required />
        </td>
      </tr>
    </tbody>
  </table>
</div>
<input type="hidden" name="config_param" value="1" />
<button class="btn btn-primary" type="submit">Valider édition</button>

</form>

<br />
