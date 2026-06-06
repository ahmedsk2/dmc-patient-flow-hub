<?php
require "dbconnect.php";

session_start();

$username = "";
$email = "";

// $_POST['position']="";
$errors = array();

if (isset($_POST['reg_user'])) {
    // receive all input values from the form
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_1 = $_POST['password_1'] ?? '';
    $password_2 = $_POST['password_2'] ?? '';
    $position = (int) ($_POST['position'] ?? 0);

    // form validation: ensure that the form is correctly filled
    if (empty($username)) {
        array_push($errors, "Username is required");
    }
    if (empty($full_name)) {
        array_push($errors, "Your full name is required");
    }
    if (empty($email)) {
        array_push($errors, "Email is required");
    }
    if (empty($password_1)) {
        array_push($errors, "Password is required");
    }
    // Never allow self-registration as Admin (0); only known non-admin roles.
    if (!in_array($position, [2, 3, 4, 5], true)) {
        array_push($errors, "A valid position is required");
    }
    if ($password_1 != $password_2) {
        array_push($errors, "The two passwords do not match");
    }

    // check the database to make sure a user does not already exist with the same username and/or email
    $chk = $mysqli->prepare("SELECT member_name, member_email FROM members WHERE member_name = ? OR member_email = ? LIMIT 1");
    $chk->bind_param("ss", $username, $email);
    $chk->execute();
    $user = $chk->get_result()->fetch_assoc();

    if ($user) { // if user exists
        if ($user['member_name'] === $username) {
            array_push($errors, "Username already exists");
        }

        if ($user['member_email'] === $email) {
            array_push($errors, "Email already exists");
        }
    }

    // register user if there are no errors in the form
    if (count($errors) == 0) {
        $today = date("Y-m-d");
        $password = password_hash($password_1, PASSWORD_DEFAULT); // encrypt the password before saving in the database

        $ins = $mysqli->prepare("INSERT INTO members (member_name, full_name, member_email, member_password, position, active, pass_exp_date) VALUES (?, ?, ?, ?, ?, 0, ?)");
        $ins->bind_param("ssssis", $username, $full_name, $email, $password, $position, $today);
        $ins->execute();
        $_SESSION['username'] = $username;
        $_SESSION['success'] = "You are now logged in";
        header('location: index.php?s=success');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Registration New Account</title>
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
    <a href="https://www.innovia.ai/"><img src="dist/img/logo-wt.png" width="100%"></a>
  </div>

  <div class="card">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Registration</p>

      <form method="post" autocomplete="off" action="register.php">
        <div class="form-group">
          <label>Username</label>
          <input type="text" name="username" class="form-control"  placeholder="Login Name" required>
        </div>
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="full_name" class="form-control"  placeholder="Display Name" required>
        </div>
        <div class="form-group">
          <label>Position</label>
          <select name="position" class="form-control" required>
            <option selected disabled value="">Select</option>
            <?php
            $formationSQL = "SELECT * FROM position";
            $result1 = $mysqli->query($formationSQL);
            $positions = $result1->fetch_all(MYSQLI_ASSOC);
            foreach ($positions as $p) {
                echo '<option value="' . htmlspecialchars($p['id']) . '">' . htmlspecialchars($p['position']) . '</option>';
            }
            ?>
          </select>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" class="form-control"  placeholder="E-Mail" required>
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" autocomplete="new-password" id="password" name="password_1" class="form-control" placeholder="Password" required>
          <div class="progress mt-2">
            <div class="progress-bar"></div>
          </div>
        </div>
        <div class="form-group">
          <label>Confirm Password</label>
          <input type="password" autocomplete="new-password" name="password_2" class="form-control" placeholder="Confirm Password" required>
        </div>
        <div class="form-group" style="color: red;">
          <?php include('errors.php'); ?>
        </div>
        <div class="form-group">
          <button type="submit" id="registerbtn" class="btn btn-primary btn-block" name="reg_user" disabled>Register</button>
        </div>
        <p class="mt-3">
          Already a member? <a href="./">Sign in</a>
        </p>
      </form>
    </div>
  </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/zxcvbn/4.2.0/zxcvbn.js" type="text/javascript"></script>
<script type="text/javascript">
  $(document).ready(function () {
    $.fn.bootstrapPasswordMeter = function (options) {
      var settings = $.extend({
        minPasswordLength: 3,
        level0ClassName: 'progress-bar-danger',
        level0Description: 'Weak',
        level1ClassName: 'progress-bar-danger',
        level1Description: 'Not great',
        level2ClassName: 'progress-bar-warning',
        level2Description: 'Better',
        level3ClassName: 'progress-bar-success',
        level3Description: 'Strong',
        level4ClassName: 'progress-bar-success',
        level4Description: 'Very strong',
        parentContainerClass: '.form-group'
      }, options || {});

      $(this).on("keyup", function () {
        var progressBar = $(this).closest(settings.parentContainerClass).find('.progress-bar');
        var progressBarWidth = 0;
        var progressBarDescription = '';
        if ($(this).val().length >= settings.minPasswordLength) {
          var zxcvbnObj = zxcvbn($(this).val());
          progressBar.removeClass(settings.level0ClassName)
            .removeClass(settings.level1ClassName)
            .removeClass(settings.level2ClassName)
            .removeClass(settings.level3ClassName)
            .removeClass(settings.level4ClassName);
          switch (zxcvbnObj.score) {
            case 0:
              progressBarWidth = 25;
              progressBar.addClass(settings.level0ClassName);
              progressBarDescription = settings.level0Description;
              $('#registerbtn').attr('disabled', true).text('You need a stronger password');
              break;
            case 1:
              progressBarWidth = 25;
              progressBar.addClass(settings.level1ClassName);
              progressBarDescription = settings.level1Description;
              $('#registerbtn').attr('disabled', true).text('You need a stronger password');
              break;
            case 2:
              progressBarWidth = 50;
              progressBar.addClass(settings.level2ClassName);
              progressBarDescription = settings.level2Description;
              $('#registerbtn').attr('disabled', true).text('You need a stronger password');
              break;
            case 3:
              progressBarWidth = 75;
              progressBar.addClass(settings.level3ClassName);
              progressBarDescription = settings.level3Description;
              $('#registerbtn').attr('disabled', false).text('Register');
              break;
            case 4:
              progressBarWidth = 100;
              progressBar.addClass(settings.level4ClassName);
              progressBarDescription = settings.level4Description;
              $('#registerbtn').attr('disabled', false).text('Register');
              break;
          }
        } else {
          progressBarWidth = 0;
          progressBarDescription = '';
          $('#registerbtn').attr('disabled', true).text('You need a stronger password');
        }
        progressBar.css('width', progressBarWidth + '%');
        progressBar.text(progressBarDescription);
      });
    };
    $('#password').bootstrapPasswordMeter({ minPasswordLength: 3 });
  });
</script>

</body>
</html>
