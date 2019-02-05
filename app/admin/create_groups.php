<?php
require_once('./common.php');
require_once('./functions.php');

// Script to map or create obs gorups

$query = mysqli_query($db,"SELECT * FROM obs_list");
while($result = mysqli_fetch_array($query)) {
  $categorie = $result['obs_categorie'];
  $coordinates_lat = $result['obs_coordinates_lat'];
  $coordinates_lon = $result['obs_coordinates_lon'];
  $address = $result['obs_address_string'];
  $id = $result['obs_id'];

  $group_id = 0;
  $group_query = mysqli_query($db,"SELECT * FROM obs_groups");
  while($group_result = mysqli_fetch_array($group_query)) {
      if($group_result['group_categorie'] == $categorie && $group_result['group_address_string'] == $address) {
        $group_id = $group_result['group_id'];
        break;
      }
      elseif($group_result['group_categorie'] == $categorie && distance($group_result['group_coordinates_lat'], $group_result['group_coordinates_lon'], $coordinates_lat, $coordinates_lon, $unit = 'm') < 50) {
        $group_id = $group_result['group_id'];
        break;
      }
  }
  if($group_id == 0) {
    mysqli_query($db,'INSERT INTO obs_groups (`group_address_string`,`group_coordinates_lat`,`group_coordinates_lon`,`group_categorie`) VALUES
      ("'.$address.'","'.$coordinates_lat.'","'.$coordinates_lon.'","'.$categorie.'")') ;
    $group_id = mysqli_insert_id($db);
  }

  mysqli_query($db,'UPDATE obs_list SET obs_group="'.$group_id.'" WHERE obs_id="'.$id.'"');
}
