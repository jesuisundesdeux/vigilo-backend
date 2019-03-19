<?php
require_once('../common.php');
require_once('../functions.php');

# Get issue information
$approve = intval($_GET["approve"]);
$token=$_GET["token"];

if (isset($_GET["approve"])) {
  $query = mysqli_query($db, "UPDATE obs_list set obs_approved=$approve WHERE obs_token='" . $token . "'");
  delete_token_cache($token);

  echo 'DONE';
}
