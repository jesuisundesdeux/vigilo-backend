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

/*
 * First password (or new password) of an account, from the one-time link generated in the
 * page Comptes (includes/password_links.php). No session needed; the link is used once.
 */
require_once('../includes/security.php');

vigilo_session_start();
vigilo_admin_headers();
// The token is in the address: never sent to another site, never cached
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

require_once('../includes/version.php');
require_once('../includes/common.php');
require_once('../includes/functions.php');
require_once('../includes/password_links.php');

$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
$link  = password_link_find($db, $token);
$error = '';
$done  = false;

if ($link && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
  $confirm  = isset($_POST['password_confirm']) && is_string($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
  $length   = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
  if (!csrf_valid()) {
    $error = 'Session expirée, merci de réessayer.';
  } elseif ($length < VIGILO_PASSWORD_MIN_LENGTH) {
    $error = 'Le mot de passe doit contenir au moins ' . VIGILO_PASSWORD_MIN_LENGTH . ' caractères.';
  } elseif (!hash_equals($password, $confirm)) {
    $error = 'Les deux mots de passe sont différents.';
  } elseif (password_link_use($db, $link, $password)) {
    audit_log('account_password_set', 'role:' . intval($link['link_roleid']), array('role_login' => $link['role_login'], 'link' => intval($link['link_id'])));
    $done = true;
  } else {
    $error = 'Ce lien a déjà été utilisé.';
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
    <title>Mot de passe · <?= h($instance_name) ?> admin</title>
    <link rel="icon" href="vigilo.png">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/admin.css?v=<?= intval(@filemtime(__DIR__ . '/assets/admin.css')) ?>" rel="stylesheet">
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
      <img class="mb-3" src="vigilo.png" alt="" width="64" height="64">
      <h1 class="h4 mb-1 fw-normal">Choisir votre mot de passe</h1>
      <p class="text-body-secondary mb-4"><?= h($instance_name) ?></p>
<?php if ($done) { ?>
      <div class="alert alert-success" role="alert">Mot de passe enregistré. Vous pouvez maintenant vous connecter.</div>
      <a class="btn btn-primary w-100 py-2" href="login.php">Se connecter</a>
<?php } elseif (!$link) { ?>
      <div class="alert alert-warning" role="alert">Ce lien n'est pas valide : il a déjà été utilisé, il a expiré (<?= intval(VIGILO_PASSWORD_LINK_HOURS) ?> heures) ou un nouveau lien l'a remplacé. Demandez un nouveau lien à un administrateur.</div>
      <a class="btn btn-outline-secondary w-100 py-2" href="login.php">Page de connexion</a>
<?php } else { ?>
      <form method="POST" action="password.php?token=<?= h(rawurlencode($token)) ?>" autocomplete="off">
        <?= csrf_field() ?>
<?php if ($error) { ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
<?php } ?>
        <div class="mb-2">
          <label class="form-label small mb-1" for="inputLogin">Login</label>
          <input id="inputLogin" type="text" class="form-control" value="<?= h($link['role_login']) ?>" readonly autocomplete="username">
        </div>
        <div class="form-floating">
          <input id="inputPassword" type="password" name="password" class="form-control rounded-bottom-0" placeholder="Nouveau mot de passe" required minlength="<?= intval(VIGILO_PASSWORD_MIN_LENGTH) ?>" autofocus autocomplete="new-password">
          <label for="inputPassword">Nouveau mot de passe</label>
        </div>
        <div class="form-floating mb-2">
          <input id="inputConfirm" type="password" name="password_confirm" class="form-control rounded-top-0" placeholder="Confirmation" required minlength="<?= intval(VIGILO_PASSWORD_MIN_LENGTH) ?>" autocomplete="new-password">
          <label for="inputConfirm">Confirmation</label>
        </div>
        <p class="form-text mb-3">Au moins <?= intval(VIGILO_PASSWORD_MIN_LENGTH) ?> caractères. Ce lien ne sert qu'une fois ; il expire le <?= h(date('d/m/Y à H:i', intval($link['link_expires']))) ?>.</p>
        <button class="btn btn-primary w-100 py-2" type="submit">Enregistrer le mot de passe</button>
      </form>
<?php } ?>
      <p class="mt-5 mb-3 text-body-secondary small">Vigilo <?= h(BACKEND_VERSION) ?></p>
    </main>
  </body>
</html>
