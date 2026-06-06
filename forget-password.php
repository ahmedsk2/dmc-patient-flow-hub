<?php
require('dbconnect.php');
?>

<!DOCTYPE html>
<html>
<head>
  <title>Forget Password</title>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="vendor/fontawesome-free-6.5.2-web/css/all.min.css">
  <!-- Material Design Iconic Font -->
  <link href="vendor/material-design-iconic-font/css/material-design-iconic-font.min.css" rel="stylesheet">
  <!-- Bootstrap CSS -->
  <link href="vendor/bootstrap-5.3.3-dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- AdminLTE CSS -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <!-- Custom CSS -->
  <link href="css/main.css" rel="stylesheet">

  <!-- jQuery -->
  <script src="vendor/jquery-3.7.1.min.js"></script>
  <!-- Bootstrap JS -->
  <script src="vendor/bootstrap-5.3.3-dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="hold-transition login-page">
  <div class="login-box">
    <div class="login-logo">
      <a href="https://www.innovia.ai/">
        <img src="dist/img/logo-wt.png" width="100%">
      </a>
    </div>

    <div class="card">
      <div class="card-body login-card-body">
        <p class="login-box-msg">Forget Password</p>
        <p>Enter <strong>Username</strong> or <strong>Email Address</strong> to send password reset link</p>

        <form method="post" action="forget-password-email.php">
          <div class="input-group mb-3">
            <input type="text" name="email" class="form-control" placeholder="Username or E-Mail" required autofocus>
            <div class="input-group-append">
              <div class="input-group-text">
                <span class="fas fa-envelope"></span>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-12">
              <button type="submit" name="submit_email" value="submit_email" class="btn btn-primary btn-block">Submit</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
