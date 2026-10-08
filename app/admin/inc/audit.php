<?php
/*
Copyright (C) 2026 Velocité Montpellier

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

/*
 * Journal of privileged actions (#75).
 * Filters are named f_login / f_action: a GET parameter named "action" is
 * reserved to action links (CSRF checked by index.php).
 */
$audit_per_page = 50;

$f_login  = (isset($_GET['f_login']) && is_string($_GET['f_login'])) ? trim($_GET['f_login']) : '';
$f_action = (isset($_GET['f_action']) && is_string($_GET['f_action'])) ? trim($_GET['f_action']) : '';
$current_page = (isset($_GET['p']) && is_string($_GET['p']) && ctype_digit($_GET['p'])) ? max(1, (int) $_GET['p']) : 1;

/* Values of the filter selects */
$audit_logins = array();
$audit_actions = array();
$query = mysqli_query($db, "SELECT DISTINCT audit_login FROM obs_audit_log ORDER BY audit_login");
if ($query === false) {
  echo '<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle"></i> Le journal n\'est pas disponible : la table <code>obs_audit_log</code> est absente. Merci d\'appliquer les mises à jour de la base.</div>';
  return;
}
while ($row = mysqli_fetch_row($query)) {
  $audit_logins[] = (string) $row[0];
}
$query = mysqli_query($db, "SELECT DISTINCT audit_action FROM obs_audit_log ORDER BY audit_action");
if ($query) {
  while ($row = mysqli_fetch_row($query)) {
    $audit_actions[] = (string) $row[0];
  }
}

/* WHERE clause, all inputs escaped */
$where = array();
if ($f_login !== '') {
  $where[] = "audit_login = '" . mysqli_real_escape_string($db, $f_login) . "'";
}
if ($f_action !== '') {
  $where[] = "audit_action = '" . mysqli_real_escape_string($db, $f_action) . "'";
}
$where_sql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

$total = 0;
$query = mysqli_query($db, "SELECT COUNT(*) FROM obs_audit_log" . $where_sql);
if ($query) {
  $total = (int) mysqli_fetch_row($query)[0];
}
$page_count = max(1, (int) ceil($total / $audit_per_page));
if ($current_page > $page_count) {
  $current_page = $page_count;
}
$offset = ($current_page - 1) * $audit_per_page;

$entries = array();
$query = mysqli_query($db, "SELECT audit_id, audit_time, audit_login, audit_role, audit_ip, audit_action, audit_target, audit_details
                            FROM obs_audit_log" . $where_sql . "
                            ORDER BY audit_time DESC, audit_id DESC
                            LIMIT " . (int) $offset . ", " . (int) $audit_per_page);
if ($query) {
  while ($row = mysqli_fetch_assoc($query)) {
    $entries[] = $row;
  }
}

/* URL of a page of the journal, keeping the filters */
$audit_url = function ($p) use ($page_name, $f_login, $f_action) {
  $params = array('page' => $page_name);
  if ($f_login !== '') {
    $params['f_login'] = $f_login;
  }
  if ($f_action !== '') {
    $params['f_action'] = $f_action;
  }
  if ($p > 1) {
    $params['p'] = (int) $p;
  }
  return '?' . http_build_query($params);
};

/* Badge color from the action name */
$audit_badge = function ($action) {
  $action = strtolower((string) $action);
  if (strpos($action, 'delete') !== false || strpos($action, 'fail') !== false) {
    return 'text-bg-danger';
  }
  if (strpos($action, 'create') !== false || strpos($action, 'add') !== false || strpos($action, 'approve') !== false) {
    return 'text-bg-success';
  }
  if (strpos($action, 'edit') !== false || strpos($action, 'update') !== false) {
    return 'text-bg-primary';
  }
  if (strpos($action, 'login') !== false || strpos($action, 'logout') !== false) {
    return 'text-bg-secondary';
  }
  return 'text-bg-info';
};

/* Details: pretty-printed when JSON */
$audit_details = function ($details) {
  $details = (string) $details;
  if ($details === '') {
    return '';
  }
  $decoded = json_decode($details, true);
  if (is_array($decoded)) {
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($pretty !== false) {
      return $pretty;
    }
  }
  return $details;
};
?>

<form class="row g-2 align-items-end mb-3" method="GET" action="">
  <input type="hidden" name="page" value="<?= h($page_name) ?>" />
  <div class="col-12 col-sm-auto">
    <label class="form-label small mb-1" for="f_login">Login</label>
    <select class="form-select form-select-sm" id="f_login" name="f_login">
      <option value="">Tous</option>
<?php foreach ($audit_logins as $login) { ?>
      <option value="<?= h($login) ?>"<?= $login === $f_login ? ' selected' : '' ?>><?= h($login === '' ? '(vide)' : $login) ?></option>
<?php } ?>
    </select>
  </div>
  <div class="col-12 col-sm-auto">
    <label class="form-label small mb-1" for="f_action">Action</label>
    <select class="form-select form-select-sm" id="f_action" name="f_action">
      <option value="">Toutes</option>
<?php foreach ($audit_actions as $action) { ?>
      <option value="<?= h($action) ?>"<?= $action === $f_action ? ' selected' : '' ?>><?= h($action) ?></option>
<?php } ?>
    </select>
  </div>
  <div class="col-12 col-sm-auto d-flex gap-2">
    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtrer</button>
<?php if ($f_login !== '' || $f_action !== '') { ?>
    <a class="btn btn-outline-secondary btn-sm" href="?page=<?= h(rawurlencode($page_name)) ?>"><i class="bi bi-x-lg"></i> Réinitialiser</a>
<?php } ?>
  </div>
  <div class="col-12 col-sm text-sm-end small text-body-secondary">
    <?= h($total) ?> entrée<?= $total > 1 ? 's' : '' ?>
  </div>
</form>

<?php if (empty($entries)) { ?>
<div class="alert alert-secondary" role="alert">Aucune entrée dans le journal.</div>
<?php } else { ?>
<div class="table-responsive">
  <table class="table table-striped table-sm table-admin align-middle">
    <thead>
      <tr>
        <th scope="col">Date</th>
        <th scope="col">Login</th>
        <th scope="col">Rôle</th>
        <th scope="col">IP</th>
        <th scope="col">Action</th>
        <th scope="col">Cible</th>
        <th scope="col">Détails</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($entries as $entry) {
    $details = $audit_details($entry['audit_details']);
    ?>
      <tr>
        <td class="text-nowrap"><?= h(date('d/m/Y H:i:s', (int) $entry['audit_time'])) ?></td>
        <td><?= h($entry['audit_login']) ?></td>
        <td><?= h($entry['audit_role']) ?></td>
        <td class="font-monospace small"><?= h($entry['audit_ip']) ?></td>
        <td><span class="badge <?= h($audit_badge($entry['audit_action'])) ?>"><?= h($entry['audit_action']) ?></span></td>
        <td><?= h($entry['audit_target']) ?></td>
        <td><?php if ($details !== '') { ?><pre class="audit-details small mb-0"><?= h($details) ?></pre><?php } ?></td>
      </tr>
<?php } ?>
    </tbody>
  </table>
</div>

<?php if ($page_count > 1) {
    $window = 2;
    $pages = array(1);
    for ($i = max(2, $current_page - $window); $i <= min($page_count - 1, $current_page + $window); $i++) {
        $pages[] = $i;
    }
    $pages[] = $page_count;
    $pages = array_values(array_unique($pages));
    ?>
<nav aria-label="Pagination du journal">
  <ul class="pagination pagination-sm flex-wrap">
    <li class="page-item<?= $current_page <= 1 ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= h($audit_url(max(1, $current_page - 1))) ?>" aria-label="Précédent"><i class="bi bi-chevron-left"></i></a>
    </li>
<?php
    $previous = 0;
    foreach ($pages as $p) {
        if ($previous && $p > $previous + 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        if ($p === $current_page) {
            echo '<li class="page-item active" aria-current="page"><span class="page-link">' . h($p) . '</span></li>';
        } else {
            echo '<li class="page-item"><a class="page-link" href="' . h($audit_url($p)) . '">' . h($p) . '</a></li>';
        }
        $previous = $p;
    }
    ?>
    <li class="page-item<?= $current_page >= $page_count ? ' disabled' : '' ?>">
      <a class="page-link" href="<?= h($audit_url(min($page_count, $current_page + 1))) ?>" aria-label="Suivant"><i class="bi bi-chevron-right"></i></a>
    </li>
  </ul>
</nav>
<?php } ?>
<?php } ?>
