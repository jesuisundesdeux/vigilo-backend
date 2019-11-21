<?php
/*
Copyright (C) 2019 Velocité Montpellier

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

/* Observations functions */
function getObsIdByToken($db,$token) {
  $checktoken_query = mysqli_query($db,"SELECT obs_id FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
  if(mysqli_num_rows($checktoken_query) == 1) {
    $checktoken_result = mysqli_fetch_array($checktoken_query);
    return $checktoken_result['obs_id'];
  }
  else {
    return False;
  }
}

function isTokenExists($db,$token) {
  $checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' LIMIT 1");
  if(mysqli_num_rows($checktoken_query) == 1) {
    return True;
  }
  else {
    return False;
  }
}

function isScopeExists($db,$scope) {
  $query_scope = mysqli_query($db, "SELECT * FROM obs_scopes WHERE scope_name='".$scope."' LIMIT 1");
  $result_scope = mysqli_fetch_array($query_scope);
  if (!$result_scope) {
    return False;
  }
  return True;
}

function isTokenWithSecretId($db,$token,$secretid) {
  $checktoken_query = mysqli_query($db,"SELECT obs_token FROM obs_list WHERE obs_token='".$token."' AND obs_secretid='".$secretid."' LIMIT 1");
  if(mysqli_num_rows($checktoken_query) == 1) {
    return True;
  }
  else {
    return False;
  }
}

function deleteObs($db,$obsid) {
  mysqli_query($db,"DELETE FROM obs_list WHERE obs_token='".$obsid."' LIMIT 1");
  unlink('images/'.$token.'.jpg');
  delete_token_cache($token);
  delete_map_cache($token);
}

/* Resolutions Functions */
function getResIdByrToken($db,$rtoken) {
  $checkrtoken_query = mysqli_query($db,"SELECT resolution_id FROM obs_resolutions WHERE resolution_rtoken='".$rtoken."' LIMIT 1");
  if(mysqli_num_rows($checkrtoken_query) == 1) {
    $checkrtoken_result = mysqli_fetch_array($checkrtoken_query);
    return $checkrtoken_result['resolution_id'];
  }
  else {
    return False;
  }
}

function updateResolution($db,$fields,$resolutionid) {
  $query_update = '';
  foreach($fields as $key => $value) {
    if($key == "resolution_comment" || $key == "resolution_time" || $key == "resolution_status") {
      $query_update .= "$key = '$value',";
    }
  }
  if(!empty($query_update)) {
    $query_update = substr($query_update, 0, -1);
    mysqli_query("UPDATE obs_resolutions SET ".$query_update." WHERE resolution_id = '".$resolutionid."'");
  }
}

function addObsToResolution($db,$obsid,$resolutionid) {
  $query_obs = mysqli_query($db,"SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."' AND restok_observationid='".$obsid."' LIMIT 1");
  if (mysqli_num_rows($query_obs) != 1) {  
    mysqli_query($db,"INSERT INTO obs_resolutions_tokens (`restok_resolutionid`,`restok_observationid`) VALUES ('".$resolutionid."','".$obsid."')");
  }
}

function delObsToResolution($db,$obsid,$resolutionid) {
  mysqli_query($db,"DELETE FROM obs_resolutions_tokens restok_resolutionid='".$resolutionid."' AND restok_observationid='".$obsid."'");

  // Remove orphan resolutions
  $query_resolution = mysqli_query($db,"SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."'");
  if (mysqli_num_rows($query_obs) == 0) {
    mysqli_query("DELETE FROM obs_resolutions WHERE resolution_id='".$resolutionid."'");
  }
}

function delResolution($db,$resolutionid) {
  $query_resolution = mysqli_query("SELECT * FROM obs_resolutions_tokens WHERE restok_resolutionid='".$resolutionid."'");
  while($result_resolution = mysqli_fetch_array($query_resolution)) {
    delObsToResolution($db,$result_resolution['restok_observationid'],$resolutionid);
  }
}

function addResolution($db,$fields,$obsidlist) {
  mysqli_query($db, 'INSERT INTO obs_resolutions (
                                  `resolution_rtoken`,
                                  `resolution_secretid`,
                                  `resolution_app_version`,
                                  `resolution_comment`,
                                  `resolution_time`,
                                  `resolution_status`);
                           VALUES (
                               "'.$fields['resolution_rtoken'].'",
                               "'.$fields['resolution_secretid'].'",
                               "'.$fields['resolution_app_version'].'",
                               "'.$fields['resolution_comment'].'",
                               "'.$fields['resolution_time'].'",
                               "'.$fields['resolution_status'].'")');

  $resolution_id = mysqli_insert_id($db);
  
  foreach($obsidlist as $obs) {
    addObsToResolution($db,$obs,$resolution_id);
  }
}


function isrTokenWithSecretId($db,$rtoken,$secretid) {
  $checkrtoken_query = mysqli_query($db,"SELECT resolution_id FROM obs_resolutions WHERE resolution_rtoken='".$rtoken."' AND resolution_secretid='".$secretid."' LIMIT 1");
  if(mysqli_num_rows($checkrtoken_query) == 1) {
    return True;
  }
  else {
    return False;
  }
}

