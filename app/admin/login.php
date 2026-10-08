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
require_once('../includes/security.php');

vigilo_session_start();
vigilo_admin_headers();

require_once('../includes/common.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
  $login = trim((string) $_POST['login']);
  $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

  if (!csrf_valid()) {
    $error = 'Session expirée, merci de réessayer.';
  } elseif (login_is_throttled($db, $login)) {
    $error = 'Trop de tentatives. Merci de réessayer dans quelques minutes.';
  } else {
    $login_query = mysqli_query($db, "SELECT * FROM obs_roles WHERE role_login = '" . mysqli_real_escape_string($db, $login) . "' AND (role_name='admin' OR role_name='citystaff') LIMIT 1");
    $login_result = $login_query ? mysqli_fetch_array($login_query) : null;
    $stored = $login_result ? (string) $login_result['role_password'] : '';

    $valid = false;
    $legacy = false;
    if ($stored !== '' && password_verify($password, $stored)) {
      $valid = true;
    } elseif ($stored !== '' && $password !== '' && hash_equals($stored, hash('sha256', $password))) {
      // Old unsalted SHA-256 hashes: accepted once, then replaced below
      $valid = true;
      $legacy = true;
    } else {
      // Same cost as a real check, so that unknown logins can not be told apart
      password_verify($password, '$2y$10$lROXUgN32msBEOanDWcY0u.Fi/EINxrDJaXi/JbTAL7pipZ42YLOO');
    }

    if ($valid) {
      if ($legacy || password_needs_rehash($stored, PASSWORD_DEFAULT)) {
        mysqli_query($db, "UPDATE obs_roles SET role_password='" . mysqli_real_escape_string($db, password_hash($password, PASSWORD_DEFAULT)) . "' WHERE role_id='" . intval($login_result['role_id']) . "'");
      }
      login_clear_failures($db, $login);
      session_regenerate_id(true);
      $_SESSION = array(
        'login' => $login_result['role_login'],
        'role' => $login_result['role_name'],
        'last_activity' => time()
      );
      audit_log('login');
      header('Location: index.php');
      exit;
    }

    login_record_failure($db, $login);
    $error = 'Login ou mot de passe incorrect.';
  }
}

$instance_name = isset($config['VIGILO_NAME']) && $config['VIGILO_NAME'] !== '' ? $config['VIGILO_NAME'] : 'Vigilo';
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connexion · <?= h($instance_name) ?> admin</title>
    <link rel="icon" href="vigilo.png">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/admin.css" rel="stylesheet">
    <script>
      (function () {
        var stored = null;
        try { stored = localStorage.getItem('vigilo-admin-theme'); } catch (e) {}
        document.documentElement.setAttribute('data-bs-theme', stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
      })();
    </script>
  </head>

  <body class="d-flex align-items-center py-4 bg-body-tertiary min-vh-100">
    <main class="form-signin w-100 m-auto">
      <form method="POST" autocomplete="on">
        <?= csrf_field() ?>
        <img class="mb-3" src="vigilo.png" alt="" width="64" height="64">
        <h1 class="h4 mb-1 fw-normal">Administration</h1>
        <p class="text-body-secondary mb-4"><?= h($instance_name) ?></p>
<?php if ($error) { ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
<?php } ?>
        <div class="form-floating">
          <input id="inputLogin" type="text" name="login" class="form-control rounded-bottom-0" placeholder="Login" required autofocus autocomplete="username" value="<?= isset($_POST['login']) ? h($_POST['login']) : '' ?>">
          <label for="inputLogin">Login</label>
        </div>
        <div class="form-floating mb-3">
          <input id="inputPassword" type="password" name="password" class="form-control rounded-top-0" placeholder="Mot de passe" required autocomplete="current-password">
          <label for="inputPassword">Mot de passe</label>
        </div>
        <button class="btn btn-primary w-100 py-2" type="submit">Se connecter</button>
        <p class="mt-5 mb-3 text-body-secondary small">Vigilo <?= h(BACKEND_VERSION) ?> · &copy; 2017-<?= date('Y') ?></p>
      </form>
    </main>
  </body>
</html>
