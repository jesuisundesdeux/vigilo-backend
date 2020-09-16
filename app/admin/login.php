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

session_start();

require_once('../includes/common.php');
$badlogin = False;
if (isset($_POST['login'])) {
  $login = mysqli_real_escape_string($db,$_POST['login']);
  $login_query = mysqli_query($db,"SELECT * FROM obs_roles WHERE role_login = '".$login."' AND role_name='admin' OR role_name='citystaff' LIMIT 1");
  $login_result = mysqli_fetch_array($login_query);
  if (hash('sha256',$_POST['password']) == $login_result['role_password']) {
    $_SESSION['login'] = $login;
    $_SESSION['role'] = $login_result['role_name'];
    if (isset($_POST['rememberme'])) {
      setcookie("admin-key",$login_result['role_key'],time()+2678400);
    }
    header('Location: index.php');
  }
  elseif (isset($_COOKIE['admin-key']) && $_COOKIE['admin-key'] == $login_result['role_key']) {
    $_SESSION['login'] = $login;
  }
  else {
    $badlogin = True;
  }
}
else {
  session_destroy();
}
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Vigilo Login</title>

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
    <form class="form-signin" method="POST">
      <img class="mb-4" src="vigilo.png" alt="" width="72" height="72">
      <h1 class="h3 mb-3 font-weight-normal">Please sign in</h1>
<?php
      if($badlogin) {
        echo '<div class="alert alert-danger" role="alert">
		  <strong>Oh zut!</strong> Login / mot de passe incorrect.
		  </div>';
      }

?>
      <label for="inputEmail" class="sr-only">Login</label>
      <input type="text" name='login' class="form-control" placeholder="Login" required autofocus>
      <label for="inputPassword" class="sr-only">Password</label>
      <input type="password" name='password' class="form-control" placeholder="Password" required>
      <div class="checkbox mb-3">
        <label>
          <input type="checkbox" name="rememberme" value="remember-me"> Remember me
        </label>
      </div>
      <button class="btn btn-lg btn-primary btn-block" type="submit">Sign in</button>
      <p class="mt-5 mb-3 text-muted">&copy; 2017-2018</p>
    </form>
  </body>
</html>
