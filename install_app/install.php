<?php

$cwd = dirname(__FILE__);

require_once("${cwd}/includes/common.php");

function deleteInstallFile() {
  unlink('./install.php');
}

if(isset($_POST['password']) && !empty($_POST['password'])) {
 if($_POST['password'] != $_POST['password2']) {
   $diffpass = TRUE;
 }
 else {
   $password=hash('sha256',$_POST['password']);
   if(empty($_POST['key'])) {
     $key = str_replace('.', '', uniqid('', true));
   }
   mysqli_query($db,"INSERT INTO obs_roles (role_key,
                                            role_name,
                                            role_owner,
                                            role_login,
                                            role_password,
                                            role_city)
                             VALUES ('',
                                      'admin',
                                      '".$_POST['name']."',
                                      '".$_POST['login']."',
                                      '".$password."',
                                      '')");
   header('Location: admin/index.php');
 } 
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Vigilo - Creation compte admin</title>

    <link rel="canonical" href="https://getbootstrap.com/docs/4.0/examples/sign-in/">

    <!-- Bootstrap core CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet">

    <style>
      html,
      body {
        height: 100%;
      }
      
      body {
        display: -ms-flexbox;
        display: -webkit-box;
        display: flex;
        -ms-flex-align: center;
        -ms-flex-pack: center;
        -webkit-box-align: center;
        align-items: center;
        -webkit-box-pack: center;
        justify-content: center;
        padding-top: 40px;
        padding-bottom: 40px;
        background-color: #f5f5f5;
      }
      
      .form-signin {
        width: 100%;
        max-width: 330px;
        padding: 15px;
        margin: 0 auto;
      }
      .form-signin .checkbox {
        font-weight: 400;
      }
      .form-signin .form-control {
        position: relative;
        box-sizing: border-box;
        height: auto;
        padding: 10px;
        font-size: 16px;
      }
      .form-signin .form-control:focus {
        z-index: 2;
      }
      .form-signin input[type="email"] {
        margin-bottom: -1px;
        border-bottom-right-radius: 0;
        border-bottom-left-radius: 0;
      }
      .form-signin input[type="password"] {
        margin-bottom: 10px;
        border-top-left-radius: 0;
        border-top-right-radius: 0;
      }
    </style>

  </head>

  <body class="text-center">
<?php
if(!$db = mysqli_connect($config['MYSQL_HOST'],
                     $config['MYSQL_USER'],
                     $config['MYSQL_PASSWORD'],
                     $config['MYSQL_DATABASE'])) {

?>
  <div class="alert alert-danger" role="alert">Erreur de connexion à la base de données MySQL : <br />
  <ul>
    <li>Si vous êtes sur docker, veuillez compléter le fichier .env </li>
    <li>Si vous êtes en mode hebergé, veuillez compléter le fichier config/config.php</li>
    </ul>
  </div>";
<?php

}
else {
  $query_installed = mysqli_query($db,"SELECT * FROM obs_roles");
  if(mysqli_num_rows($query_installed) != 0) {
?>
<div class="alert alert-warning" role="alert">
  <strong>Alerte!</strong> Compte admin déjà existant. Veuillez supprimer install.php
</div>

<?php
  } 
  else {
?>

    <form class="form-signup" method="POST">
      <img class="mb-4" src="admin/vigilo.png" alt="" width="72" height="72">
      <h1 class="h3 mb-3 font-weight-normal">Merci de créer votre compte admin</h1>

<?php
  if(isset($diffpass)) {
?>
<div class="alert alert-warning" role="alert">
  <strong>Alerte!</strong> Mots de passe différents
</div>
<?php } ?>
      <label for="inputEmail" class="sr-only">Nom et Prénom</label>
      <input type="text" name='name' class="form-control" placeholder="Nom et Prénom" required>
      <label for="inputEmail" class="sr-only">Identifiant</label>
      <input type="text" name='login' class="form-control" placeholder="Identifiant" required autofocus>
      <label for="inputPassword" class="sr-only">Mot de passe</label>
      <input type="password" name='password' class="form-control" placeholder="Mot de passe" required>
      <label for="inputPassword" class="sr-only">Mot de passe (confirmation)</label>
      <input type="password" name='password2' class="form-control" placeholder="Confirmation mot de passe" required>

      <button class="btn btn-lg btn-primary btn-block" type="submit">Créer le compte</button>
      <p class="mt-5 mb-3 text-muted">&copy; 2018-2019</p>
    </form>
<?php 
  } 
}
?>
  </body>
</html>


