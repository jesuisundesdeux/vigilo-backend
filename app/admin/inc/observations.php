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
                     "edit" => array("access" => array('admin')),
                     "notes" => array("access" => array('admin','citystaff')));

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
      $twitt = (isset($_GET['twitt']) && is_numeric($_GET['twitt'])) ? intval($_GET['twitt']) : 0;

      delete_token_cache($token);
      mysqli_query($db, "UPDATE obs_list SET obs_approved='" . $approveto . "' WHERE obs_id='" . $obsid . "'");
      audit_log('observation_approve', $token, array('approved' => $approveto));
      obsadmin_message($messages, 'success', 'Observation <strong>' . h($token) . '</strong> ' . ($approveto == 1 ? 'approuvée' : ($approveto == 2 ? 'désapprouvée' : 'remise à qualifier')));

      // puis fait un twitt
      if ($approveto == 1 && $twitt == 1) {
        $r = tweetToken($token);
        if (is_array($r) && isset($r['success']) && $r['success'] == true) {
          audit_log('observation_tweet', $token);
          obsadmin_message($messages, 'success', 'Tweet <strong>' . h($token) . '</strong> parti');
        }
        else {
          obsadmin_message($messages, 'warning', h(is_array($r) && isset($r['error']) ? $r['error'] : 'Erreur lors du tweet'));
        }
      }
    }
    elseif ($action == 'cleancache' && in_array($current_role, $actions_acl['cleancache']['access'])) {
      delete_token_cache($token);
      delete_map_cache($token);
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
    $note_query = mysqli_query($db, "SELECT * FROM obs_notes WHERE note_id='" . $note_id . "' LIMIT 1");
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
        audit_log('note_delete', $obs ? $obs['obs_token'] : '', array('note_id' => $note_id, 'note_login' => $note['note_login']));
        obsadmin_message($messages, 'success', 'Note supprimée');
      }
    }
  }
}

/* Observations cities check */
$city_query = mysqli_query($db, "SELECT * FROM obs_cities ORDER BY city_name");
$citylist = array();
$citylistname = array();

while ($city_query && $city_result = mysqli_fetch_array($city_query)) {
  $cityid = intval($city_result['city_id']);
  $citylist[$cityid] = flatstring((string) $city_result['city_name']);
  $citylistname[$cityid] = $city_result['city_name'];
}

$obswithoutcity = array("pbaddress" => array(), "cityunknown" => array(), "readytoimport" => array());

if (in_array($current_role, $actions_acl['edit']['access'])) {
  $input_enabled = '';
  $input_class = 'form-control form-control-sm';
  $select_class = 'form-select form-select-sm';

  /* Import of the cities: changes the database, so it needs the CSRF token */
  $do_import = isset($_GET['importcityfromadress']) && $_GET['importcityfromadress'] == "1" && csrf_valid();
  $imported = 0;

  $obswithoutcity_query = mysqli_query($db, "SELECT obs_token,obs_address_string,obs_cityname FROM obs_list WHERE obs_city=0 AND obs_complete=1");
  while ($obswithoutcity_query && $obswithoutcity_result = mysqli_fetch_array($obswithoutcity_query)) {
    $token = (string) $obswithoutcity_result['obs_token'];
    $token_sql = mysqli_real_escape_string($db, $token);
    $cityInadress = array();
    preg_match('/^([^,]*),([^,]*)$/', (string) $obswithoutcity_result['obs_address_string'], $cityInadress);

    if (count($cityInadress) == 3 && empty($obswithoutcity_result['obs_cityname'])) {
      $cityname = trim($cityInadress[2]);
      $address = mysqli_real_escape_string($db, $cityInadress[1]);
      $cityid = array_search(flatstring($cityname), $citylist);
      $obswithoutcity['readytoimport'][] = $token;

      if ($do_import) {
        if ($cityid) {
          mysqli_query($db, "UPDATE obs_list SET obs_address_string='" . $address . "', obs_city='" . intval($cityid) . "' WHERE obs_token='" . $token_sql . "'");
        }
        else {
          mysqli_query($db, "UPDATE obs_list SET obs_address_string='" . $address . "', obs_cityname='" . mysqli_real_escape_string($db, $cityname) . "' WHERE obs_token='" . $token_sql . "'");
        }
        $imported++;
      }
    }
    elseif (!empty($obswithoutcity_result['obs_cityname'])) {
      $cityname = (string) $obswithoutcity_result['obs_cityname'];
      $cityid = array_search(flatstring($cityname), $citylist);
      if ($cityid) {
        $obswithoutcity['readytoimport'][] = $token;
        if ($do_import) {
          mysqli_query($db, "UPDATE obs_list SET obs_cityname='', obs_city='" . intval($cityid) . "' WHERE obs_token='" . $token_sql . "'");
          $imported++;
        }
      }
      else {
        $obswithoutcity['cityunknown'][] = $token;
      }
    }
    else {
      $obswithoutcity['pbaddress'][] = $token;
    }
  }

  if ($do_import) {
    audit_log('observation_importcity', '', array('imported' => $imported));
    obsadmin_message($messages, 'success', '<strong>' . h($imported) . '</strong> observations mises à jour à partir de leur adresse');
  }
}
else {
  $input_enabled = 'disabled';
  $input_class = 'form-control-plaintext';
  $select_class = 'form-select form-select-sm';
}

/* Messages of the actions */
foreach ($messages as $message) {
  echo '<div class="alert alert-' . h($message['type']) . ' alert-dismissible fade show" role="alert">' . $message['html']
     . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
}

if (in_array($current_role, $actions_acl['edit']['access'])
    && (count($obswithoutcity['pbaddress']) > 0 || count($obswithoutcity['cityunknown']) > 0 || count($obswithoutcity['readytoimport']) > 0)) {
?>
<div class="alert alert-warning" role="alert">
  <h2 class="h6 alert-heading"><i class="bi bi-geo-alt"></i> Observations sans villes configurées</h2>
  <ul class="mb-0 ps-3">
    <li><strong><?= h(count($obswithoutcity['pbaddress'])) ?></strong> adresses qui ne sont pas au format "Rue, Ville", impossibles à importer — <a href="?page=observations&amp;filterpbaddress=1" class="alert-link">Afficher</a></li>
    <li><strong><?= h(count($obswithoutcity['cityunknown'])) ?></strong> villes non référencées — <a href="?page=observations&amp;filtercityunknown=1" class="alert-link">Afficher</a></li>
    <li><strong><?= h(count($obswithoutcity['readytoimport'])) ?></strong> importables en cityid — <a href="?page=observations&amp;importcityfromadress=1<?= h(csrf_query()) ?>" class="alert-link" data-confirm="Importer les villes à partir des adresses ?">Importer</a></li>
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

$tabapproved = array(0 => "", 1 => "", 2 => "");
$tabapproved[$approved] = "active";

$approvedcount = array(0 => 0, 1 => 0, 2 => 0);
$query_count_tabs = mysqli_query($db, "SELECT obs_approved, COUNT(*) AS nb FROM obs_list WHERE obs_complete=1" . $querysearch . " GROUP BY obs_approved");
while ($query_count_tabs && $result_count_tabs = mysqli_fetch_array($query_count_tabs)) {
  $tabkey = intval($result_count_tabs['obs_approved']);
  if (isset($approvedcount[$tabkey])) {
    $approvedcount[$tabkey] += intval($result_count_tabs['nb']);
  }
}

/* Check the list of resolved obs */
$resolutionslist = array();
$resolutions_query = mysqli_query($db, "SELECT resolution_token,resolution_id FROM obs_resolutions");
while ($resolutions_query && $resolutions_result = mysqli_fetch_array($resolutions_query)) {
  $resolutionslist[intval($resolutions_result['resolution_id'])] = $resolutions_result['resolution_token'];
}

$categorielist = getCategoriesList();
if (!is_array($categorielist)) {
  $categorielist = array();
}
$categorielist[] = array("catid" => 0, "catname" => "---");

$searchopen = ($filtertoken !== '' || $filteraddress !== '' || $searchcity != 0 || $searchcategory != 0);
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

<div class="card shadow-sm mb-3">
<div class="table-responsive">
  <table class="table table-hover align-middle table-admin mb-0">
    <thead class="table-light">
      <tr>
        <th style="width: 110px">Token</th>
        <th style="width: 180px">Photo</th>
        <th>Précisions</th>
        <th style="width: 280px">Localisation</th>
        <th style="width: 130px">Date / Heure</th>
        <th style="width: 220px" class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
<?php
if (count($observations) == 0) {
  echo '<tr><td colspan="6" class="text-center text-body-secondary py-4"><i class="bi bi-inbox"></i> Aucune observation</td></tr>';
}

foreach ($observations as $result_obs) {
  $obs_id    = intval($result_obs['obs_id']);
  $obs_token = (string) $result_obs['obs_token'];
  $obs_time  = intval($result_obs['obs_time']);
  $date  = date('d/m/Y', $obs_time);
  $heure = date('H:i', $obs_time);
  $can_act = obsadmin_can_act($result_obs, $current_role, $role_cityIds);
  $form_id = 'obs-form-' . $obs_id;
  $form_attr = $input_enabled == '' ? ' form="' . h($form_id) . '"' : '';

  $highlight_city = "";
  if ($current_role == 'citystaff' && in_array(intval($result_obs['obs_city']), $role_cityIds, true)) {
    $highlight_city = "table-info";
  }

  $obsinresolution_query = mysqli_query($db, "SELECT restok_observationid FROM obs_resolutions_tokens WHERE restok_observationid='" . $obs_id . "' LIMIT 1");
  $in_resolution = ($obsinresolution_query && mysqli_num_rows($obsinresolution_query) > 0);

  $panel_base = $config['HTTP_PROTOCOL'] . '://' . $config['URLBASE'] . '/generate_panel.php';
  $lat = (float) $result_obs['obs_coordinates_lat'];
  $lon = (float) $result_obs['obs_coordinates_lon'];
  $osm_url = 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lon . '#map=16/' . $lat . '/' . $lon . '&layers=N';
  $actionurl = '?page=' . urlencode($page_name) . '&approved=' . $approved . ($pagenb > 1 ? '&pagenb=' . $pagenb : '') . '&token=' . urlencode($obs_token) . '&obsid=' . $obs_id . $urlsuffix;
  $obs_notes = isset($notes[$obs_id]) ? $notes[$obs_id] : array();
?>
      <tr class="<?= h($highlight_city) ?>">
        <td>
          <code><?= h($obs_token) ?></code>
          <?php if ($in_resolution) { ?><br /><span class="badge text-bg-success mt-1"><i class="bi bi-check2-circle"></i> En résolution</span><?php } ?>
        </td>
        <td>
          <a href="<?= h($panel_base . '?s=800&token=' . urlencode($obs_token)) ?>" target="_blank" rel="noopener"><img class="obs-thumb" loading="lazy" alt="Observation <?= h($obs_token) ?>" src="<?= h($panel_base . '?s=200&token=' . urlencode($obs_token)) ?>" /></a>
        </td>
        <td>
          <div class="mb-2">
            <label for="obs_comment_<?= $obs_id ?>" class="form-label small fw-semibold mb-0">Commentaire</label>
            <input type="text" class="<?= $input_class ?>" id="obs_comment_<?= $obs_id ?>" name="obs_comment" value="<?= h($result_obs['obs_comment']) ?>"<?= $form_attr ?> <?= $input_enabled ?> />
          </div>
          <div class="mb-2">
            <label for="obs_categorie_<?= $obs_id ?>" class="form-label small fw-semibold mb-0">Catégorie</label>
            <select class="<?= $select_class ?>" id="obs_categorie_<?= $obs_id ?>" name="obs_categorie"<?= $form_attr ?> <?= $input_enabled ?>>
            <?php
            foreach ($categorielist as $categorie) {
              $catid = isset($categorie['catid']) ? intval($categorie['catid']) : 0;
              $catname = isset($categorie['catname']) ? $categorie['catname'] : '';
              $selected = (intval($result_obs['obs_categorie']) == $catid) ? ' selected' : '';
              echo '<option value="' . $catid . '"' . $selected . '>' . h($catname) . '</option>';
            }
            ?>
            </select>
          </div>
          <?php if (!$in_resolution) { ?>
          <div class="mb-2">
            <label for="resolution_add_<?= $obs_id ?>" class="form-label small fw-semibold mb-0">Lier à une résolution</label>
            <select class="<?= $select_class ?>" id="resolution_add_<?= $obs_id ?>" name="resolution_add"<?= $form_attr ?> <?= $input_enabled ?>>
              <option value="0" selected>---</option>
              <?php
              foreach ($resolutionslist as $resolutionid => $resolutiontoken) {
                echo '<option value="' . intval($resolutionid) . '">' . h($resolutiontoken) . '</option>';
              }
              ?>
            </select>
          </div>
          <?php } ?>

          <?php if ($can_act && in_array($current_role, $actions_acl['notes']['access'])) { ?>
          <div class="mt-2">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#notes-<?= $obs_id ?>" aria-expanded="false" aria-controls="notes-<?= $obs_id ?>">
              <i class="bi bi-sticky"></i> Notes <span class="badge rounded-pill text-bg-secondary"><?= h(count($obs_notes)) ?></span>
            </button>
            <div class="collapse mt-2" id="notes-<?= $obs_id ?>">
              <div class="card card-body bg-body-tertiary p-2">
                <p class="small text-body-secondary mb-2"><i class="bi bi-lock"></i> Notes privées des modérateurs, jamais publiées.</p>
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
                <form method="POST" action="<?= h($listurl) ?>" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="note_action" value="add" />
                  <input type="hidden" name="note_obsid" value="<?= $obs_id ?>" />
                  <label for="note_text_<?= $obs_id ?>" class="visually-hidden">Nouvelle note</label>
                  <textarea class="form-control form-control-sm mb-2" id="note_text_<?= $obs_id ?>" name="note_text" rows="2" maxlength="5000" placeholder="Ajouter une note privée…" required></textarea>
                  <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Ajouter la note</button>
                </form>
              </div>
            </div>
          </div>
          <?php } ?>
        </td>
        <td>
          <div class="mb-2">
            <label for="obs_address_string_<?= $obs_id ?>" class="form-label small fw-semibold mb-0">Rue</label>
            <a href="<?= h($osm_url) ?>" target="_blank" rel="noopener" class="small ms-1"><i class="bi bi-map"></i> Carte</a>
            <input type="text" class="<?= $input_class ?>" id="obs_address_string_<?= $obs_id ?>" name="obs_address_string" value="<?= h($result_obs['obs_address_string']) ?>" required<?= $form_attr ?> <?= $input_enabled ?> />
          </div>
          <?php if (!empty($result_obs['obs_cityname'])) { ?>
          <div class="mb-2">
            <label for="obs_cityname_<?= $obs_id ?>" class="form-label small fw-semibold mb-0">Ville <span class="badge text-bg-warning">non référencée</span></label>
            <input type="text" class="<?= $input_class ?>" id="obs_cityname_<?= $obs_id ?>" name="obs_cityname" value="<?= h($result_obs['obs_cityname']) ?>" required<?= $form_attr ?> <?= $input_enabled ?> />
          </div>
          <?php } else { ?>
          <div class="mb-2">
            <label for="obs_city_<?= $obs_id ?>" class="form-label small fw-semibold mb-0">Ville</label>
            <select class="<?= $select_class ?>" id="obs_city_<?= $obs_id ?>" name="obs_city"<?= $form_attr ?> <?= $input_enabled ?>>
            <?php
            foreach ($citylistname as $selectcityid => $selectcityname) {
              $selected = (intval($result_obs['obs_city']) == $selectcityid) ? ' selected' : '';
              echo '<option value="' . intval($selectcityid) . '"' . $selected . '>' . h($selectcityname) . '</option>';
            }
            if (intval($result_obs['obs_city']) == 0) {
              echo '<option value="0" selected>---</option>';
            }
            ?>
            </select>
          </div>
          <?php } ?>
        </td>
        <td>
          <label for="post_date_<?= $obs_id ?>" class="visually-hidden">Date</label>
          <input type="text" class="<?= $input_class ?> mb-1" id="post_date_<?= $obs_id ?>" name="post_date" value="<?= h($date) ?>" placeholder="jj/mm/aaaa" required<?= $form_attr ?> <?= $input_enabled ?> />
          <label for="post_heure_<?= $obs_id ?>" class="visually-hidden">Heure</label>
          <input type="text" class="<?= $input_class ?>" id="post_heure_<?= $obs_id ?>" name="post_heure" value="<?= h($heure) ?>" placeholder="hh:mm" required<?= $form_attr ?> <?= $input_enabled ?> />
        </td>
        <td class="text-end">
          <div class="d-grid gap-1">
          <?php // Droits réservés aux admins : approuver/désapprouver/résoudre/supprimer une observation
          if ($can_act && in_array($current_role, $actions_acl['edit']['access'])) { ?>
            <form method="POST" action="<?= h($listurl) ?>" id="<?= h($form_id) ?>" class="m-0 d-grid">
              <?= csrf_field() ?>
              <input type="hidden" name="obs_id" value="<?= $obs_id ?>" />
              <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-pencil"></i> Valider l'édition</button>
            </form>
          <?php }
          if ($can_act && in_array($current_role, $actions_acl['approve']['access'])) { ?>
            <div class="btn-group btn-group-sm" role="group" aria-label="Approuver">
              <a class="btn btn-outline-success" href="<?= h($actionurl . '&action=approve&approveto=1' . csrf_query()) ?>"><i class="bi bi-check-lg"></i> Approuver</a>
              <a class="btn btn-outline-success" href="<?= h($actionurl . '&action=approve&approveto=1&twitt=1' . csrf_query()) ?>" title="Approuver et tweeter"><i class="bi bi-twitter-x"></i><span class="visually-hidden">Approuver et tweeter</span></a>
            </div>
            <a class="btn btn-sm btn-outline-warning" href="<?= h($actionurl . '&action=approve&approveto=2' . csrf_query()) ?>"><i class="bi bi-x-lg"></i> Désapprouver</a>
          <?php }
          if ($can_act && in_array($current_role, $actions_acl['cleancache']['access'])) { ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($actionurl . '&action=cleancache' . csrf_query()) ?>"><i class="bi bi-hdd"></i> Effacer le cache</a>
          <?php }
          if ($can_act && in_array($current_role, $actions_acl['resolve']['access']) && !$in_resolution) { ?>
            <a class="btn btn-sm btn-outline-info" href="<?= h($actionurl . '&action=resolve' . csrf_query()) ?>"><i class="bi bi-eye"></i> Nouvelle résolution</a>
          <?php }
          if ($can_act && in_array($current_role, $actions_acl['delete']['access'])) { ?>
            <a class="btn btn-sm btn-outline-danger" href="<?= h($actionurl . '&action=delete' . csrf_query()) ?>" data-confirm="Merci de valider la suppression"><i class="bi bi-trash"></i> Supprimer</a>
          <?php } ?>
          </div>
        </td>
      </tr>
<?php
}
?>
    </tbody>
  </table>
</div>
</div>

<?php
if ($nbpages > 1) {
  $pageurl = '?page=' . urlencode($page_name) . '&approved=' . $approved . $urlsuffix . '&pagenb=';
  $previous_disabled = ($pagenb <= 1) ? ' disabled' : '';
  $next_disabled = ($pagenb >= $nbpages) ? ' disabled' : '';
?>
<nav aria-label="Pagination des observations">
  <ul class="pagination pagination-sm flex-wrap">
    <li class="page-item<?= $previous_disabled ?>">
      <a class="page-link" href="<?= h($pageurl . max(1, $pagenb - 1)) ?>"<?= $previous_disabled ? ' tabindex="-1" aria-disabled="true"' : '' ?>><i class="bi bi-chevron-left"></i><span class="visually-hidden">Précédent</span></a>
    </li>
<?php
  for ($i = 1; $i <= $nbpages; $i++) {
    $active = ($pagenb == $i);
?>
    <li class="page-item<?= $active ? ' active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><a class="page-link" href="<?= h($pageurl . $i) ?>"><?= $i ?></a></li>
<?php
  }
?>
    <li class="page-item<?= $next_disabled ?>">
      <a class="page-link" href="<?= h($pageurl . min($nbpages, $pagenb + 1)) ?>"<?= $next_disabled ? ' tabindex="-1" aria-disabled="true"' : '' ?>><i class="bi bi-chevron-right"></i><span class="visually-hidden">Suivant</span></a>
    </li>
  </ul>
</nav>
<?php
}
?>
