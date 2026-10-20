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

/* Defines acls for this page used by roles */
$actions_acl = array("delete" => array("access" => array('admin')),
                     "resolve" => array("access" => array('admin','citystaff')),
                     "approve" => array("access" => array('admin')),
                     "cleancache" => array("access" => array('admin')),
                     "archive" => array("access" => array('admin')),
                     "edit" => array("access" => array('admin')),
                     "notes" => array("access" => array('admin','citystaff')));

require_once(__DIR__ . '/../../includes/addresses.php');

$current_role  = $_SESSION['role'];
$current_login = isset($_SESSION['login']) ? (string) $_SESSION['login'] : '';
$is_admin      = ($current_role == 'admin');

$urlsuffix = "";
$messages  = array();

function obsadmin_message(&$messages, $type, $html)
{
  $messages[] = array('type' => $type, 'html' => $html);
}

/* Cities of the current citystaff account (#240): used to filter the list and to restrict actions */
$role_cityIds = ($current_role == 'citystaff') ? getRoleCityIds($db, $current_login) : array();
if ($current_role == 'citystaff' && empty($role_cityIds)) {
  echo '<div class="alert alert-info">Aucune ville n\'est associée à votre compte : demandez à un administrateur de vous en attribuer.</div>';
}

/* Can the current account act on this observation (row of obs_list)? Admins: always; citystaff: only on their cities */
function obsadmin_can_act($obs, $role, $role_cityIds)
{
  if ($role == 'admin') {
    return true;
  }
  if ($role == 'citystaff' && is_array($obs) && isset($obs['obs_city'])) {
    return in_array(intval($obs['obs_city']), $role_cityIds, true);
  }
  return false;
}

function obsadmin_get_obs($db, $obsid)
{
  $query = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_id='" . intval($obsid) . "' LIMIT 1");
  if (!$query) {
    return null;
  }
  $row = mysqli_fetch_array($query);
  return $row ? $row : null;
}

/* Approval state of an observation (0 to qualify, 1 approved, 2 disapproved), with the webhooks */
function obsadmin_approve($db, $obs, $approveto, &$messages)
{
  $token = (string) $obs['obs_token'];
  delete_token_cache($token);
  $approved_before = webhook_approval_state($db, $token);
  mysqli_query($db, "UPDATE obs_list SET obs_approved='" . intval($approveto) . "' WHERE obs_id='" . intval($obs['obs_id']) . "'");
  audit_log('observation_approve', $token, array('approved' => intval($approveto)));
  // Published: attached to the matching active problem, if only one (#327)
  if (intval($approveto) === 1 && $approved_before !== 1) {
    $problem = problems_on_publication($db, $token, isset($_SESSION['login']) ? $_SESSION['login'] : '', 'admin');
    if ($problem[0] == 'attached' || $problem[0] == 'ambiguous') {
      $titles = problem_titles($db);
      $names  = array();
      foreach ($problem[1] as $problem_id) {
        $names[] = '<a href="?page=problems&amp;view=' . intval($problem_id) . '">' . h(isset($titles[$problem_id]) ? $titles[$problem_id] : '#' . $problem_id) . '</a>';
      }
      obsadmin_message($messages, 'info', $problem[0] == 'attached'
        ? 'Observation <strong>' . h($token) . '</strong> rattachée au problème ' . $names[0] . '.'
        : 'Observation <strong>' . h($token) . '</strong> : plusieurs problèmes correspondent (' . implode(', ', $names) . '), à arbitrer dans la page Problèmes.');
    }
  }
  foreach (webhooks_on_approval($db, $token, $approved_before, $approveto) as $hook_id => $delivery) {
    if ($delivery[1] !== '') {
      obsadmin_message($messages, 'warning', 'Webhook <strong>#' . intval($hook_id) . '</strong> en échec pour <strong>' . h($token) . '</strong> : ' . h($delivery[1]) . ' (voir la page Webhooks).');
    }
  }
}

/* Archived observations (#archive): kept for the statistics, no longer listed by get_issues.php */
function obsadmin_archive($db, $obs, $archiveto)
{
  mysqli_query($db, "UPDATE obs_list SET obs_archived='" . ($archiveto ? 1 : 0) . "' WHERE obs_id='" . intval($obs['obs_id']) . "'");
  audit_log($archiveto ? 'observation_archive' : 'observation_unarchive', (string) $obs['obs_token']);
}

/* Day of a date field (YYYY-MM-DD) as a timestamp (start or end of the day), False if invalid */
function obsadmin_day($value, $end)
{
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
    return False;
  }
  return strtotime($value . ($end ? ' 23:59:59' : ' 00:00:00'));
}

/* <option>s of the resolutions, grouped by status (<optgroup>) */
function obsadmin_resolution_options($groups, $options)
{
  $html = '';
  foreach ($groups as $status => $label) {
    if (!empty($options[$status])) {
      $html .= '<optgroup label="' . h($label) . '">';
      foreach ($options[$status] as $id => $text) {
        $html .= '<option value="' . intval($id) . '">' . h($text) . '</option>';
      }
      $html .= '</optgroup>';
    }
  }
  return $html;
}

/* Webhooks of a resolution created in the admin */
function obsadmin_resolution_created($db, $resolution_token, &$messages)
{
  $resolutionid = getResolutionIdByResolutionToken(mysqli_real_escape_string($db, $resolution_token));
  if ($resolutionid === False) {
    return;
  }
  foreach (webhooks_for_resolution($db, VIGILO_WEBHOOK_EVENT_RESOLUTION_CREATED, $resolutionid) as $hook_id => $delivery) {
    if ($delivery[1] !== '') {
      obsadmin_message($messages, 'warning', 'Webhook <strong>#' . intval($hook_id) . '</strong> en échec pour <strong>' . h($resolution_token) . '</strong> : ' . h($delivery[1]) . ' (voir la page Webhooks).');
    }
  }
}

/* Actions links */
if (isset($_GET['action']) && isset($_GET['obsid']) && is_numeric($_GET['obsid']) && !isset($_POST['obs_id'])) {
  $action = (string) $_GET['action'];
  $obsid  = intval($_GET['obsid']);
  $obs    = obsadmin_get_obs($db, $obsid);

  if ($obs === null) {
    obsadmin_message($messages, 'warning', 'Observation <strong>' . h($obsid) . '</strong> introuvable');
  }
  elseif (!obsadmin_can_act($obs, $current_role, $role_cityIds)) {
    obsadmin_message($messages, 'danger', 'Action non autorisée sur une observation hors de vos villes');
  }
  else {
    // The token always comes from the database, never from the URL
    $token = (string) $obs['obs_token'];

    if ($action == 'delete' && in_array($current_role, $actions_acl['delete']['access'])) {
      deleteObs($obsid);
      audit_log('observation_delete', $token, array('obs_id' => $obsid));
      obsadmin_message($messages, 'success', 'Observation <strong>' . h($token) . '</strong> supprimée');
    }
    elseif ($action == 'approve' && in_array($current_role, $actions_acl['approve']['access'])) {
      $approveto = 1;
      if (isset($_GET['approveto']) && in_array((string) $_GET['approveto'], array('0', '1', '2'), true)) {
        $approveto = intval($_GET['approveto']);
      }

      obsadmin_approve($db, $obs, $approveto, $messages);
      obsadmin_message($messages, 'success', 'Observation <strong>' . h($token) . '</strong> ' . ($approveto == 1 ? 'approuvée' : ($approveto == 2 ? 'désapprouvée' : 'remise à qualifier')));
    }
    elseif ($action == 'archive' && in_array($current_role, $actions_acl['archive']['access'])) {
      $archiveto = (isset($_GET['archiveto']) && (string) $_GET['archiveto'] === '0') ? 0 : 1;
      obsadmin_archive($db, $obs, $archiveto);
      obsadmin_message($messages, 'success', 'Observation <strong>' . h($token) . '</strong> ' . ($archiveto ? 'archivée' : 'désarchivée'));
    }
    elseif ($action == 'cleancache' && in_array($current_role, $actions_acl['cleancache']['access'])) {
      delete_token_cache($token);
      audit_log('observation_cleancache', $token);
      obsadmin_message($messages, 'success', 'Cache de l\'observation <strong>' . h($token) . '</strong> effacé');
    }
    elseif ($action == 'resolve' && in_array($current_role, $actions_acl['resolve']['access'])) {
      $fields = array("resolution_token" => 'R_' . tokenGenerator(4),
                      "resolution_secretid" => str_replace('.', '', uniqid('', true)),
                      "resolution_app_version" => 'admin',
                      "resolution_comment" => '',
                      "resolution_time" => 0,
                      "resolution_status" => 2);
      $obsidlist = array($obsid);
      if (addResolution($fields, $obsidlist)) {
        audit_log('resolution_create', $fields['resolution_token'], array('obs_id' => $obsid, 'obs_token' => $token));
        obsadmin_resolution_created($db, $fields['resolution_token'], $messages);
        obsadmin_message($messages, 'success', 'Résolution <strong>' . h($fields['resolution_token']) . '</strong> ajoutée, et modifiable <a href="?page=resolutions" class="alert-link">ici</a>');
      }
    }
    else {
      obsadmin_message($messages, 'danger', 'Action non autorisée');
    }
  }
}

/* Edit form */
if (isset($_POST['obs_id']) && in_array($current_role, $actions_acl['edit']['access'])) {
  $obsid = intval($_POST['obs_id']);
  $obs   = obsadmin_get_obs($db, $obsid);
  $obstime = parseAdminDateTime(isset($_POST['post_date']) ? (string) $_POST['post_date'] : '', isset($_POST['post_heure']) ? (string) $_POST['post_heure'] : '');

  if ($obs === null) {
    obsadmin_message($messages, 'warning', 'Observation <strong>' . h($obsid) . '</strong> introuvable');
  }
  elseif (!obsadmin_can_act($obs, $current_role, $role_cityIds)) {
    obsadmin_message($messages, 'danger', 'Action non autorisée sur une observation hors de vos villes');
  }
  elseif ($obstime === False) {
    obsadmin_message($messages, 'danger', 'Format de date incorrect');
  }
  else {
    $update  = array("obs_time='" . intval($obstime) . "'");
    $changes = array();
    if (intval($obs['obs_time']) != intval($obstime)) {
      $changes['obs_time'] = intval($obstime);
    }

    /* Only these columns can be edited: field names are never taken from the request */
    $editable_fields = array('obs_comment' => 'string',
                             'obs_explanation' => 'string',
                             'obs_categorie' => 'int',
                             'obs_address_string' => 'string',
                             'obs_cityname' => 'string',
                             'obs_city' => 'int');
    foreach ($editable_fields as $key => $type) {
      if (isset($_POST[$key]) && is_scalar($_POST[$key])) {
        if ($type == 'int') {
          $value = intval($_POST[$key]);
          $update[] = $key . "='" . $value . "'";
        }
        else {
          $value = (string) $_POST[$key];
          $update[] = $key . "='" . mysqli_real_escape_string($db, $value) . "'";
        }
        if (!isset($obs[$key]) || (string) $obs[$key] !== (string) $value) {
          $changes[$key] = $value;
        }
      }
    }

    mysqli_query($db, "UPDATE obs_list SET " . implode(',', $update) . " WHERE obs_id='" . $obsid . "'");

    if (isset($_POST['resolution_add']) && is_numeric($_POST['resolution_add']) && intval($_POST['resolution_add']) > 0) {
      $resolution_add = intval($_POST['resolution_add']);
      $resolution_check = mysqli_query($db, "SELECT resolution_id FROM obs_resolutions WHERE resolution_id='" . $resolution_add . "' LIMIT 1");
      if ($resolution_check && mysqli_num_rows($resolution_check) == 1) {
        addObsToResolution($obsid, $resolution_add);
        $changes['resolution_add'] = $resolution_add;
      }
    }

    audit_log('observation_edit', $obs['obs_token'], $changes);
    obsadmin_message($messages, 'success', 'Observation <strong>' . h($obs['obs_token']) . '</strong> mise à jour');
  }
}

/* Moderator notes (#266): private, never exposed by the public API */
if (isset($_POST['note_action']) && in_array($current_role, $actions_acl['notes']['access'])) {
  $note_action = (string) $_POST['note_action'];

  if ($note_action == 'add') {
    $note_obsid = isset($_POST['note_obsid']) ? intval($_POST['note_obsid']) : 0;
    $note_text  = isset($_POST['note_text']) && is_scalar($_POST['note_text']) ? trim((string) $_POST['note_text']) : '';
    $obs = obsadmin_get_obs($db, $note_obsid);

    if ($obs === null) {
      obsadmin_message($messages, 'warning', 'Observation introuvable');
    }
    elseif (!obsadmin_can_act($obs, $current_role, $role_cityIds)) {
      obsadmin_message($messages, 'danger', 'Action non autorisée sur une observation hors de vos villes');
    }
    elseif ($note_text === '') {
      obsadmin_message($messages, 'warning', 'La note est vide');
    }
    else {
      $note_text = vigilo_truncate($note_text, 5000);
      mysqli_query($db, "INSERT INTO obs_notes (note_obsid, note_time, note_login, note_text)
                         VALUES ('" . $note_obsid . "', '" . time() . "',
                                 '" . mysqli_real_escape_string($db, $current_login) . "',
                                 '" . mysqli_real_escape_string($db, $note_text) . "')");
      audit_log('note_add', $obs['obs_token'], array('note_id' => intval(mysqli_insert_id($db))));
      obsadmin_message($messages, 'success', 'Note ajoutée à l\'observation <strong>' . h($obs['obs_token']) . '</strong>');
    }
  }
  elseif ($note_action == 'delete') {
    $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
    // Notes of a problem (note_problemid) are managed in the page Problèmes
    $note_query = mysqli_query($db, "SELECT * FROM obs_notes WHERE note_id='" . $note_id . "' AND note_problemid = 0 LIMIT 1");
    $note = $note_query ? mysqli_fetch_array($note_query) : null;

    if (!$note) {
      obsadmin_message($messages, 'warning', 'Note introuvable');
    }
    else {
      $obs = obsadmin_get_obs($db, $note['note_obsid']);
      $is_author = ($current_login !== '' && (string) $note['note_login'] === $current_login);
      if (!$is_admin && !($is_author && obsadmin_can_act($obs, $current_role, $role_cityIds))) {
        obsadmin_message($messages, 'danger', 'Seul un administrateur ou l\'auteur peut supprimer cette note');
      }
      else {
        mysqli_query($db, "DELETE FROM obs_notes WHERE note_id='" . $note_id . "' LIMIT 1");
        audit_log('note_delete', $obs ? $obs['obs_token'] : '', array('note_id' => $note_id, 'note_login' => $note['note_login'], 'note_text' => $note['note_text']));
        obsadmin_message($messages, 'success', 'Note supprimée');
      }
    }
  }
}

/* Observations cities check */
$city_query = mysqli_query($db, "SELECT * FROM obs_cities ORDER BY city_name");
$citylistname = array();

while ($city_query && $city_result = mysqli_fetch_array($city_query)) {
  $cityid = intval($city_result['city_id']);
  $citylistname[$cityid] = $city_result['city_name'];
}

/*
 * Quality of the addresses (includes/addresses.php): corrections proposed for the address and the
 * city of the observations (city in the address, free city name of a referenced city, postcode,
 * country, spaces, same street written differently), applied after review; the observations still
 * without a city are listed (city not referenced, no city in the address).
 */
$obswithoutcity = array("pbaddress" => array(), "cityunknown" => array());
$address_proposals = array();
$address_cities = array();
if ($city_query) {
  mysqli_data_seek($city_query, 0);
  while ($city_result = mysqli_fetch_array($city_query)) {
    $address_cities[intval($city_result['city_id'])] = array('name' => (string) $city_result['city_name'], 'postcode' => (string) $city_result['city_postcode']);
  }
}

if (in_array($current_role, $actions_acl['edit']['access'])) {
  $address_rows = array();
  $address_query = mysqli_query($db, "SELECT obs_id, obs_token, obs_address_string, obs_city, obs_cityname FROM obs_list WHERE obs_complete=1");
  while ($address_query && $row = mysqli_fetch_assoc($address_query)) {
    $address_rows[intval($row['obs_id'])] = $row;
  }
  $address_proposals = address_cleanup_proposals($address_rows, $address_cities);

  /* Corrections checked in the list: computed again here, never taken from the request */
  if (isset($_POST['address_fix'])) {
    $fix_ids = isset($_POST['fix_ids']) && is_array($_POST['fix_ids']) ? array_unique(array_map('intval', $_POST['fix_ids'])) : array();
    $fixed = array();
    foreach ($fix_ids as $fix_id) {
      if (!isset($address_proposals[$fix_id])) {
        continue;
      }
      $after = $address_proposals[$fix_id]['after'];
      mysqli_query($db, "UPDATE obs_list SET obs_address_string='" . mysqli_real_escape_string($db, $after['address']) . "',
                         obs_city='" . intval($after['city']) . "', obs_cityname='" . mysqli_real_escape_string($db, $after['cityname']) . "'
                         WHERE obs_id='" . intval($fix_id) . "'");
      delete_token_cache($address_proposals[$fix_id]['token']);
      $fixed[$address_proposals[$fix_id]['token']] = array($address_proposals[$fix_id]['before'], $after);
      $address_rows[$fix_id]['obs_address_string'] = $after['address'];
      $address_rows[$fix_id]['obs_city'] = $after['city'];
      $address_rows[$fix_id]['obs_cityname'] = $after['cityname'];
      unset($address_proposals[$fix_id]);
    }
    if ($fixed) {
      audit_log('observation_address_fix', '', array('count' => count($fixed), 'changes' => array_slice($fixed, 0, 100, true)));
      $address_proposals = address_cleanup_proposals($address_rows, $address_cities);
    }
    obsadmin_message($messages, $fixed ? 'success' : 'warning', '<strong>' . count($fixed) . '</strong> adresse(s) corrigée(s)');
  }

  // Still without a referenced city once corrected
  foreach ($address_rows as $obs_id => $row) {
    $after = isset($address_proposals[$obs_id]) ? $address_proposals[$obs_id]['after'] : array('city' => intval($row['obs_city']), 'cityname' => (string) $row['obs_cityname']);
    if (intval($after['city']) == 0) {
      $obswithoutcity[trim($after['cityname']) !== '' ? 'cityunknown' : 'pbaddress'][] = (string) $row['obs_token'];
    }
  }
}

/*
 * Bulk actions on the checked observations (bulk_ids[]): each observation is checked like a
 * single action (role, cities of a citystaff).
 */
$bulk_actions = array('approve' => array('acl' => 'approve', 'label' => 'Approuver'),
                      'disapprove' => array('acl' => 'approve', 'label' => 'Désapprouver'),
                      'pending' => array('acl' => 'approve', 'label' => 'Remettre à qualifier'),
                      'category' => array('acl' => 'edit', 'label' => 'Changer la catégorie'),
                      'city' => array('acl' => 'edit', 'label' => 'Changer la ville'),
                      'resolution_new' => array('acl' => 'resolve', 'label' => 'Nouvelle résolution regroupant la sélection'),
                      'resolution_add' => array('acl' => 'edit', 'label' => 'Ajouter à une résolution existante'),
                      'archive' => array('acl' => 'archive', 'label' => 'Archiver'),
                      'unarchive' => array('acl' => 'archive', 'label' => 'Désarchiver'),
                      'cleancache' => array('acl' => 'cleancache', 'label' => 'Effacer le cache'),
                      'delete' => array('acl' => 'delete', 'label' => 'Supprimer'));
foreach ($bulk_actions as $bulk_key => $bulk_action) {
  if (!in_array($current_role, $actions_acl[$bulk_action['acl']]['access'])) {
    unset($bulk_actions[$bulk_key]);
  }
}
$bulk_categories = array();
foreach (getCategoriesList() as $categorie) {
  if (isset($categorie['catid']) && empty($categorie['catdisable'])) {
    $bulk_categories[intval($categorie['catid'])] = (string) $categorie['catname'];
  }
}

if (isset($_POST['bulk_action'])) {
  $bulk_key = (string) $_POST['bulk_action'];
  $bulk_ids = array();
  if (isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
    $bulk_ids = array_slice(array_unique(array_map('intval', $_POST['bulk_ids'])), 0, 1000);
  }
  $bulk_value = isset($_POST['bulk_value_' . $bulk_key]) && is_scalar($_POST['bulk_value_' . $bulk_key]) ? intval($_POST['bulk_value_' . $bulk_key]) : 0;
  $bulk_check = mysqli_query($db, "SELECT resolution_id FROM obs_resolutions WHERE resolution_id='" . $bulk_value . "' LIMIT 1");
  $bulk_resolution_ok = $bulk_check && mysqli_num_rows($bulk_check) == 1;
  $bulk_obs = array();
  $refused  = 0;
  foreach ($bulk_ids as $bulk_id) {
    $obs = obsadmin_get_obs($db, $bulk_id);
    if ($obs === null) {
      continue;
    }
    if (obsadmin_can_act($obs, $current_role, $role_cityIds)) {
      $bulk_obs[] = $obs;
    } else {
      $refused++;
    }
  }

  if (!isset($bulk_actions[$bulk_key])) {
    obsadmin_message($messages, 'danger', 'Action non autorisée');
  } elseif (count($bulk_obs) == 0) {
    obsadmin_message($messages, 'warning', 'Aucune observation sélectionnée' . ($refused ? ' dans vos villes' : ''));
  } elseif ($bulk_key == 'category' && !isset($bulk_categories[$bulk_value])) {
    obsadmin_message($messages, 'warning', 'Choisir une catégorie active.');
  } elseif ($bulk_key == 'city' && !isset($citylistname[$bulk_value])) {
    obsadmin_message($messages, 'warning', 'Choisir une ville.');
  } elseif ($bulk_key == 'resolution_add' && !$bulk_resolution_ok) {
    obsadmin_message($messages, 'warning', 'Choisir une résolution.');
  } else {
    $done    = array();
    $skipped = 0;
    foreach ($bulk_obs as $obs) {
      $obsid = intval($obs['obs_id']);
      $token = (string) $obs['obs_token'];
      if ($bulk_key == 'approve' || $bulk_key == 'disapprove' || $bulk_key == 'pending') {
        obsadmin_approve($db, $obs, $bulk_key == 'approve' ? 1 : ($bulk_key == 'disapprove' ? 2 : 0), $messages);
      } elseif ($bulk_key == 'category') {
        mysqli_query($db, "UPDATE obs_list SET obs_categorie='" . $bulk_value . "' WHERE obs_id='" . $obsid . "'");
        delete_token_cache($token);
        audit_log('observation_edit', $token, array('obs_categorie' => $bulk_value));
      } elseif ($bulk_key == 'city') {
        mysqli_query($db, "UPDATE obs_list SET obs_city='" . $bulk_value . "', obs_cityname='' WHERE obs_id='" . $obsid . "'");
        audit_log('observation_edit', $token, array('obs_city' => $bulk_value));
      } elseif ($bulk_key == 'resolution_add' || $bulk_key == 'resolution_new') {
        $in_resolution = mysqli_query($db, "SELECT restok_observationid FROM obs_resolutions_tokens WHERE restok_observationid='" . $obsid . "' LIMIT 1");
        if ($in_resolution && mysqli_num_rows($in_resolution) > 0) {
          $skipped++;
          continue;
        }
        if ($bulk_key == 'resolution_add') {
          addObsToResolution($obsid, $bulk_value);
          audit_log('resolution_add_observation', 'resolution:' . $bulk_value, 'obs:' . $token);
        }
      } elseif ($bulk_key == 'archive' || $bulk_key == 'unarchive') {
        obsadmin_archive($db, $obs, $bulk_key == 'archive' ? 1 : 0);
      } elseif ($bulk_key == 'cleancache') {
        delete_token_cache($token);
        audit_log('observation_cleancache', $token);
      } elseif ($bulk_key == 'delete') {
        deleteObs($obsid);
        audit_log('observation_delete', $token, array('obs_id' => $obsid));
      }
      $done[$obsid] = $token;
    }
    if ($bulk_key == 'resolution_new' && count($done) > 0) {
      $fields = array("resolution_token" => 'R_' . tokenGenerator(4),
                      "resolution_secretid" => str_replace('.', '', uniqid('', true)),
                      "resolution_app_version" => 'admin',
                      "resolution_comment" => '',
                      "resolution_time" => 0,
                      "resolution_status" => 2);
      if (addResolution($fields, array_keys($done))) {
        audit_log('resolution_create', $fields['resolution_token'], array('obs_tokens' => array_values($done)));
        obsadmin_resolution_created($db, $fields['resolution_token'], $messages);
        obsadmin_message($messages, 'success', 'Résolution <strong>' . h($fields['resolution_token']) . '</strong> créée avec ' . count($done) . ' observation(s), modifiable <a href="?page=resolutions" class="alert-link">ici</a>');
      }
    } elseif ($bulk_key == 'resolution_add' && count($done) > 0) {
      flushImagesCacheResolution($bulk_value);
    }
    if ($bulk_key != 'resolution_new') {
      obsadmin_message($messages, 'success', h($bulk_actions[$bulk_key]['label']) . ' : <strong>' . count($done) . '</strong> observation(s)');
    }
    if ($skipped > 0) {
      obsadmin_message($messages, 'warning', $skipped . ' observation(s) déjà dans une résolution ignorée(s)');
    }
  }
  if ($refused > 0) {
    obsadmin_message($messages, 'danger', $refused . ' observation(s) hors de vos villes ignorée(s)');
  }
}

/*
 * Archiving by period: the observations of a period (date of the observation), optionally of one
 * category, are archived after a preview of their number (archive_step: preview, then confirm).
 */
$archive_categories = array();
foreach (getCategoriesList() as $categorie) {
  if (isset($categorie['catid'])) {
    $archive_categories[intval($categorie['catid'])] = (string) $categorie['catname'];
  }
}
$archive_form = array('from' => '', 'to' => '', 'category' => 0);
if (isset($_POST['archive_step']) && in_array($current_role, $actions_acl['archive']['access'])) {
  $archive_form['from'] = isset($_POST['archive_from']) && is_scalar($_POST['archive_from']) ? trim((string) $_POST['archive_from']) : '';
  $archive_form['to'] = isset($_POST['archive_to']) && is_scalar($_POST['archive_to']) ? trim((string) $_POST['archive_to']) : '';
  $archive_form['category'] = isset($_POST['archive_category']) && is_scalar($_POST['archive_category']) ? intval($_POST['archive_category']) : 0;
  $archive_from = ($archive_form['from'] === '') ? 0 : obsadmin_day($archive_form['from'], false);
  $archive_to = obsadmin_day($archive_form['to'], true);

  if ($archive_from === False || $archive_to === False) {
    obsadmin_message($messages, 'warning', 'Archivage : indiquer une date de fin valide (et une date de début valide si elle est renseignée).');
  } elseif ($archive_from > $archive_to) {
    obsadmin_message($messages, 'warning', 'Archivage : la date de début est après la date de fin.');
  } elseif ($archive_form['category'] != 0 && !isset($archive_categories[$archive_form['category']])) {
    obsadmin_message($messages, 'warning', 'Archivage : catégorie inconnue.');
  } else {
    $archive_where = "obs_complete=1 AND obs_archived=0 AND obs_time >= " . intval($archive_from) . " AND obs_time <= " . intval($archive_to)
                   . ($archive_form['category'] != 0 ? " AND obs_categorie='" . intval($archive_form['category']) . "'" : '');
    $archive_count_query = mysqli_query($db, "SELECT COUNT(*) FROM obs_list WHERE " . $archive_where);
    $archive_count = $archive_count_query ? intval(mysqli_fetch_array($archive_count_query)[0]) : 0;
    $archive_what = ($archive_form['from'] !== '' ? 'du ' . h(date('d/m/Y', $archive_from)) . ' ' : 'jusqu\'') . 'au ' . h(date('d/m/Y', $archive_to))
                  . ($archive_form['category'] != 0 ? ', catégorie <strong>' . h($archive_categories[$archive_form['category']]) . '</strong>' : ', toutes catégories');

    if ($archive_count == 0) {
      obsadmin_message($messages, 'info', 'Archivage : aucune observation à archiver ' . $archive_what . '.');
    } elseif ((string) $_POST['archive_step'] === 'confirm') {
      mysqli_query($db, "UPDATE obs_list SET obs_archived=1 WHERE " . $archive_where);
      $archived_count = intval(mysqli_affected_rows($db));
      audit_log('observation_archive_period', '', array('from' => $archive_form['from'], 'to' => $archive_form['to'],
                                                         'category' => $archive_form['category'], 'count' => $archived_count));
      obsadmin_message($messages, 'success', '<strong>' . $archived_count . '</strong> observation(s) archivée(s) ' . $archive_what . '.');
      $archive_form = array('from' => '', 'to' => '', 'category' => 0);
    } else {
      // Preview: the number of observations, and a button to confirm
      obsadmin_message($messages, 'warning', '<strong>' . $archive_count . '</strong> observation(s) à archiver ' . $archive_what . '.'
        . '<form method="POST" action="" class="mt-2 mb-0">' . csrf_field()
        . '<input type="hidden" name="archive_step" value="confirm" />'
        . '<input type="hidden" name="archive_from" value="' . h($archive_form['from']) . '" />'
        . '<input type="hidden" name="archive_to" value="' . h($archive_form['to']) . '" />'
        . '<input type="hidden" name="archive_category" value="' . intval($archive_form['category']) . '" />'
        . '<button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-archive"></i> Archiver ces ' . $archive_count . ' observation(s)</button></form>');
    }
  }
}

/* Messages of the actions */
foreach ($messages as $message) {
  echo '<div class="alert alert-' . h($message['type']) . ' alert-dismissible fade show" role="alert">' . $message['html']
     . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
}

$address_reasons = address_reason_labels();
$address_counts = array();
foreach ($address_proposals as $proposal) {
  foreach ($proposal['reasons'] as $reason) {
    $address_counts[$reason] = (isset($address_counts[$reason]) ? $address_counts[$reason] : 0) + 1;
  }
}

/* Corrections of the addresses: reviewed then applied (page=observations&addresses=1) */
if (isset($_GET['addresses']) && in_array($current_role, $actions_acl['edit']['access'])) {
  $address_reason = isset($_GET['reason']) && is_string($_GET['reason']) && isset($address_reasons[$_GET['reason']]) ? $_GET['reason'] : '';
  $shown = array();
  foreach ($address_proposals as $obs_id => $proposal) {
    if ($address_reason === '' || in_array($address_reason, $proposal['reasons'], true)) {
      $shown[$obs_id] = $proposal;
    }
  }
  $address_limit = 300;
  $address_url = '?page=' . urlencode($page_name) . '&addresses=1' . ($address_reason !== '' ? '&reason=' . urlencode($address_reason) : '');
  $city_label = function ($values) use ($citylistname) {
    if (intval($values['city']) > 0) {
      // Same name for two cities of the instance: their number tells them apart
      $name = isset($citylistname[intval($values['city'])]) ? $citylistname[intval($values['city'])] : '#' . intval($values['city']);
      return h($name) . (count(array_keys($citylistname, $name)) > 1 ? ' <span class="text-body-secondary">(n° ' . intval($values['city']) . ')</span>' : '');
    }
    return trim($values['cityname']) !== '' ? h($values['cityname']) . ' <span class="badge text-bg-warning">non référencée</span>' : '<span class="text-body-secondary">—</span>';
  };
?>
<p><a href="?page=<?= h(urlencode($page_name)) ?>"><i class="bi bi-arrow-left"></i> Observations</a></p>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-magic"></i> Corrections des adresses</h2>
    <span class="text-body-secondary small"><strong><?= count($address_proposals) ?></strong> proposée(s)</span>
  </div>
  <div class="card-body">
    <p class="small text-body-secondary">
      Corrections calculées sur les observations : ville reconnue dans l'adresse (sans tenir compte des majuscules, des
      accents, de « St » / « Saint », ou par son code postal), ville non référencée qui correspond à une ville de
      l'instance, ville retirée de l'adresse quand elle est déjà connue, numéro de bâtiment, code postal, pays et espaces
      retirés, même rue écrite différemment dans la même ville (l'orthographe la plus fréquente est gardée). Rien n'est
      modifié avant « Appliquer » ; seules les corrections cochées sont appliquées.
    </p>
    <ul class="nav nav-pills small mb-3">
      <li class="nav-item"><a class="nav-link py-1<?= $address_reason === '' ? ' active' : '' ?>" href="?page=<?= h(urlencode($page_name)) ?>&amp;addresses=1">Toutes <span class="badge text-bg-secondary"><?= count($address_proposals) ?></span></a></li>
      <?php foreach ($address_reasons as $reason => $label) { if (empty($address_counts[$reason])) { continue; } ?>
      <li class="nav-item"><a class="nav-link py-1<?= $address_reason === $reason ? ' active' : '' ?>" href="?page=<?= h(urlencode($page_name)) ?>&amp;addresses=1&amp;reason=<?= h(urlencode($reason)) ?>"><?= h($label) ?> <span class="badge text-bg-secondary"><?= intval($address_counts[$reason]) ?></span></a></li>
      <?php } ?>
    </ul>
    <?php if (!$shown) { ?>
    <p class="text-body-secondary mb-0"><i class="bi bi-check2-circle"></i> Aucune correction à proposer.</p>
    <?php } else { ?>
    <form method="POST" action="<?= h($address_url) ?>" id="address-form">
      <?= csrf_field() ?>
      <input type="hidden" name="address_fix" value="1" />
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <div class="form-check mb-0 me-2">
          <input class="form-check-input" type="checkbox" id="address-all" data-check-all="fix_ids[]" checked />
          <label class="form-check-label" for="address-all">Tout cocher</label>
        </div>
        <button class="btn btn-sm btn-primary" type="submit" data-confirm="Appliquer les corrections cochées ?"><i class="bi bi-check2-all"></i> Appliquer</button>
      </div>
    </form>
    <?php if (count($shown) > $address_limit) { ?><p class="small text-body-secondary">Les <?= $address_limit ?> premières sont affichées : appliquer puis revenir pour les suivantes.</p><?php } ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle table-admin table-stack-md mb-0">
        <thead class="table-light"><tr><th scope="col"><span class="visually-hidden">Choix</span></th><th scope="col">Observation</th><th scope="col">Avant</th><th scope="col">Après</th><th scope="col">Correction</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($shown, 0, $address_limit, true) as $obs_id => $proposal) { ?>
          <tr>
            <td><input class="form-check-input" type="checkbox" name="fix_ids[]" value="<?= intval($obs_id) ?>" form="address-form" checked aria-label="Corriger <?= h($proposal['token']) ?>" /></td>
            <td><a href="?page=<?= h(urlencode($page_name)) ?>&amp;approved=1&amp;filtertype=uniq&amp;filtertoken=<?= h(urlencode($proposal['token'])) ?>"><code><?= h($proposal['token']) ?></code></a></td>
            <td data-label="Avant"><span class="text-break"><?= address_visible_html($proposal['before']['address']) ?></span><div class="small text-body-secondary"><?= $city_label($proposal['before']) ?></div></td>
            <td data-label="Après"><span class="text-break fw-semibold"><?= h($proposal['after']['address']) ?></span><div class="small"><?= $city_label($proposal['after']) ?></div></td>
            <td><?php foreach ($proposal['reasons'] as $reason) { ?><span class="badge text-bg-light border me-1"><?= h($address_reasons[$reason]) ?></span><?php } ?>
              <?php if (in_array('format', $proposal['reasons'], true) && ($details = address_format_details($proposal['before']['address'], $proposal['after']['address']))) { ?><div class="small text-body-secondary mt-1"><?= h(implode(' ; ', $details)) ?></div><?php } ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
    <?php } ?>
  </div>
</div>
<?php
  return;
}

if (in_array($current_role, $actions_acl['edit']['access'])
    && ($address_proposals || count($obswithoutcity['pbaddress']) > 0 || count($obswithoutcity['cityunknown']) > 0)) {
?>
<div class="alert alert-warning" role="alert">
  <h2 class="h6 alert-heading"><i class="bi bi-geo-alt"></i> Qualité des adresses</h2>
  <ul class="mb-0 ps-3">
    <?php if ($address_proposals) { ?>
    <li><strong><?= count($address_proposals) ?></strong> correction(s) proposée(s)
      (<?php $parts = array(); foreach ($address_reasons as $reason => $label) { if (!empty($address_counts[$reason])) { $parts[] = h($label) . ' : ' . intval($address_counts[$reason]); } } echo implode(', ', $parts); ?>)
      — <a href="?page=observations&amp;addresses=1" class="alert-link">Vérifier et appliquer</a></li>
    <?php } ?>
    <?php if (count($obswithoutcity['cityunknown']) > 0) { ?>
    <li><strong><?= h(count($obswithoutcity['cityunknown'])) ?></strong> observation(s) dans une ville non référencée (archivées et à qualifier comprises) — <a href="?page=observations&amp;filtercityunknown=1&amp;searcharchived=all" class="alert-link">Afficher</a> ; à ajouter dans <a href="?page=cities" class="alert-link">Villes</a> pour qu'elles soient reconnues</li>
    <?php } ?>
    <?php if (count($obswithoutcity['pbaddress']) > 0) { ?>
    <li><strong><?= h(count($obswithoutcity['pbaddress'])) ?></strong> adresse(s) sans ville (pas au format « Rue, Ville » ; archivées et à qualifier comprises) — <a href="?page=observations&amp;filterpbaddress=1&amp;searcharchived=all" class="alert-link">Afficher</a></li>
    <?php } ?>
  </ul>
</div>
<?php
}

/* Search part */
// To check the good radio button
$filterType = (isset($_GET['filtertype']) && $_GET['filtertype'] == "similar") ? 'similar' : 'uniq';
$filterTypeUniqueChecked  = ($filterType == 'uniq') ? 'checked' : '';
$filterTypeSimilarChecked = ($filterType == 'similar') ? 'checked' : '';

$searchtoken = "";
$searchaddress = "";
$searchcity = 0;
$querysearch = "";
$searchcategory = 0;
$filtertoken = (isset($_GET['filtertoken']) && is_scalar($_GET['filtertoken'])) ? (string) $_GET['filtertoken'] : '';
$filteraddress = (isset($_GET['filteraddress']) && is_scalar($_GET['filteraddress'])) ? (string) $_GET['filteraddress'] : '';

if ($filtertoken !== '' && isset($_GET['filtertype']) && $_GET['filtertype'] == "similar") {
  $filter = array('distance' => 300,
                  'fdistance' => 1,
                  'fcategorie' => 1,
                  'faddress' => 1);
  $similar = sameas($filtertoken, $filter);
  $similar_sql = array();
  foreach ($similar as $similar_token) {
    $similar_sql[] = mysqli_real_escape_string($db, (string) $similar_token);
  }
  $querysearch .= " AND obs_token IN ('" . implode("','", $similar_sql) . "')";
  $urlsuffix .= "&filtertype=similar&filtertoken=" . urlencode($filtertoken);
}
elseif ($filtertoken !== '' && isset($_GET['filtertype']) && $_GET['filtertype'] == "uniq") {
  $searchtoken = mysqli_real_escape_string($db, $filtertoken);
  $querysearch .= " AND obs_token = '" . $searchtoken . "'";
  $urlsuffix .= "&filtertype=uniq&filtertoken=" . urlencode($filtertoken);
}

if ($filteraddress !== '') {
  $searchaddress = mysqli_real_escape_string($db, $filteraddress);
  $querysearch .= " AND LOWER(obs_address_string) LIKE LOWER('%" . $searchaddress . "%')";
  $urlsuffix .= "&filteraddress=" . urlencode($filteraddress);
}

if (isset($_GET['searchcity']) && is_numeric($_GET['searchcity']) && intval($_GET['searchcity']) != 0) {
  $searchcity = intval($_GET['searchcity']);
  $querysearch .= " AND obs_city='" . $searchcity . "'";
  $urlsuffix .= "&searchcity=" . $searchcity;
}

function obsadmin_tokens_sql($db, $tokens)
{
  $escaped = array();
  foreach ($tokens as $t) {
    $escaped[] = mysqli_real_escape_string($db, (string) $t);
  }
  return "('" . implode("','", $escaped) . "')";
}

if (isset($_GET['filterpbaddress']) && $_GET['filterpbaddress'] == "1") {
  $querysearch .= " AND obs_token IN " . obsadmin_tokens_sql($db, $obswithoutcity['pbaddress']);
  $urlsuffix .= "&filterpbaddress=1";
}
elseif (isset($_GET['filtercityunknown']) && $_GET['filtercityunknown'] == "1") {
  $querysearch .= " AND obs_token IN " . obsadmin_tokens_sql($db, $obswithoutcity['cityunknown']);
  $urlsuffix .= "&filtercityunknown=1";
}

/* Archived observations: hidden by default, or only them, or all */
$searcharchived = (isset($_GET['searcharchived']) && in_array((string) $_GET['searcharchived'], array('0', '1', 'all'), true)) ? (string) $_GET['searcharchived'] : '0';
if ($searcharchived !== 'all') {
  $querysearch .= " AND obs_archived='" . intval($searcharchived) . "'";
}
if ($searcharchived !== '0') {
  $urlsuffix .= "&searcharchived=" . urlencode($searcharchived);
}

if (isset($_GET['searchcategory']) && is_numeric($_GET['searchcategory']) && intval($_GET['searchcategory']) != 0) {
  $searchcategory = intval($_GET['searchcategory']);
  $querysearch .= " AND obs_categorie='" . $searchcategory . "'";
  $urlsuffix .= "&searchcategory=" . $searchcategory;
}

/* Filter obs by city when the current user has the citystaff role */
if ($current_role == 'citystaff') {
  // A citystaff only sees the observations of their cities (none when no city is set)
  $querysearch .= empty($role_cityIds) ? " AND 0 " : " AND obs_city IN ('" . implode("','", array_map('intval', $role_cityIds)) . "') ";
}

// Tab filter process
$approved = 1;
if (isset($_GET['approved']) && in_array((string) $_GET['approved'], array('0', '1', '2'), true)) {
  $approved = intval($_GET['approved']);
}

$approvedcount = array(0 => 0, 1 => 0, 2 => 0);
$query_count_tabs = mysqli_query($db, "SELECT obs_approved, COUNT(*) AS nb FROM obs_list WHERE obs_complete=1" . $querysearch . " GROUP BY obs_approved");
while ($query_count_tabs && $result_count_tabs = mysqli_fetch_array($query_count_tabs)) {
  $tabkey = intval($result_count_tabs['obs_approved']);
  if (isset($approvedcount[$tabkey])) {
    $approvedcount[$tabkey] += intval($result_count_tabs['nb']);
  }
}
// No tab chosen and none in this one (e.g. a link of "Qualité des adresses"): the first tab that has some
if (!isset($_GET['approved']) && $approvedcount[$approved] == 0) {
  foreach (array(1, 0, 2) as $tab) {
    if ($approvedcount[$tab] > 0) {
      $approved = $tab;
      break;
    }
  }
}
$tabapproved = array(0 => "", 1 => "", 2 => "");
$tabapproved[$approved] = "active";

/* Check the list of resolved obs */
$resolutionslist = array();
// Resolutions to choose from, grouped by status: token, comment and number of observations
$resolution_groups = array(2 => 'Prises en compte', 3 => 'En cours de résolution', 4 => 'Indiquées résolues', 1 => 'Résolues');
$resolution_options = array();
$resolutions_query = mysqli_query($db, "SELECT obs_resolutions.resolution_token, obs_resolutions.resolution_id, obs_resolutions.resolution_status,
                                               obs_resolutions.resolution_comment, COUNT(obs_resolutions_tokens.restok_observationid) AS nb
                                        FROM obs_resolutions LEFT JOIN obs_resolutions_tokens ON obs_resolutions_tokens.restok_resolutionid = obs_resolutions.resolution_id
                                        GROUP BY obs_resolutions.resolution_id ORDER BY obs_resolutions.resolution_time DESC, obs_resolutions.resolution_id DESC");
while ($resolutions_query && $resolutions_result = mysqli_fetch_array($resolutions_query)) {
  $resolutionslist[intval($resolutions_result['resolution_id'])] = $resolutions_result['resolution_token'];
  $group = isset($resolution_groups[intval($resolutions_result['resolution_status'])]) ? intval($resolutions_result['resolution_status']) : 2;
  $comment = trim((string) $resolutions_result['resolution_comment']);
  $resolution_options[$group][intval($resolutions_result['resolution_id'])] = $resolutions_result['resolution_token']
    . ($comment !== '' ? ' — ' . vigilo_truncate($comment, 50) : '') . ' (' . intval($resolutions_result['nb']) . ' obs.)';
}

$categorielist = getCategoriesList();
if (!is_array($categorielist)) {
  $categorielist = array();
}
$categorielist[] = array("catid" => 0, "catname" => "---");

$searchopen = ($filtertoken !== '' || $filteraddress !== '' || $searchcity != 0 || $searchcategory != 0 || $searcharchived !== '0');
?>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-search me-2"></i><strong>Recherche</strong>
  </div>
  <div class="card-body">
    <form method="GET" action="">
      <input type="hidden" name="page" value="<?= h($page_name) ?>" />
      <div class="row g-3">
        <div class="col-md-6 col-lg-4">
          <label for="searchToken" class="form-label">Token</label>
          <input type="text" class="form-control" name="filtertoken" id="searchToken" value="<?= h($filtertoken) ?>">
          <div class="mt-2">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="filtertype" id="gridRadios1" value="uniq" <?= $filterTypeUniqueChecked ?>>
              <label class="form-check-label" for="gridRadios1">Unique</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="filtertype" id="gridRadios2" value="similar" <?= $filterTypeSimilarChecked ?>>
              <label class="form-check-label" for="gridRadios2">Similaires</label>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-lg-4">
          <label for="searchAddress" class="form-label">Rue</label>
          <input type="text" class="form-control" name="filteraddress" id="searchAddress" value="<?= h($filteraddress) ?>">
        </div>
        <div class="col-md-6 col-lg-2">
          <label for="searchcity" class="form-label">Ville</label>
          <select class="form-select" name="searchcity" id="searchcity">
          <?php
          $citylistnametmp = $citylistname;
          $citylistnametmp[0] = "---";
          foreach ($citylistnametmp as $selectcityid => $selectcityname) {
            $selected = ($searchcity == $selectcityid) ? ' selected' : '';
            echo '<option value="' . intval($selectcityid) . '"' . $selected . '>' . h($selectcityname) . '</option>';
          }
          ?>
          </select>
        </div>
        <div class="col-md-6 col-lg-2">
          <label for="searchcategory" class="form-label">Catégorie</label>
          <select class="form-select" name="searchcategory" id="searchcategory">
          <?php
          foreach ($categorielist as $categorie) {
            $catid = isset($categorie['catid']) ? intval($categorie['catid']) : 0;
            $catname = isset($categorie['catname']) ? $categorie['catname'] : '';
            $selected = ($searchcategory == $catid) ? ' selected' : '';
            echo '<option value="' . $catid . '"' . $selected . '>' . h($catname) . '</option>';
          }
          ?>
          </select>
        </div>
        <div class="col-md-6 col-lg-2">
          <label for="searcharchived" class="form-label">Archives</label>
          <select class="form-select" name="searcharchived" id="searcharchived">
            <option value="0"<?= $searcharchived === '0' ? ' selected' : '' ?>>Non archivées</option>
            <option value="1"<?= $searcharchived === '1' ? ' selected' : '' ?>>Archivées</option>
            <option value="all"<?= $searcharchived === 'all' ? ' selected' : '' ?>>Toutes</option>
          </select>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Rechercher</button>
        <?php if ($searchopen) { ?>
        <a href="?page=<?= urlencode($page_name) ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Réinitialiser</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>

<?php
/* Pagination */
$pagenb = 1;
if (isset($_GET['pagenb']) && is_numeric($_GET['pagenb']) && intval($_GET['pagenb']) > 0) {
  $pagenb = intval($_GET['pagenb']);
}

$maxobsperpage = 100;
$offset = ($pagenb - 1) * $maxobsperpage;

$countpage_query = mysqli_query($db, "SELECT count(*) FROM obs_list WHERE obs_approved='" . $approved . "' AND obs_complete=1" . $querysearch);
$nbrows = $countpage_query ? intval(mysqli_fetch_array($countpage_query)[0]) : 0;
$nbpages = intval(ceil($nbrows / $maxobsperpage));

/* Main query for the list of obs */
$observations = array();
$query_obs = mysqli_query($db, "SELECT * FROM obs_list WHERE obs_approved='" . $approved . "' AND obs_complete=1 " . $querysearch . " ORDER BY obs_time DESC LIMIT " . intval($offset) . "," . intval($maxobsperpage));
while ($query_obs && $result_obs = mysqli_fetch_array($query_obs)) {
  $observations[] = $result_obs;
}

/* Moderator notes of the displayed observations */
$notes = array();
if (count($observations) > 0 && in_array($current_role, $actions_acl['notes']['access'])) {
  $obsids = array();
  foreach ($observations as $result_obs) {
    $obsids[] = intval($result_obs['obs_id']);
  }
  $notes_query = mysqli_query($db, "SELECT * FROM obs_notes WHERE note_obsid IN (" . implode(',', $obsids) . ") ORDER BY note_time ASC");
  while ($notes_query && $note = mysqli_fetch_array($notes_query)) {
    $notes[intval($note['note_obsid'])][] = $note;
  }
}

/* Problem of each displayed observation (link to the page Problèmes, admin and citystaff) */
$obs_problems = array();
if (count($observations) > 0 && isset($menu['problems']) && in_array($current_role, $menu['problems']['access'])) {
  $obsids = array();
  foreach ($observations as $result_obs) {
    $obsids[] = intval($result_obs['obs_id']);
  }
  $problems_query = mysqli_query($db, "SELECT obs_problem_observations.po_obsid, obs_problems.problem_id, obs_problems.problem_title
                                       FROM obs_problem_observations INNER JOIN obs_problems ON obs_problems.problem_id = obs_problem_observations.po_problemid
                                       WHERE obs_problem_observations.po_obsid IN (" . implode(',', $obsids) . ")");
  while ($problems_query && $row = mysqli_fetch_assoc($problems_query)) {
    $obs_problems[intval($row['po_obsid'])] = array(intval($row['problem_id']), (string) $row['problem_title']);
  }
}

$listurl = '?page=' . urlencode($page_name) . '&approved=' . $approved . ($pagenb > 1 ? '&pagenb=' . $pagenb : '') . $urlsuffix;
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <h2 class="h4 mb-0">Liste</h2>
  <span class="text-body-secondary"><strong><?= h($nbrows) ?></strong> observations</span>
</div>

<?php if (in_array($current_role, $actions_acl['approve']['access'])) { ?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tabapproved[1] ?>" href="<?= h('?page=' . urlencode($page_name) . '&approved=1' . $urlsuffix) ?>"><i class="bi bi-check-lg"></i> Approuvées <span class="badge rounded-pill text-bg-info"><?= h($approvedcount[1]) ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tabapproved[0] ?>" href="<?= h('?page=' . urlencode($page_name) . '&approved=0' . $urlsuffix) ?>"><i class="bi bi-clock"></i> À qualifier <span class="badge rounded-pill text-bg-warning"><?= h($approvedcount[0]) ?></span></a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tabapproved[2] ?>" href="<?= h('?page=' . urlencode($page_name) . '&approved=2' . $urlsuffix) ?>"><i class="bi bi-x-lg"></i> Désapprouvées <span class="badge rounded-pill text-bg-secondary"><?= h($approvedcount[2]) ?></span></a>
  </li>
</ul>
<?php } ?>

<?php
// Bulk actions that make sense in this tab: not the current moderation state, resolutions for
// published observations only, archive or unarchive depending on the archives shown
$bulk_actions_shown = $bulk_actions;
unset($bulk_actions_shown[array(0 => 'pending', 1 => 'approve', 2 => 'disapprove')[$approved]]);
if ($approved != 1) {
  unset($bulk_actions_shown['resolution_new'], $bulk_actions_shown['resolution_add']);
}
if ($searcharchived === '0') {
  unset($bulk_actions_shown['unarchive']);
} elseif ($searcharchived === '1') {
  unset($bulk_actions_shown['archive']);
}
if (!empty($bulk_actions_shown) && count($observations) > 0) { ?>
<form method="POST" action="<?= h($listurl) ?>" id="bulk-form" class="card card-body shadow-sm mb-3 py-2" data-bulk>
  <?= csrf_field() ?>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <div class="form-check mb-0 me-2">
      <input class="form-check-input" type="checkbox" id="bulk-all" data-bulk-all />
      <label class="form-check-label" for="bulk-all">Tout sélectionner</label>
    </div>
    <span class="text-body-secondary small me-2"><strong data-bulk-count>0</strong> sélectionnée(s)</span>
    <label class="visually-hidden" for="bulk-action">Action sur la sélection</label>
    <select class="form-select form-select-sm w-auto" name="bulk_action" id="bulk-action">
      <option value="">Action sur la sélection…</option>
      <?php foreach ($bulk_actions_shown as $bulk_key => $bulk_action) {
        $bulk_attr = '';
        if (in_array($bulk_key, array('category', 'city', 'resolution_add'), true)) {
          $bulk_attr = ' data-field="' . h($bulk_key) . '"';
        }
        if ($bulk_key == 'delete') {
          $bulk_attr = ' data-confirm="Supprimer définitivement %n observation(s) et leurs photos ?"';
        } ?>
      <option value="<?= h($bulk_key) ?>"<?= $bulk_attr ?>><?= h($bulk_action['label']) ?></option>
      <?php } ?>
    </select>
    <?php if (isset($bulk_actions['category'])) { ?>
    <span data-bulk-field="category" hidden>
      <label class="visually-hidden" for="bulk-category">Nouvelle catégorie</label>
      <select class="form-select form-select-sm w-auto" name="bulk_value_category" id="bulk-category">
        <option value="">— catégorie —</option>
        <?php foreach ($bulk_categories as $bulk_catid => $bulk_catname) { ?>
        <option value="<?= intval($bulk_catid) ?>"><?= h($bulk_catname) ?></option>
        <?php } ?>
      </select>
    </span>
    <?php } ?>
    <?php if (isset($bulk_actions['city'])) { ?>
    <span data-bulk-field="city" hidden>
      <label class="visually-hidden" for="bulk-city">Nouvelle ville</label>
      <select class="form-select form-select-sm w-auto" name="bulk_value_city" id="bulk-city">
        <option value="">— ville —</option>
        <?php foreach ($citylistname as $bulk_cityid => $bulk_cityname) { ?>
        <option value="<?= intval($bulk_cityid) ?>"><?= h($bulk_cityname) ?></option>
        <?php } ?>
      </select>
    </span>
    <?php } ?>
    <?php if (isset($bulk_actions['resolution_add'])) { ?>
    <span data-bulk-field="resolution_add" hidden>
      <label class="visually-hidden" for="bulk-resolution-filter">Filtrer les résolutions</label>
      <input type="search" class="form-control form-control-sm d-inline-block w-auto" id="bulk-resolution-filter" data-filter-select="#bulk-resolution" placeholder="Filtrer (token…)" autocomplete="off" />
      <label class="visually-hidden" for="bulk-resolution">Résolution</label>
      <select class="form-select form-select-sm w-auto" name="bulk_value_resolution_add" id="bulk-resolution">
        <option value="">— résolution —</option>
        <?= obsadmin_resolution_options($resolution_groups, $resolution_options) ?>
      </select>
    </span>
    <?php } ?>
    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2-all"></i> Appliquer</button>
  </div>
</form>
<?php } ?>

<div class="card shadow-sm mb-3">
<div class="table-responsive">
  <table class="table table-hover align-middle table-admin table-compact table-stack mb-0">
    <thead class="table-light">
      <tr>
        <th style="width: 1%"><span class="visually-hidden">Photo</span></th>
        <th>Observation</th>
        <th>Précisions</th>
        <th>Localisation</th>
        <th class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
<?php
if (count($observations) == 0) {
  echo '<tr><td colspan="5" class="text-center text-body-secondary py-4"><i class="bi bi-inbox"></i> Aucune observation</td></tr>';
}

$category_names = array();
foreach ($categorielist as $categorie) {
  if (isset($categorie['catid'])) {
    $category_names[intval($categorie['catid'])] = isset($categorie['catname']) ? (string) $categorie['catname'] : '';
  }
}
$resolution_badges = array(
  1 => array('text-bg-success', 'bi-check2-all', 'Résolue'),
  4 => array('text-bg-info', 'bi-check2-circle', 'Indiquée résolue'),
  3 => array('text-bg-warning', 'bi-hourglass-split', 'En cours de résolution'),
  2 => array('text-bg-secondary', 'bi-info-circle', 'Prise en compte'),
);
$can_edit_role    = in_array($current_role, $actions_acl['edit']['access']);
$can_notes_role   = in_array($current_role, $actions_acl['notes']['access']);
$can_resolve_role = in_array($current_role, $actions_acl['resolve']['access']);
$can_link_role    = isset($bulk_actions['resolution_add']) || isset($bulk_actions['resolution_new']);

foreach ($observations as $result_obs) {
  $obs_id    = intval($result_obs['obs_id']);
  $obs_token = (string) $result_obs['obs_token'];
  $obs_time  = intval($result_obs['obs_time']);
  $can_act = obsadmin_can_act($result_obs, $current_role, $role_cityIds);

  $highlight_city = "";
  if ($current_role == 'citystaff' && in_array(intval($result_obs['obs_city']), $role_cityIds, true)) {
    $highlight_city = "table-info";
  }

  // Most advanced status among the resolutions of the observation (0: in none)
  $resolution_status = intval(getResolutionStatus($obs_id));
  $in_resolution = ($resolution_status > 0);

  // Photos through the admin: never pixelated for moderators
  $panel_base = 'photo.php';
  $lat = (float) $result_obs['obs_coordinates_lat'];
  $lon = (float) $result_obs['obs_coordinates_lon'];
  $osm_url = 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=16/' . $lat . '/' . $lon . '&layers=N';
  // Street-level pictures around the observation (Panoramax viewer, map centred on it)
  $panoramax_url = 'https://explore.panoramax.fr/#focus=map&map=19/' . $lat . '/' . $lon;
  $actionurl = '?page=' . urlencode($page_name) . '&approved=' . $approved . ($pagenb > 1 ? '&pagenb=' . $pagenb : '') . '&token=' . urlencode($obs_token) . '&obsid=' . $obs_id . $urlsuffix;
  $obs_notes = isset($notes[$obs_id]) ? $notes[$obs_id] : array();
  $catid = intval($result_obs['obs_categorie']);
  $cityname = trim((string) $result_obs['obs_cityname']);
  $city = $cityname !== '' ? $cityname : (isset($citylistname[intval($result_obs['obs_city'])]) ? $citylistname[intval($result_obs['obs_city'])] : '');
  $edit_fill = array('obs_id' => $obs_id, 'obs_comment' => (string) $result_obs['obs_comment'], 'obs_explanation' => (string) $result_obs['obs_explanation'],
                     'obs_categorie' => $catid, 'obs_address_string' => (string) $result_obs['obs_address_string'],
                     'obs_city' => intval($result_obs['obs_city']), 'obs_cityname' => $cityname, 'city_shown' => $cityname === '', 'cityname_shown' => $cityname !== '',
                     'post_date' => date('d/m/Y', $obs_time), 'post_heure' => date('H:i', $obs_time));
  // A resolution concerns a published observation not already in one
  $can_link = $can_act && $can_link_role && !$in_resolution && intval($result_obs['obs_approved']) == 1;
  $more = array();
  if ($can_act && in_array($current_role, $actions_acl['archive']['access'])) {
    $more[] = empty($result_obs['obs_archived'])
      ? '<a class="dropdown-item" href="' . h($actionurl . '&action=archive&archiveto=1' . csrf_query()) . '"><i class="bi bi-archive"></i> Archiver</a>'
      : '<a class="dropdown-item" href="' . h($actionurl . '&action=archive&archiveto=0' . csrf_query()) . '"><i class="bi bi-box-arrow-up"></i> Désarchiver</a>';
  }
  if ($can_act && in_array($current_role, $actions_acl['cleancache']['access'])) {
    $more[] = '<a class="dropdown-item" href="' . h($actionurl . '&action=cleancache' . csrf_query()) . '"><i class="bi bi-hdd"></i> Effacer le cache</a>';
  }
  if ($can_act && in_array($current_role, $actions_acl['approve']['access']) && intval($result_obs['obs_approved']) != 0) {
    $more[] = '<a class="dropdown-item" href="' . h($actionurl . '&action=approve&approveto=0' . csrf_query()) . '"><i class="bi bi-clock"></i> Remettre à qualifier</a>';
  }
  if ($can_act && in_array($current_role, $actions_acl['delete']['access'])) {
    $more[] = '<a class="dropdown-item text-danger" href="' . h($actionurl . '&action=delete' . csrf_query()) . '" data-confirm="Supprimer définitivement l\'observation ' . h($obs_token) . ' et sa photo ?"><i class="bi bi-trash"></i> Supprimer</a>';
  }
?>
      <tr class="<?= h($highlight_city) ?>">
        <td>
          <div class="d-flex align-items-center gap-2">
            <?php if ($can_act && !empty($bulk_actions_shown)) { ?>
            <input class="form-check-input" type="checkbox" name="bulk_ids[]" value="<?= $obs_id ?>" form="bulk-form" id="bulk-<?= $obs_id ?>" aria-label="Sélectionner <?= h($obs_token) ?>" />
            <?php } ?>
            <a href="<?= h($panel_base . '?s=800&token=' . urlencode($obs_token)) ?>" data-photo data-photo-title="Observation <?= h($obs_token) ?>"><img class="obs-thumb obs-thumb-sm" loading="lazy" alt="Observation <?= h($obs_token) ?>" src="<?= h($panel_base . '?s=200&token=' . urlencode($obs_token)) ?>" /></a>
          </div>
        </td>
        <td class="text-nowrap">
          <code><?= h($obs_token) ?></code>
          <div class="obs-meta"><?= h(date('d/m/Y H:i', $obs_time)) ?></div>
          <div class="d-flex flex-wrap gap-1">
          <?php if (!empty($result_obs['obs_archived'])) { ?><span class="badge text-bg-secondary"><i class="bi bi-archive"></i> Archivée</span><?php } ?>
          <?php if ($in_resolution) {
            $badge = isset($resolution_badges[$resolution_status]) ? $resolution_badges[$resolution_status] : $resolution_badges[2];
          ?><a class="badge <?= h($badge[0]) ?> text-decoration-none" href="?page=resolutions&amp;resolved=<?= intval($resolution_status) ?>&amp;q=<?= h(urlencode($obs_token)) ?>" title="Voir la résolution"><i class="bi <?= h($badge[1]) ?>"></i> <?= h($badge[2]) ?></a><?php } ?>
          <?php if (isset($obs_problems[$obs_id])) { ?><a class="badge text-bg-light border text-decoration-none text-wrap text-start" href="?page=problems&amp;view=<?= intval($obs_problems[$obs_id][0]) ?>" title="Problème auquel l'observation est rattachée"><i class="bi bi-diagram-3"></i> <?= h(vigilo_truncate($obs_problems[$obs_id][1], 40)) ?></a><?php } ?>
          </div>
        </td>
        <td class="obs-text">
          <div class="fw-semibold small"><?= h(isset($category_names[$catid]) ? $category_names[$catid] : '#' . $catid) ?></div>
          <?php if (trim((string) $result_obs['obs_comment']) !== '') { ?><div class="text-break"><?= h($result_obs['obs_comment']) ?></div><?php } ?>
          <?php if (trim((string) $result_obs['obs_explanation']) !== '') { ?><div class="obs-meta obs-clamp" title="<?= h($result_obs['obs_explanation']) ?>"><?= h(vigilo_truncate($result_obs['obs_explanation'], 120)) ?></div><?php } ?>
        </td>
        <td class="obs-loc">
          <div class="text-break"><?= h($result_obs['obs_address_string']) ?></div>
          <div class="obs-meta">
            <?= $city !== '' ? h($city) : '<span class="text-warning-emphasis">Sans ville</span>' ?>
            <?php if ($cityname !== '') { ?><span class="badge text-bg-warning">non référencée</span><?php } ?>
            · <a href="<?= h($osm_url) ?>" target="_blank" rel="noopener" title="Voir sur OpenStreetMap"><i class="bi bi-map"></i><span class="visually-hidden">Carte</span></a>
            · <a class="text-nowrap" href="<?= h($panoramax_url) ?>" target="_blank" rel="noopener" title="Voir les photos de rue autour de l'observation sur Panoramax"><i class="bi bi-camera"></i> Panoramax</a>
          </div>
        </td>
        <td class="text-end">
          <div class="d-flex flex-wrap flex-xl-nowrap justify-content-end gap-1">
          <?php if ($can_act && in_array($current_role, $actions_acl['approve']['access'])) {
            // Only the moderation states the observation is not in
            if (intval($result_obs['obs_approved']) != 1) { ?>
            <a class="btn btn-sm btn-outline-success" href="<?= h($actionurl . '&action=approve&approveto=1' . csrf_query()) ?>" title="Approuver"><i class="bi bi-check-lg"></i><span class="d-none d-xxl-inline"> Approuver</span></a>
            <?php }
            if (intval($result_obs['obs_approved']) != 2) { ?>
            <a class="btn btn-sm btn-outline-warning" href="<?= h($actionurl . '&action=approve&approveto=2' . csrf_query()) ?>" title="Désapprouver"><i class="bi bi-x-lg"></i><span class="d-none d-xxl-inline"> Désapprouver</span></a>
            <?php }
          }
          if ($can_act && $can_edit_role) { ?>
            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#obsEditModal" data-title="Modifier l'observation <?= h($obs_token) ?>" data-fill="<?= h(json_encode($edit_fill)) ?>" title="Modifier"><i class="bi bi-pencil"></i><span class="visually-hidden">Modifier</span></button>
          <?php }
          if ($can_link) { ?>
            <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="modal" data-bs-target="#obsResolutionModal" data-title="Résolution pour l'observation <?= h($obs_token) ?>" data-fill="<?= h(json_encode(array('bulk_ids' => $obs_id))) ?>" title="Ajouter à une résolution"><i class="bi bi-link-45deg"></i><span class="d-none d-xxl-inline"> Résolution</span></button>
          <?php }
          if ($can_act && $can_notes_role) { ?>
            <button class="btn btn-sm btn-outline-secondary text-nowrap" type="button" data-bs-toggle="collapse" data-bs-target="#notes-<?= $obs_id ?>" aria-expanded="false" aria-controls="notes-<?= $obs_id ?>" title="Notes privées"><i class="bi bi-sticky"></i> <?= count($obs_notes) ?></button>
          <?php }
          if ($more) { ?>
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Autres actions"><i class="bi bi-three-dots"></i><span class="visually-hidden">Autres actions</span></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach ($more as $item) { ?><li><?= $item ?></li><?php } ?>
              </ul>
            </div>
          <?php } ?>
          </div>
        </td>
      </tr>
      <?php if ($can_act && $can_notes_role) { ?>
      <tr class="collapse obs-notes-row" id="notes-<?= $obs_id ?>">
        <td colspan="5">
          <div class="card card-body bg-body-tertiary p-2">
            <p class="small text-body-secondary mb-2"><i class="bi bi-lock"></i> Notes privées des modérateurs de l'observation <code><?= h($obs_token) ?></code>, jamais publiées.</p>
            <?php if (count($obs_notes) == 0) { ?>
            <p class="small text-body-secondary mb-2">Aucune note.</p>
            <?php } ?>
            <?php foreach ($obs_notes as $note) {
              $note_can_delete = $is_admin || ($current_login !== '' && (string) $note['note_login'] === $current_login);
            ?>
            <div class="border-bottom pb-2 mb-2">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="small text-body-secondary">
                  <i class="bi bi-person"></i> <?= h($note['note_login']) ?> · <?= h(date('d/m/Y H:i', intval($note['note_time']))) ?>
                </div>
                <?php if ($note_can_delete) { ?>
                <form method="POST" action="<?= h($listurl) ?>" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="note_action" value="delete" />
                  <input type="hidden" name="note_id" value="<?= intval($note['note_id']) ?>" />
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Supprimer cette note ?" title="Supprimer la note"><i class="bi bi-trash"></i><span class="visually-hidden">Supprimer la note</span></button>
                </form>
                <?php } ?>
              </div>
              <div class="small"><?= nl2br(h($note['note_text'])) ?></div>
            </div>
            <?php } ?>
            <form method="POST" action="<?= h($listurl) ?>" class="m-0 d-flex flex-wrap gap-2 align-items-start">
              <?= csrf_field() ?>
              <input type="hidden" name="note_action" value="add" />
              <input type="hidden" name="note_obsid" value="<?= $obs_id ?>" />
              <label for="note_text_<?= $obs_id ?>" class="visually-hidden">Nouvelle note</label>
              <textarea class="form-control form-control-sm flex-grow-1" id="note_text_<?= $obs_id ?>" name="note_text" rows="2" maxlength="5000" placeholder="Ajouter une note privée…" required style="min-width: 14rem; flex-basis: 20rem;"></textarea>
              <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Ajouter la note</button>
            </form>
          </div>
        </td>
      </tr>
      <?php } ?>
<?php
}
?>
    </tbody>
  </table>
</div>
</div>

<?= admin_pagination('?page=' . urlencode($page_name) . '&approved=' . $approved . $urlsuffix . '&pagenb=', $pagenb, $nbpages, 'Pagination des observations') ?>

<?php if ($can_edit_role && count($observations) > 0) { ?>
<div class="modal fade" id="obsEditModal" tabindex="-1" aria-labelledby="obsEditModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="POST" action="<?= h($listurl) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="obs_id" value="" />
      <div class="modal-header">
        <h2 class="modal-title h5" id="obsEditModalTitle">Modifier l'observation</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label for="obs-edit-comment" class="form-label">Commentaire</label>
            <input type="text" class="form-control" id="obs-edit-comment" name="obs_comment" value="" />
          </div>
          <div class="col-md-6">
            <label for="obs-edit-category" class="form-label">Catégorie</label>
            <select class="form-select" id="obs-edit-category" name="obs_categorie">
              <?php foreach ($categorielist as $categorie) { ?>
              <option value="<?= isset($categorie['catid']) ? intval($categorie['catid']) : 0 ?>"><?= h(isset($categorie['catname']) ? $categorie['catname'] : '') ?></option>
              <?php } ?>
            </select>
          </div>
          <div class="col-12">
            <label for="obs-edit-explanation" class="form-label">Explication</label>
            <textarea class="form-control" id="obs-edit-explanation" name="obs_explanation" rows="2"></textarea>
          </div>
          <div class="col-md-6">
            <label for="obs-edit-address" class="form-label">Rue</label>
            <input type="text" class="form-control" id="obs-edit-address" name="obs_address_string" value="" required />
          </div>
          <div class="col-md-6" data-fill-show="city_shown">
            <label for="obs-edit-city" class="form-label">Ville</label>
            <select class="form-select" id="obs-edit-city" name="obs_city">
              <option value="0">---</option>
              <?php foreach ($citylistname as $selectcityid => $selectcityname) { ?>
              <option value="<?= intval($selectcityid) ?>"><?= h($selectcityname) ?></option>
              <?php } ?>
            </select>
          </div>
          <div class="col-md-6" data-fill-show="cityname_shown">
            <label for="obs-edit-cityname" class="form-label">Ville <span class="badge text-bg-warning">non référencée</span></label>
            <input type="text" class="form-control" id="obs-edit-cityname" name="obs_cityname" value="" required />
          </div>
          <div class="col-6 col-md-3">
            <label for="obs-edit-date" class="form-label">Date</label>
            <input type="text" class="form-control" id="obs-edit-date" name="post_date" value="" placeholder="jj/mm/aaaa" required />
          </div>
          <div class="col-6 col-md-3">
            <label for="obs-edit-time" class="form-label">Heure</label>
            <input type="text" class="form-control" id="obs-edit-time" name="post_heure" value="" placeholder="hh:mm" required />
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php } ?>

<?php if ($can_link_role && $approved == 1 && count($observations) > 0) { ?>
<div class="modal fade" id="obsResolutionModal" tabindex="-1" aria-labelledby="obsResolutionModalTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="<?= h($listurl) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="bulk_ids[]" value="" />
      <div class="modal-header">
        <h2 class="modal-title h5" id="obsResolutionModalTitle">Résolution</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <?php if (isset($bulk_actions['resolution_add'])) { ?>
        <label for="obs-resolution" class="form-label">Ajouter à une résolution existante</label>
        <input type="search" class="form-control mb-2" id="obs-resolution-filter" data-filter-select="#obs-resolution" placeholder="Filtrer : token, commentaire…" aria-label="Filtrer les résolutions" autocomplete="off" />
        <select class="form-select" id="obs-resolution" name="bulk_value_resolution_add" required>
          <option value="">— résolution —</option>
          <?= obsadmin_resolution_options($resolution_groups, $resolution_options) ?>
        </select>
        <div class="form-text">Token, commentaire et nombre d'observations de la résolution ; les plus récentes d'abord.</div>
        <?php } ?>
        <?php if (isset($bulk_actions['resolution_new'])) { ?>
        <p class="small text-body-secondary mt-3 mb-0">Ou créer une nouvelle résolution (« Prise en compte ») pour cette seule observation.</p>
        <?php } ?>
      </div>
      <div class="modal-footer">
        <?php if (isset($bulk_actions['resolution_new'])) { ?>
        <button type="submit" class="btn btn-outline-info me-auto" name="bulk_action" value="resolution_new" formnovalidate><i class="bi bi-plus-lg"></i> Nouvelle résolution</button>
        <?php } ?>
        <?php if (isset($bulk_actions['resolution_add'])) { ?>
        <button type="submit" class="btn btn-primary" name="bulk_action" value="resolution_add"><i class="bi bi-link-45deg"></i> Ajouter</button>
        <?php } ?>
      </div>
    </form>
  </div>
</div>
<?php } ?>

<?php if (in_array($current_role, $actions_acl['archive']['access'])) { ?>
<div class="card shadow-sm mt-4 mb-4">
  <div class="card-header d-flex align-items-center">
    <i class="bi bi-archive me-2"></i><strong>Archiver par période</strong>
  </div>
  <div class="card-body">
    <p class="small text-body-secondary">Les observations archivées ne sont plus listées dans l'application ni sur la carte, mais restent comptées dans les statistiques. Elles restent visibles ici (filtre « Archives ») et peuvent être désarchivées.</p>
    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="archive_step" value="preview" />
      <div class="row g-3 align-items-end">
        <div class="col-sm-6 col-lg-3">
          <label for="archive_from" class="form-label">Du <span class="text-body-secondary small">(facultatif)</span></label>
          <input type="date" class="form-control" name="archive_from" id="archive_from" value="<?= h($archive_form['from']) ?>" />
        </div>
        <div class="col-sm-6 col-lg-3">
          <label for="archive_to" class="form-label">Au</label>
          <input type="date" class="form-control" name="archive_to" id="archive_to" value="<?= h($archive_form['to']) ?>" required />
        </div>
        <div class="col-sm-6 col-lg-3">
          <label for="archive_category" class="form-label">Catégorie</label>
          <select class="form-select" name="archive_category" id="archive_category">
            <option value="0">Toutes</option>
            <?php foreach ($archive_categories as $archive_catid => $archive_catname) { ?>
            <option value="<?= intval($archive_catid) ?>"<?= $archive_form['category'] == $archive_catid ? ' selected' : '' ?>><?= h($archive_catname) ?></option>
            <?php } ?>
          </select>
        </div>
        <div class="col-sm-6 col-lg-3">
          <button type="submit" class="btn btn-outline-warning w-100"><i class="bi bi-search"></i> Compter les observations</button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php } ?>
