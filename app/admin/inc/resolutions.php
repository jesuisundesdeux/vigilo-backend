<?php
if (!isset($page_name) || (isset($_SESSION['role']) && !in_array($_SESSION['role'],$menu[$page_name]['access']))) {
  exit('Not allowed');
}

/* Defines acls for this page used by roles */
$actions_acl = array("delete" => array("access" => array('admin')),
                     "resolve" => array("access" => array('admin','citystaff')),
                     "approve" => array("access" => array('admin')),
                     "cleancache" => array("access" => array('admin')),
                     "edit" => array("access" => array('admin')));

$query_resolution = mysqli_query($db,"SELECT * FROM obs_resolutions");


?>
<h2>Liste</h2>
<div class="table-responsive">
  <table class="table table-striped table-sm">
    <thead>
      <tr>
        <th width="100px">Token</th>
        <th width="150px">Photo</th>
        <th width="100px">Date / Heure</th>
        <th width="300px"> </th>
      </tr>
    </thead>
    <tbody>
<?php
while ($result_resolution = mysqli_fetch_array($query_resolution)) {
$date = date('d/m/Y',$result_resolution['resolution_time']);
$heure = date('H:i',$result_resolution['resolution_time']);
?>
      <form action="?page=resolutions<?=$urlsuffix ?>" method="POST">
      <tr>
        <td><?=$result_resolution['resolution_token'] ?></td>
        <td>
          <a href="/get_photo.php?rtoken=<?=$result_resolution['resolution_token'] ?>" target="_blank"><img width="200px" src="/get_photo.php?rtoken=<?=$result_resolution['resolution_token'] ?>" /></a>
        </td>
        <td>
          <label for="obs_comment"><strong>Commentaire</strong></label>
    <input type="text" class="form-control-plaintext" name="obs_comment" value="<?=$result_resolution['resolution_comment'] ?>" />
          <?php
           $observations_query = mysqli_query($db,"SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='".$result_resolution['resolution_id']."'");
           while($observations_result = mysqli_fetch_array($observations_query)) {
             echo $observations_result['restok_observationid'] . '/';
     }
        ?>
        </td>
        <td>
          <input type="text" class="form-control-plaintext" name="post_date" value="<?=$date ?>" required />
          <input type="text" class="form-control-plaintext" name="post_heure" value="<?=$heure ?>" required />
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
