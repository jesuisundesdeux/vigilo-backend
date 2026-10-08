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

define('ACCOUNT_PASSWORD_MIN_LENGTH', 10);
define('ACCOUNT_ROLE_CITY_MAX_LENGTH', 255);

$account_roles = array('guest', 'admin', 'citystaff', 'moderator');

/*
 * Reads obs_roles.role_city: a JSON array of city names, or the legacy
 * comma separated format. Returns a clean list (trimmed, no empty value, no duplicate).
 */
if (!function_exists('account_parse_role_cities')) {
    function account_parse_role_cities($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }
        $items   = null;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $items = $decoded;
        } elseif (is_string($decoded)) {
            $items = explode(',', $decoded);
        } else {
            $items = explode(',', $raw);
        }
        return account_clean_city_list($items);
    }
}

if (!function_exists('account_clean_city_list')) {
    function account_clean_city_list($items)
    {
        $clean = array();
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '' && !in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }
        return $clean;
    }
}

if (!function_exists('account_mask_key')) {
    function account_mask_key($key)
    {
        $key = (string) $key;
        $len = strlen($key);
        if ($len == 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($key, 0, 4) . str_repeat('•', 8) . substr($key, -4);
    }
}

if (!function_exists('account_new_key')) {
    function account_new_key()
    {
        return strtoupper(bin2hex(random_bytes(20)));
    }
}

$messages      = array();
$reopen        = null;
$current_login = isset($_SESSION['login']) ? (string) $_SESSION['login'] : '';

/* Existing cities, used to validate the citystaff selection */
$city_names  = array();
$city_query  = mysqli_query($db, "SELECT city_name FROM obs_cities ORDER BY city_name");
if ($city_query) {
    while ($city_row = mysqli_fetch_array($city_query)) {
        $city_names[] = trim((string) $city_row['city_name']);
    }
}

/* Action link: delete */
if (isset($_GET['action']) && !isset($_POST['role_id'])) {
    if ($_GET['action'] == 'delete' && isset($_GET['roleid']) && is_numeric($_GET['roleid'])) {
        $roleid      = intval($_GET['roleid']);
        $query_check = mysqli_query($db, "SELECT role_id, role_login, role_name FROM obs_roles WHERE role_id = " . $roleid . " LIMIT 1");
        $check       = $query_check ? mysqli_fetch_array($query_check) : null;
        if (!$check) {
            $messages[] = array('warning', 'Compte <strong>#' . $roleid . '</strong> introuvable.');
        } elseif ($current_login !== '' && (string) $check['role_login'] === $current_login) {
            $messages[] = array('danger', 'Vous ne pouvez pas supprimer votre propre compte.');
        } elseif (mysqli_query($db, "DELETE FROM obs_roles WHERE role_id = " . $roleid)) {
            audit_log('account_delete', 'role:' . $roleid, array('role_login' => $check['role_login'], 'role_name' => $check['role_name']));
            $messages[] = array('success', 'Compte <strong>#' . $roleid . '</strong> supprimé.');
        } else {
            $messages[] = array('danger', 'Impossible de supprimer le compte <strong>#' . $roleid . '</strong>.');
        }
    }
}

/* POST: regenerate the API key */
if (isset($_POST['role_id']) && isset($_POST['key_regenerate'])) {
    $roleid      = intval($_POST['role_id']);
    $query_check = mysqli_query($db, "SELECT role_id FROM obs_roles WHERE role_id = " . $roleid . " LIMIT 1");
    if ($query_check && mysqli_fetch_array($query_check)) {
        $new_key = account_new_key();
        if (mysqli_query($db, "UPDATE obs_roles SET role_key = '" . mysqli_real_escape_string($db, $new_key) . "' WHERE role_id = " . $roleid)) {
            audit_log('account_key_regenerate', 'role:' . $roleid);
            $messages[] = array('success', 'Nouvelle clé générée pour le compte <strong>#' . $roleid . '</strong>.');
        } else {
            $messages[] = array('danger', 'Impossible de régénérer la clé du compte <strong>#' . $roleid . '</strong>.');
        }
    } else {
        $messages[] = array('warning', 'Compte <strong>#' . $roleid . '</strong> introuvable.');
    }
} elseif (isset($_POST['role_id'])) {
    /* POST: create (role_id 0) or edit an account. Only the columns below can be changed. */
    $roleid      = intval($_POST['role_id']);
    $creating    = ($roleid === 0);
    if ($creating) {
        $old = array('role_name' => '', 'role_owner' => '', 'role_login' => '', 'role_password' => '', 'role_city' => '');
    } else {
        $query_check = mysqli_query($db, "SELECT * FROM obs_roles WHERE role_id = " . $roleid . " LIMIT 1");
        $old         = $query_check ? mysqli_fetch_array($query_check) : null;
    }
    $errors      = array();
    $set         = array();
    $changes     = array();

    if (!$old) {
        $errors[] = 'Compte <strong>#' . $roleid . '</strong> introuvable.';
    } else {
        $is_self = $current_login !== '' && (string) $old['role_login'] === $current_login;

        // Role
        if (isset($_POST['role_name']) && is_string($_POST['role_name'])) {
            $role_name = $_POST['role_name'];
            if (!in_array($role_name, $account_roles, true)) {
                $errors[] = 'Rôle inconnu.';
            } elseif ($is_self && $role_name !== 'admin') {
                $errors[] = 'Vous ne pouvez pas retirer le rôle admin de votre propre compte.';
            } elseif ($role_name !== (string) $old['role_name']) {
                $set['role_name']     = $role_name;
                $changes['role_name'] = $role_name;
            }
        }

        // Owner (user name)
        if (isset($_POST['role_owner']) && is_string($_POST['role_owner'])) {
            $role_owner = trim($_POST['role_owner']);
            if (strlen($role_owner) > 255) {
                $errors[] = 'Le nom d\'utilisateur est trop long (255 caractères maximum).';
            } elseif ($role_owner !== (string) $old['role_owner']) {
                $set['role_owner']     = $role_owner;
                $changes['role_owner'] = $role_owner;
            }
        }

        // Login
        if (isset($_POST['role_login']) && is_string($_POST['role_login'])) {
            $role_login = trim($_POST['role_login']);
            if (strlen($role_login) > 60) {
                $errors[] = 'Le login est trop long (60 caractères maximum).';
            } elseif ($role_login !== (string) $old['role_login']) {
                if ($role_login !== '') {
                    $query_dup = mysqli_query($db, "SELECT role_id FROM obs_roles WHERE role_login = '" . mysqli_real_escape_string($db, $role_login) . "' AND role_id <> " . $roleid . " LIMIT 1");
                    if ($query_dup && mysqli_fetch_array($query_dup)) {
                        $errors[] = 'Le login <strong>' . h($role_login) . '</strong> est déjà utilisé par un autre compte.';
                    }
                }
                $set['role_login']     = $role_login;
                $changes['role_login'] = $role_login;
            }
        }

        // Password: empty field keeps the current password
        if (isset($_POST['role_password']) && is_string($_POST['role_password']) && $_POST['role_password'] !== '') {
            $password = $_POST['role_password'];
            $length   = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
            if ($length < ACCOUNT_PASSWORD_MIN_LENGTH) {
                $errors[] = 'Le mot de passe doit contenir au moins ' . ACCOUNT_PASSWORD_MIN_LENGTH . ' caractères.';
            } else {
                $set['role_password']     = password_hash($password, PASSWORD_DEFAULT);
                $changes['role_password'] = '(modifié)';
            }
        }

        if ($creating && !isset($set['role_name'])) {
            $errors[] = 'Le rôle est obligatoire.';
        }
        // A login without password could not sign in
        $final_login = isset($set['role_login']) ? $set['role_login'] : (string) $old['role_login'];
        if ($final_login !== '' && ($creating || isset($set['role_login'])) && !isset($set['role_password']) && (string) $old['role_password'] === '') {
            $errors[] = 'Un mot de passe est obligatoire pour un compte avec login.';
        }

        // Cities of a citystaff (#237, #270): clean JSON array of city names
        $final_role = isset($set['role_name']) ? $set['role_name'] : (string) $old['role_name'];
        if (isset($_POST['role_city_present']) && $final_role === 'citystaff') {
            $posted_cities = (isset($_POST['role_city']) && is_array($_POST['role_city'])) ? $_POST['role_city'] : array();
            $allowed       = array_merge($city_names, account_parse_role_cities($old['role_city']));
            $selected      = array();
            foreach (account_clean_city_list($posted_cities) as $city) {
                if (in_array($city, $allowed, true)) {
                    $selected[] = $city;
                }
            }
            $role_city = json_encode(array_values($selected));
            if ($role_city === false) {
                $errors[] = 'Liste de villes invalide.';
            } elseif (strlen($role_city) > ACCOUNT_ROLE_CITY_MAX_LENGTH) {
                $errors[] = 'Trop de villes sélectionnées : la liste enregistrée dépasse ' . ACCOUNT_ROLE_CITY_MAX_LENGTH . ' caractères. Merci de réduire la sélection.';
            } elseif ($selected !== account_parse_role_cities($old['role_city']) || $role_city !== (string) $old['role_city']) {
                $set['role_city']     = $role_city;
                $changes['role_city'] = $selected;
            }
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            $messages[] = array('danger', $error);
        }
        $messages[] = array('warning', $creating ? 'Le compte n\'a pas été ajouté.' : 'Le compte <strong>#' . $roleid . '</strong> n\'a pas été modifié.');
        // Reopens the window with what was typed (never the password)
        $reopen = array('role_id' => $roleid, 'role_password' => '', 'role_city' => array());
        foreach (array('role_name', 'role_owner', 'role_login') as $field) {
            $reopen[$field] = isset($_POST[$field]) && is_string($_POST[$field]) ? $_POST[$field] : '';
        }
        if (isset($_POST['role_city']) && is_array($_POST['role_city'])) {
            $reopen['role_city'] = account_clean_city_list($_POST['role_city']);
        }
        if (!$creating) {
            $reopen['is_self'] = $is_self;
            if ($is_self) {
                $reopen['role_name'] = (string) $old['role_name'];
            }
        }
    } elseif ($creating) {
        $set['role_key'] = account_new_key();
        $columns = array();
        $inserts = array();
        foreach (array_merge($old, $set) as $column => $value) {
            // $column comes from the allowlist above, never from the POST keys
            $columns[] = $column;
            $inserts[] = "'" . mysqli_real_escape_string($db, $value) . "'";
        }
        if (mysqli_query($db, "INSERT INTO obs_roles (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $inserts) . ")")) {
            $new_id = intval(mysqli_insert_id($db));
            audit_log('account_create', 'role:' . $new_id, $changes);
            $messages[] = array('success', 'Compte <strong>#' . $new_id . '</strong> (' . h($set['role_name']) . ') ajouté.');
        } else {
            $messages[] = array('danger', 'Impossible d\'ajouter le compte.');
        }
    } elseif (empty($set)) {
        $messages[] = array('info', 'Aucune modification pour le compte <strong>#' . $roleid . '</strong>.');
    } else {
        $parts = array();
        foreach ($set as $column => $value) {
            // $column comes from the allowlist above, never from the POST keys
            $parts[] = $column . " = '" . mysqli_real_escape_string($db, $value) . "'";
        }
        if (mysqli_query($db, "UPDATE obs_roles SET " . implode(', ', $parts) . " WHERE role_id = " . $roleid)) {
            audit_log('account_edit', 'role:' . $roleid, $changes);
            if ($is_self && isset($set['role_login'])) {
                $_SESSION['login'] = $set['role_login'];
                $current_login     = $set['role_login'];
            }
            $messages[] = array('success', 'Compte <strong>#' . $roleid . '</strong> mis à jour.');
        } else {
            $messages[] = array('danger', 'Impossible de mettre à jour le compte <strong>#' . $roleid . '</strong>.');
        }
    }
}

if (isset($_GET['ask_pwd_update'])) {
    echo '<div class="alert alert-info d-flex gap-2" role="alert"><i class="bi bi-info-circle"></i><div>Votre mot de passe utilise un ancien format. Il a été converti automatiquement, mais il est recommandé d\'en définir un nouveau (au moins ' . ACCOUNT_PASSWORD_MIN_LENGTH . ' caractères) avec le bouton « Modifier » de votre compte ci-dessous.</div></div>';
}

foreach ($messages as $message) {
    // Messages are built above from escaped / integer values only
    echo '<div class="alert alert-' . h($message[0]) . '" role="alert">' . $message[1] . '</div>';
}

$query_role = mysqli_query($db, "SELECT * FROM obs_roles ORDER BY role_id");
$self_url   = '?page=' . urlencode($page_name);
?>
<div class="card shadow-sm">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="h5 mb-0"><i class="bi bi-people"></i> Comptes</h2>
    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#accountModal" data-title="Ajouter un compte"
            data-fill="<?= h(json_encode(array('role_id' => 0, 'role_name' => 'moderator', 'role_owner' => '', 'role_login' => '', 'role_password' => '', 'role_city' => array(), 'is_self' => false))) ?>"><i class="bi bi-person-plus"></i> Ajouter un compte</button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle table-admin mb-0">
      <thead>
        <tr>
          <th scope="col" class="d-none d-md-table-cell">#</th>
          <th scope="col">Rôle</th>
          <th scope="col">Nom utilisateur</th>
          <th scope="col" class="d-none d-sm-table-cell">Login</th>
          <th scope="col" class="d-none d-lg-table-cell">Villes</th>
          <th scope="col" class="d-none d-xl-table-cell">Clé API</th>
          <th scope="col" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
<?php
$forms         = '';
$role_badges   = array('admin' => 'danger', 'moderator' => 'primary', 'citystaff' => 'success', 'guest' => 'secondary');
$city_options  = $city_names;
while ($query_role && ($result_role = mysqli_fetch_array($query_role))) {
    $role_id     = intval($result_role['role_id']);
    $key_form    = 'role' . $role_id . 'keyform';
    $is_self     = $current_login !== '' && (string) $result_role['role_login'] === $current_login;
    $role_key    = (string) $result_role['role_key'];
    $role_name   = (string) $result_role['role_name'];
    $role_cities = $role_name === 'citystaff' ? account_parse_role_cities($result_role['role_city']) : array();
    foreach ($role_cities as $role_city) {
        if (!in_array($role_city, $city_options, true)) {
            $city_options[] = $role_city;
        }
    }
    $role_fill   = array('role_id' => $role_id, 'role_name' => $role_name, 'role_owner' => (string) $result_role['role_owner'],
                         'role_login' => (string) $result_role['role_login'], 'role_password' => '', 'role_city' => $role_cities, 'is_self' => $is_self);

    $forms .= '<form method="POST" action="' . h($self_url) . '" id="' . h($key_form) . '">'
            . csrf_field()
            . '<input type="hidden" name="role_id" value="' . $role_id . '" />'
            . '<input type="hidden" name="key_regenerate" value="1" /></form>';
?>
        <tr<?= $is_self ? ' class="table-active"' : '' ?>>
          <td class="d-none d-md-table-cell text-body-secondary">#<?= $role_id ?></td>
          <td class="text-nowrap">
            <span class="badge text-bg-<?= h(isset($role_badges[$role_name]) ? $role_badges[$role_name] : 'warning') ?>"><?= h($role_name) ?></span>
            <?php if ($is_self) { ?><span class="badge text-bg-light border ms-1">vous</span><?php } ?>
          </td>
          <td><?= h($result_role['role_owner']) ?></td>
          <td class="d-none d-sm-table-cell"><?= $result_role['role_login'] !== '' ? '<code>' . h($result_role['role_login']) . '</code>' : '<span class="text-body-secondary small">clé API seule</span>' ?></td>
          <td class="d-none d-lg-table-cell small">
            <?php if ($role_name === 'citystaff') { echo $role_cities ? h(implode(', ', $role_cities)) : '<span class="badge text-bg-warning">aucune ville</span>'; } else { echo '<span class="text-body-secondary">—</span>'; } ?>
          </td>
          <td class="d-none d-xl-table-cell text-nowrap">
<?php if ($role_key === '') { ?>
            <span class="badge text-bg-warning">aucune clé</span>
<?php } else { ?>
            <code class="account-key" data-key="<?= h($role_key) ?>" data-masked="<?= h(account_mask_key($role_key)) ?>"><?= h(account_mask_key($role_key)) ?></code>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-1 account-key-toggle" title="Afficher / masquer la clé" aria-label="Afficher la clé"><i class="bi bi-eye"></i></button>
<?php } ?>
            <button type="submit" class="btn btn-sm btn-outline-warning ms-1" form="<?= h($key_form) ?>" title="Générer une nouvelle clé" aria-label="Générer une nouvelle clé" data-confirm="Générer une nouvelle clé API pour le compte #<?= $role_id ?> ? L'ancienne clé ne fonctionnera plus."><i class="bi bi-arrow-repeat"></i></button>
          </td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#accountModal"
                    data-title="Modifier le compte #<?= $role_id ?>" data-fill="<?= h(json_encode($role_fill)) ?>"><i class="bi bi-pencil"></i><span class="d-none d-lg-inline"> Modifier</span></button>
<?php if (!$is_self) { ?>
            <a class="btn btn-sm btn-outline-danger" href="<?= h($self_url) ?>&amp;action=delete&amp;roleid=<?= $role_id ?><?= h(csrf_query()) ?>" data-confirm="Merci de valider la suppression du compte #<?= $role_id ?>" title="Supprimer"><i class="bi bi-trash"></i><span class="d-none d-lg-inline"> Supprimer</span></a>
<?php } ?>
          </td>
        </tr>
<?php
}
?>
      </tbody>
    </table>
  </div>
  <div class="card-body py-2">
    <p class="small text-body-secondary mb-0 d-xl-none">La clé API de chaque compte s'affiche sur un écran plus large.</p>
  </div>
</div>
<?= $forms ?>

<div class="modal fade" id="accountModal" tabindex="-1" aria-labelledby="accountModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="POST" action="<?= h($self_url) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="role_id" value="0" />
      <input type="hidden" name="role_city_present" value="1" />
      <div class="modal-header">
        <h2 class="modal-title h5" id="accountModalTitle">Compte</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label" for="role_name">Rôle</label>
            <select class="form-select" id="role_name" name="role_name" required>
              <?php foreach ($account_roles as $value) { ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php } ?>
            </select>
            <div class="form-text" data-account-self hidden>Votre propre rôle admin ne peut pas être retiré.</div>
          </div>
          <div class="col-md-7">
            <label class="form-label" for="role_owner">Nom utilisateur</label>
            <input type="text" class="form-control" id="role_owner" name="role_owner" maxlength="255" />
          </div>
          <div class="col-md-5">
            <label class="form-label" for="role_login">Login</label>
            <input type="text" class="form-control" id="role_login" name="role_login" maxlength="60" autocomplete="off" />
            <div class="form-text">Vide : compte utilisable seulement avec sa clé API.</div>
          </div>
          <div class="col-md-7">
            <label class="form-label" for="role_password">Mot de passe</label>
            <input type="password" class="form-control" id="role_password" name="role_password" minlength="<?= intval(ACCOUNT_PASSWORD_MIN_LENGTH) ?>" autocomplete="new-password" />
            <div class="form-text" data-account-password></div>
          </div>
          <div class="col-12" data-account-cities>
            <label class="form-label" for="role_city">Villes du compte citystaff</label>
            <select class="form-select" id="role_city" name="role_city[]" multiple size="<?= intval(min(8, max(3, count($city_options)))) ?>">
              <?php foreach ($city_options as $city) { ?>
                <option value="<?= h($city) ?>"><?= h(in_array($city, $city_names, true) ? $city : $city . ' (ville inconnue)') ?></option>
              <?php } ?>
            </select>
            <div class="form-text">Ctrl / Cmd + clic pour une sélection multiple.</div>
          </div>
        </div>
        <p class="small text-body-secondary mt-3 mb-0" data-account-new>Une clé API est générée à la création du compte.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php if ($reopen !== null) { ?>
<div hidden data-reopen-modal="#accountModal" data-title="<?= $reopen['role_id'] ? 'Modifier le compte #' . intval($reopen['role_id']) : 'Ajouter un compte' ?>" data-fill="<?= h(json_encode($reopen)) ?>"></div>
<?php } ?>

<script>
  // Account window: cities only for a citystaff, own role locked, password hint
  (function () {
    var form = document.querySelector('#accountModal form');
    var role = form.elements['role_name'];
    var cities = form.querySelector('[data-account-cities]');
    function sync() { cities.hidden = role.value !== 'citystaff'; }
    role.addEventListener('change', sync);
    form.addEventListener('vigilo:filled', function (event) {
      var values = event.detail;
      var creating = String(values.role_id) === '0';
      role.disabled = values.is_self === true;
      form.querySelector('[data-account-self]').hidden = values.is_self !== true;
      form.querySelector('[data-account-new]').hidden = !creating;
      form.elements['role_password'].placeholder = creating ? '' : 'Inchangé';
      form.querySelector('[data-account-password]').textContent = (creating ? 'Obligatoire avec un login' : 'Vide : mot de passe inchangé')
        + ', au moins <?= intval(ACCOUNT_PASSWORD_MIN_LENGTH) ?> caractères.';
      sync();
    });
  })();

  // Show / hide the full API key
  (function () {
    document.querySelectorAll('.account-key-toggle').forEach(function (button) {
      button.addEventListener('click', function () {
        var code = button.parentNode.querySelector('.account-key');
        if (!code) {
          return;
        }
        var shown = code.getAttribute('data-shown') === '1';
        code.textContent = shown ? code.getAttribute('data-masked') : code.getAttribute('data-key');
        code.setAttribute('data-shown', shown ? '0' : '1');
        button.innerHTML = shown ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        button.setAttribute('aria-label', shown ? 'Afficher la clé' : 'Masquer la clé');
      });
    });
  })();
</script>
