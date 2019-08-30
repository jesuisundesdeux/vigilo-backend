<?php
if (!isset($page_name)) {
  exit('Not allowed');
}

if (isset($_GET['action']) && !isset($_POST['ta_id'])) {
  if ($_GET['action'] == 'add') {
    mysqli_query($db,"INSERT INTO obs_twitteraccounts (ta_consumer,
                                                       ta_consumersecret,
                                                       ta_accesstoken,
                                                       ta_accesstokensecret)
                                               VALUES ('consumer',
                                                       'consumerkey',
                                                       'accesstoken',
                                                       'accesstokensecret')");
    echo '<div class="alert alert-success" role="alert">Compte Twitter ajouté, merci de remplir les champs correspondants</div>';
  }
  if(isset($_GET['taid']) && is_numeric($_GET['taid'])) {
    if ($_GET['action'] == 'delete') {
      $taid = mysqli_real_escape_string($db,$_GET['taid']);
      mysqli_query($db,"DELETE FROM obs_twitteraccounts WHERE ta_id = '".$taid."'");
      echo '<div class="alert alert-success" role="alert">Compte twitter <strong>'.$taid.'</strong> supprimé</div>';
    }
  }
}

if (isset($_POST['ta_id'])) {
  $update = "";
  foreach ($_POST as $key => $value) {
    if(preg_match('/ta_(?:.*)$/',$key)) {
      $key = mysqli_real_escape_string($db,$key);
      $value = mysqli_real_escape_string($db,$value);
      $update .= $key . "='".$value."',";
    }
  }
  $update = rtrim($update,',');
  $taid = mysqli_real_escape_string($db,$_POST['ta_id']);
  mysqli_query($db,"UPDATE obs_twitteraccounts SET ". $update . " WHERE ta_id='".$taid."'");

  echo '<div class="alert alert-success" role="alert">Compte Twitter <strong>'.$taid.'</strong> mis à jour</div>';
}

$query_ta = mysqli_query($db, "SELECT * FROM obs_twitteraccounts");

?>

<h2>Liste</h2>
<p><a href="?page=<?=$page_name ?>&action=add">Ajouter un compte Twitter</a></p>

<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th># ID</th>
        <th>Consumer</th>
        <th>Consumer Secret</th>
        <th>Access Token</th>
        <th>Access Token Secret</th>
        <th> </th>
        <th> </th>
      </tr>
    </thead>
    <tbody>
<?php
while ($result_ta = mysqli_fetch_array($query_ta)) {
?>
      <form action="" method="POST">
      <tr>
        <td>#<?=$result_ta['ta_id'] ?></td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_consumer" value="<?=$result_ta['ta_consumer'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_consumersecret" value="<?=$result_ta['ta_consumersecret'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_accesstoken" value="<?=$result_ta['ta_accesstoken'] ?>" required />
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="ta_accesstokensecret" value="<?=$result_ta['ta_accesstokensecret'] ?>" required />
        </td>
        <td>
          <input type="hidden" name="ta_id" value="<?=$result_ta['ta_id'] ?>" />
          <button class="btn btn-primary" type="submit">Valider édition</button>
        </td>
        <td>
          <a href="?page=<?=$page_name ?>&action=delete&taid=<?=$result_ta['ta_id'] ?>" onclick="return confirm('Merci de valider la suppression')">Supprimer</a>
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
