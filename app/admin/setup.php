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

/*
 * Install assistant, opened by admin/login.php until the instance is installed:
 * 1. database: settings tested then written in config/config.php (classic install),
 *    or checked (Docker: MYSQL_* variables);
 * 2. schema: migrations of app/migrations (empty or older database);
 * 3. first admin account, instance name and URL (detected from the browser).
 * Every step needs the one-time install code (includes/setup.php). Once an admin
 * account exists, this page only sends to the login page.
 */
require_once('../includes/security.php');
require_once('../includes/version.php');
require_once('../includes/migrations.php');
require_once('../includes/setup.php');

vigilo_session_start();
vigilo_admin_headers();

define('SETUP_PASSWORD_MIN_LENGTH', 10);

$state = vigilo_setup_state();
if ($state['step'] === 'done') {
    vigilo_setup_code_remove();
    header('Location: login.php');
    exit;
}

$messages = array();
$docker   = vigilo_setup_is_docker();
list($code, $code_file) = vigilo_setup_code_ensure();
$code_error = $code === null ? $code_file : '';
$code_path  = $code !== null ? ltrim(str_replace(VIGILO_APP_DIR, '', $code_file), '/') : '';
$code_env   = $code !== null && $code_file === '';

/* Install code: once given, kept in the session */
$unlocked = $code !== null && !empty($_SESSION['setup_ok']) && hash_equals((string) $_SESSION['setup_ok'], hash('sha256', $code));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_code']) && $code !== null) {
    if (!csrf_valid()) {
        $messages[] = array('danger', 'Session expirée, merci de réessayer.');
    } elseif (vigilo_setup_code_valid($_POST['setup_code'])) {
        session_regenerate_id(true);
        $_SESSION['setup_ok'] = hash('sha256', $code);
        $unlocked = true;
    } else {
        sleep(1);
        $messages[] = array('danger', 'Code d\'installation incorrect.');
    }
}

$db_form = array('MYSQL_HOST' => 'localhost', 'MYSQL_USER' => '', 'MYSQL_PASSWORD' => '', 'MYSQL_DATABASE' => '');
$config_copy = '';
$action = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_action'])) ? (string) $_POST['setup_action'] : '';
if ($action !== '' && (!$unlocked || !csrf_valid())) {
    $messages[] = array('danger', 'Session expirée ou code d\'installation manquant.');
    $action = '';
}

/* 1. Database settings (classic install only: Docker reads the environment) */
if (in_array($action, array('db_test', 'db_save'), true) && $state['step'] === 'config' && !$state['config'] && !$docker) {
    foreach (array_keys($db_form) as $key) {
        $db_form[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    }
    $db_form['MYSQL_PASSWORD'] = isset($_POST['MYSQL_PASSWORD']) && is_string($_POST['MYSQL_PASSWORD']) ? $_POST['MYSQL_PASSWORD'] : '';
    $test = vigilo_setup_connect($db_form);
    if (!($test instanceof mysqli)) {
        $messages[] = array('danger', h($test));
    } else {
        $privileges = vigilo_setup_check_privileges($test);
        $tables     = mysqli_query($test, "SHOW TABLES");
        $count      = $tables ? mysqli_num_rows($tables) : 0;
        mysqli_close($test);
        if ($privileges !== '') {
            $messages[] = array('danger', h($privileges));
        } elseif ($action === 'db_test') {
            $messages[] = array('success', 'Connexion réussie : le compte peut créer les tables'
                . ($count ? ' (la base contient déjà ' . intval($count) . ' table(s)).' : ' (base vide).'));
        } else {
            $error = vigilo_setup_write_config($db_form);
            if ($error === '') {
                $messages[] = array('success', 'Configuration enregistrée dans config/config.php.');
                $state = vigilo_setup_state();
            } else {
                $config_copy = vigilo_setup_config_content($db_form);
                $messages[] = array('warning', h($error) . ' Créez le fichier <code>config/config.php</code> avec le contenu ci-dessous (FTP, gestionnaire de fichiers), puis rechargez cette page.');
            }
        }
    }
}

/* 2. Schema */
if ($action === 'migrate' && $state['step'] === 'database') {
    try {
        $applied = vigilo_migrate($state['db']);
        $messages[] = array('success', 'Base initialisée (version ' . h(BACKEND_VERSION) . ', ' . count($applied) . ' migration(s)).');
    } catch (Exception $e) {
        $messages[] = array('danger', 'Initialisation de la base en échec : ' . h($e->getMessage()));
    }
    $state = vigilo_setup_state();
}

/* 3. First admin account */
list($detected_base, $detected_proto) = vigilo_setup_detect_url();
$admin_form = array('name' => '', 'login' => 'admin', 'instance' => '', 'urlbase' => $detected_base, 'proto' => $detected_proto);
if ($action === 'admin' && $state['step'] === 'admin') {
    $db = $state['db'];
    foreach (array_keys($admin_form) as $key) {
        $admin_form[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    }
    $password  = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $password2 = isset($_POST['password2']) && is_string($_POST['password2']) ? $_POST['password2'] : '';
    $errors = array();
    if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $admin_form['login'])) {
        $errors[] = 'Le login doit contenir de 3 à 60 lettres, chiffres ou . _ @ -.';
    }
    if (strlen($admin_form['name']) > 255) {
        $errors[] = 'Le nom est trop long.';
    }
    if (strlen($password) < SETUP_PASSWORD_MIN_LENGTH) {
        $errors[] = 'Le mot de passe doit contenir au moins ' . SETUP_PASSWORD_MIN_LENGTH . ' caractères.';
    } elseif ($password !== $password2) {
        $errors[] = 'Les deux mots de passe sont différents.';
    }
    if ($admin_form['instance'] === '' || strlen($admin_form['instance']) > 100) {
        $errors[] = 'Le nom de l\'instance est obligatoire (100 caractères au plus).';
    }
    $admin_form['urlbase'] = preg_replace('#^https?://#i', '', rtrim($admin_form['urlbase'], '/'));
    if (!preg_match('#^[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~\-/]*)?$#', $admin_form['urlbase'])) {
        $errors[] = 'L\'URL de base doit être un nom de domaine, avec un chemin éventuel (exemple : vigilo.example.org).';
    }
    if (!in_array($admin_form['proto'], array('http', 'https'), true)) {
        $errors[] = 'Protocole invalide.';
    }
    if ($errors) {
        $messages[] = array('danger', 'Compte non créé :<ul class="mb-0"><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul>');
    } else {
        $ok = mysqli_query($db, "INSERT INTO obs_roles (role_key, role_name, role_owner, role_login, role_password, role_city)
                                 VALUES ('" . strtoupper(bin2hex(random_bytes(20))) . "', 'admin',
                                         '" . mysqli_real_escape_string($db, $admin_form['name']) . "',
                                         '" . mysqli_real_escape_string($db, $admin_form['login']) . "',
                                         '" . mysqli_real_escape_string($db, password_hash($password, PASSWORD_DEFAULT)) . "', '')");
        if (!$ok) {
            $messages[] = array('danger', 'Impossible de créer le compte : ' . h(mysqli_error($db)));
        } else {
            $settings = array('vigilo_name' => $admin_form['instance'], 'vigilo_urlbase' => $admin_form['urlbase'], 'vigilo_http_proto' => $admin_form['proto']);
            foreach ($settings as $param => $value) {
                $param = mysqli_real_escape_string($db, $param);
                $value = mysqli_real_escape_string($db, $value);
                $query = mysqli_query($db, "SELECT config_id FROM obs_config WHERE config_param = '" . $param . "' LIMIT 1");
                if ($query && mysqli_num_rows($query)) {
                    mysqli_query($db, "UPDATE obs_config SET config_value = '" . $value . "' WHERE config_param = '" . $param . "'");
                } else {
                    mysqli_query($db, "INSERT INTO obs_config (config_param, config_value) VALUES ('" . $param . "', '" . $value . "')");
                }
            }
            vigilo_setup_code_remove();
            session_regenerate_id(true);
            $_SESSION = array('login' => $admin_form['login'], 'role' => 'admin', 'last_activity' => time());
            $GLOBALS['db'] = $db;
            audit_log('setup_admin_create', 'login:' . $admin_form['login'], $settings);
            header('Location: index.php');
            exit;
        }
    }
}

$steps = array('config' => 'Base de données', 'database' => 'Initialisation de la base', 'admin' => 'Compte administrateur');
$current = $state['step'] === 'newer' ? 'database' : $state['step'];
$step_index = array_search($current, array_keys($steps), true);
$requirements = vigilo_setup_requirements();
?>
<!doctype html>
<html lang="fr" data-bs-theme="light">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation · Vigilo</title>
    <link rel="icon" href="vigilo.png">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/admin.css?v=<?= intval(@filemtime(__DIR__ . '/assets/admin.css')) ?>" rel="stylesheet">
    <script>
      (function () {
        var stored = null;
        try { stored = localStorage.getItem('vigilo-admin-theme'); } catch (e) {}
        document.documentElement.setAttribute('data-bs-theme', stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
      })();
    </script>
  </head>
  <body class="bg-body-tertiary">
    <main class="container py-4 setup-page" style="max-width: 760px;">
      <div class="d-flex align-items-center gap-3 mb-4">
        <img src="vigilo.png" alt="" width="56" height="56">
        <div>
          <h1 class="h3 mb-0">Installation de Vigilo</h1>
          <div class="text-body-secondary">Version <?= h(BACKEND_VERSION) ?> · <?= $docker ? 'image Docker' : 'installation classique' ?></div>
        </div>
      </div>

      <ol class="setup-steps list-unstyled d-flex flex-wrap gap-2 mb-4">
<?php $i = 0; foreach ($steps as $key => $label) { $done = $i < $step_index; $active = $i === $step_index; ?>
        <li class="badge rounded-pill <?= $done ? 'text-bg-success' : ($active ? 'text-bg-primary' : 'text-bg-secondary') ?> fs-6 fw-normal"<?= $active ? ' aria-current="step"' : '' ?>>
          <i class="bi <?= $done ? 'bi-check-lg' : 'bi-' . ($i + 1) . '-circle' ?>"></i> <?= h($label) ?>
        </li>
<?php $i++; } ?>
      </ol>

<?php foreach ($messages as $message) { ?>
      <div class="alert alert-<?= h($message[0]) ?>" role="alert"><?= $message[1] ?></div>
<?php } ?>

<?php if ($state['step'] === 'newer') { ?>
      <div class="alert alert-danger" role="alert">
        La base de données (version <?= h($state['db_version']) ?>) est plus récente que le code (<?= h(BACKEND_VERSION) ?>) :
        installez le code de la version <?= h($state['db_version']) ?> ou plus récente. Rien n'a été modifié.
      </div>
<?php } elseif ($code === null) { ?>
      <div class="alert alert-danger" role="alert"><?= h($code_error) ?></div>
<?php } elseif (!$unlocked) { ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h2 class="h5"><i class="bi bi-key"></i> Code d'installation</h2>
<?php if ($code_env) { ?>
          <p>Pour vérifier que vous administrez ce serveur, saisissez le code d'installation fourni par l'hébergeur de
            l'instance (variable <code>VIGILO_SETUP_CODE</code>).</p>
<?php } else { ?>
          <p>Pour vérifier que vous administrez ce serveur, saisissez le code d'installation. Il se trouve dans le fichier
            <code><?= h($code_path) ?></code> du serveur<?= $docker ? '' : ' (FTP, gestionnaire de fichiers de l\'hébergeur)' ?>.</p>
<?php } ?>
<?php if ($docker && !$code_env) { ?>
          <p class="small">Avec Docker, il est aussi affiché au démarrage du conteneur :
            <code>docker compose logs web | grep -i "code d'installation"</code>, ou
            <code>docker compose exec web cat <?= h($code_path) ?></code>.</p>
<?php } ?>
          <form method="POST" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-sm-8">
              <label class="form-label" for="setup_code">Code d'installation</label>
              <input class="form-control font-monospace" id="setup_code" name="setup_code" autocomplete="off" spellcheck="false" required autofocus>
            </div>
            <div class="col-sm-4"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-unlock"></i> Continuer</button></div>
          </form>
        </div>
      </div>
<?php } elseif ($state['step'] === 'config') { ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h2 class="h5"><i class="bi bi-database"></i> Base de données</h2>
<?php   if ($docker) { ?>
          <div class="alert alert-danger" role="alert">Connexion à la base impossible : <?= h($state['db_error']) ?></div>
          <p class="mb-0">Avec Docker, la base est définie par les variables <code>MYSQL_HOST</code>, <code>MYSQL_USER</code>,
            <code>MYSQL_PASSWORD</code> et <code>MYSQL_DATABASE</code> du fichier <code>.env</code>. Corrigez-les, redémarrez
            (<code>docker compose up -d</code>) puis rechargez cette page.</p>
<?php   } elseif ($state['config']) { ?>
          <div class="alert alert-danger" role="alert">Connexion à la base impossible avec <code>config/config.php</code> : <?= h($state['db_error']) ?></div>
          <p class="mb-0">Corrigez le fichier <code>config/config.php</code> (ou supprimez-le pour le recréer avec ce formulaire), puis rechargez cette page.</p>
<?php   } else { ?>
          <p>Renseignez la base MySQL / MariaDB créée chez votre hébergeur (vide de préférence). La connexion et les droits du
            compte sont testés avant l'enregistrement dans <code>config/config.php</code>.</p>
          <form method="POST" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <div class="col-md-6">
              <label class="form-label" for="MYSQL_HOST">Serveur</label>
              <input class="form-control" id="MYSQL_HOST" name="MYSQL_HOST" value="<?= h($db_form['MYSQL_HOST']) ?>" required>
              <div class="form-text">Souvent <code>localhost</code> ; <code>serveur:port</code> pour un port différent.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="MYSQL_DATABASE">Nom de la base</label>
              <input class="form-control" id="MYSQL_DATABASE" name="MYSQL_DATABASE" value="<?= h($db_form['MYSQL_DATABASE']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="MYSQL_USER">Utilisateur</label>
              <input class="form-control" id="MYSQL_USER" name="MYSQL_USER" value="<?= h($db_form['MYSQL_USER']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="MYSQL_PASSWORD">Mot de passe</label>
              <input class="form-control" type="password" id="MYSQL_PASSWORD" name="MYSQL_PASSWORD" autocomplete="new-password">
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
              <button class="btn btn-outline-primary" type="submit" name="setup_action" value="db_test"><i class="bi bi-plug"></i> Tester la connexion</button>
              <button class="btn btn-primary" type="submit" name="setup_action" value="db_save"><i class="bi bi-save"></i> Tester et enregistrer</button>
            </div>
          </form>
<?php     if ($config_copy !== '') { ?>
          <label class="form-label mt-3" for="config_copy">Contenu de <code>config/config.php</code></label>
          <textarea class="form-control font-monospace small" id="config_copy" rows="8" readonly><?= h($config_copy) ?></textarea>
<?php     } ?>
<?php   } ?>
        </div>
      </div>
<?php } elseif ($state['step'] === 'database') { ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h2 class="h5"><i class="bi bi-database-gear"></i> Initialisation de la base</h2>
          <p class="text-success"><i class="bi bi-check-circle"></i> Connexion à la base réussie.</p>
          <p><?= $state['db_version'] === '0.0.0'
                ? 'La base est vide : ses tables vont être créées avec les scripts de <code>migrations/</code>.'
                : 'La base est en version <strong>' . h($state['db_version']) . '</strong> : elle va être mise à jour.' ?>
            Version du code : <strong><?= h(BACKEND_VERSION) ?></strong>.</p>
          <form method="POST">
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit" name="setup_action" value="migrate"><i class="bi bi-play-fill"></i> <?= $state['db_version'] === '0.0.0' ? 'Initialiser la base' : 'Mettre à jour la base' ?></button>
          </form>
        </div>
      </div>
<?php } elseif ($state['step'] === 'admin') { ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h2 class="h5"><i class="bi bi-person-lock"></i> Compte administrateur et instance</h2>
          <p class="text-success"><i class="bi bi-check-circle"></i> Base de données à jour (version <?= h($state['db_version']) ?>).</p>
          <form method="POST" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="setup_action" value="admin">
            <div class="col-md-6">
              <label class="form-label" for="name">Votre nom</label>
              <input class="form-control" id="name" name="name" maxlength="255" value="<?= h($admin_form['name']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="login">Login</label>
              <input class="form-control" id="login" name="login" maxlength="60" required value="<?= h($admin_form['login']) ?>" autocomplete="username">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="password">Mot de passe</label>
              <input class="form-control" type="password" id="password" name="password" minlength="<?= SETUP_PASSWORD_MIN_LENGTH ?>" required autocomplete="new-password">
              <div class="form-text">Au moins <?= SETUP_PASSWORD_MIN_LENGTH ?> caractères.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="password2">Confirmation</label>
              <input class="form-control" type="password" id="password2" name="password2" minlength="<?= SETUP_PASSWORD_MIN_LENGTH ?>" required autocomplete="new-password">
            </div>
            <div class="col-12">
              <label class="form-label" for="instance">Nom de l'instance</label>
              <input class="form-control" id="instance" name="instance" maxlength="100" required value="<?= h($admin_form['instance']) ?>" placeholder="Vigilo Montpellier">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="proto">Protocole</label>
              <select class="form-select" id="proto" name="proto">
                <option value="https"<?= $admin_form['proto'] === 'https' ? ' selected' : '' ?>>https</option>
                <option value="http"<?= $admin_form['proto'] === 'http' ? ' selected' : '' ?>>http</option>
              </select>
            </div>
            <div class="col-md-9">
              <label class="form-label" for="urlbase">URL de base</label>
              <input class="form-control" id="urlbase" name="urlbase" required value="<?= h($admin_form['urlbase']) ?>">
              <div class="form-text">Détectée d'après l'adresse de cette page (sans protocole) : à corriger si l'instance est
                publiée à une autre adresse. Modifiable ensuite dans Configuration.</div>
            </div>
            <div class="col-12">
              <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Créer le compte et terminer</button>
            </div>
          </form>
        </div>
      </div>
<?php } ?>

      <div class="card shadow-sm">
        <div class="card-header"><i class="bi bi-list-check"></i> Vérifications</div>
        <ul class="list-group list-group-flush small">
<?php
$db_line = $state['db'] instanceof mysqli ? array(true, 'connectée' . ($state['db_version'] !== null ? ', version ' . $state['db_version'] : ''))
         : array(false, $state['config'] ? $state['db_error'] : 'non configurée');
$checks = $requirements + array(
    'Base de données' => $db_line,
    'Version de la base / du code' => array($state['db_version'] === null ? null : version_compare($state['db_version'], BACKEND_VERSION, '=='),
                                            ($state['db_version'] === null ? '?' : $state['db_version']) . ' / ' . BACKEND_VERSION),
);
foreach ($checks as $label => $check) {
    $icon = $check[0] === true ? 'bi-check-circle-fill text-success' : ($check[0] === false ? 'bi-x-circle-fill text-danger' : 'bi-dash-circle text-body-secondary');
?>
          <li class="list-group-item d-flex justify-content-between gap-2">
            <span><i class="bi <?= $icon ?>"></i> <?= h($label) ?></span><span class="text-body-secondary text-end"><?= h($check[1]) ?></span>
          </li>
<?php } ?>
        </ul>
      </div>
      <p class="text-body-secondary small mt-3">Documentation : <a href="https://vigilo.city/fr/documentation/installation/" target="_blank" rel="noopener noreferrer">installation de Vigilo</a>.</p>
    </main>
  </body>
</html>
