<?php
session_start();
require_once __DIR__ . '/csrf.php';

require ('dbconnect.php');
if (!isset($_SESSION["member_id"])){
  header('location: index.php');
}
$member_id = (int) $_SESSION["member_id"];
$errors = array();


$ps = $mysqli->prepare("SELECT * FROM members WHERE member_id = ?");
$ps->bind_param("i", $member_id);
$ps->execute();
$user = $ps->get_result()->fetch_array(MYSQLI_ASSOC);

      $today=date("Y-m-d");
      $pass_date = $user["pass_exp_date"];
      $expirydate = date('Y-m-d', strtotime("+3 months", strtotime($pass_date)));

      // echo date('Y-m-d');
      // echo $expirydate;
if (date('Y-m-d') > $expirydate || ($_GET['pass'] ?? '') == "true") {


  
if (isset($_POST['change_pass'])) {
  csrf_verify();
  // receive all input values from the form
  $oldpassword = $_POST['oldpassword'] ?? '';
  $password_1 = $_POST['password_1'] ?? '';
  $password_2 = $_POST['password_2'] ?? '';

  // form validation: ensure that the form is correctly filled ...
  // by adding (array_push()) corresponding error unto $errors array
  if (empty($oldpassword)) { array_push($errors, "oldpassword is required"); }
  if (empty($password_1)) { array_push($errors, "Password is required"); }
  if (!password_verify($oldpassword, $user["member_password"])) {
    array_push($errors, "Wrong old password");
  } else if (password_verify($password_1, $user["member_password"])){
    array_push($errors, "Choose password different from previous one");
  }
  else{
    if ($password_1 != $password_2) {
      array_push($errors, "The two passwords do not match");
    }
  }

  // Finally, register user if there are no errors in the form
  if (count($errors) == 0) {
      $password =  password_hash($password_1, PASSWORD_DEFAULT);//encrypt the password before saving in the database

      $upd = $mysqli->prepare("UPDATE members SET member_password = ?, pass_exp_date = ? WHERE member_id = ?");
      $upd->bind_param("ssi", $password, $today, $member_id);
      $upd->execute();
      $_SESSION['success'] = "Password changed";
      header('location: index.php?s=changed');
  }
}
?>

  <head>
  <title> Change Password</title>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">


  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">

  <!-- Password strength meter -->
 

  <!--<script language="javascript" src="vendor\pwdMeter-master\jquery.pwdMeter.js"></script> -->
  <!-- Bootstrap 3 CDN removed (S10): AdminLTE supplies the styling; the meter uses bg-* utilities. -->
 

  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">

</head>
<?php //echo $member_id;?>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
	
    <a href="https://www.dmc-im.com/">  <img src="dist/img/logo.png" width="100%"></a>
  </div>

 
  <div class="card">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Change Password</p>

<form method="post" autocomplete="off" action="change-password.php?pass=true">
<?php echo csrf_field(); ?>

<div class="input-group mb-3">
    <div class="col-5" style=" display: table-cell;">
  	    <label>Old Password</label>
    </div>
    <div class="col-7">
  	  <input type="password" name="oldpassword" value="" aria-label="Old password" placeholder="Old Password" >
    </div>
</div>

<div class="input-group mb-3">

    <div class="form-group" style=" display: table; margin-bottom: 0px;width: 100%; ">
    <div class="col-5"  style=" display: table-cell;">
      <label for="password">Password</label>
      </div>
      <div class="col-7">
      <input type="password" autocomplete="false" id="password" name="password_1" placeholder="Password">
      </div>


      <div class="progress" style="margin-bottom: 0px;  margin-top: 2%;">
        <div class="progress-bar"></div>
      </div>
    </div>
    
    
</div>
<div class="input-group mb-3">
    <div class="col-5"  style=" display: table-cell;">
        <label>Confirm password</label>
    </div>
    <div class="col-7">
        <input autocomplete="false" type="password" name="password_2" aria-label="Confirm new password" placeholder="Confirm password">
  	</div>
</div>
<div class="input-group mb-3" style='color: red;'>
<?php include('errors.php'); ?>
  	</div>

      <div class="input-group mb-3">
  	  <button type="submit" id='registerbtn' class="btn btn-primary btn-block" name="change_pass" disabled>Change Password</button>
  	</div>
  </form>
  </div>
    <!-- /.login-card-body -->
  </div>
</div>

<script src="vendor/jquery-3.7.1.min.js"></script>
<script src="vendor/zxcvbn/4.2.0/zxcvbn.js" type="text/javascript"></script>
  <script type="text/javascript">
    $(document).ready(function () { 
      });
  </script>
<script>
  const button = document.getElementById('registerbtn'); 
    $(function() {
  $.fn.bootstrapPasswordMeter = function(options) {
    var settings = $.extend({
      minPasswordLength: 6,
      level0ClassName: 'bg-danger',
      level0Description: 'Weak',
      level1ClassName: 'bg-danger',
      level1Description: 'Not great',
      level2ClassName: 'bg-warning',
      level2Description: 'Better',
      level3ClassName: 'bg-success',
      level3Description: 'Strong',
      level4ClassName: 'bg-success',
      level4Description: 'Very strong',
      parentContainerClass: '.form-group'
    }, options || {});

    $(this).on("keyup", function() {
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
            button.disabled = true;
            break;
          case 1:
            progressBarWidth = 25;
            progressBar.addClass(settings.level1ClassName);
            progressBarDescription = settings.level1Description;
            button.disabled = true;
            break;
          case 2:
            progressBarWidth = 50;
            progressBar.addClass(settings.level2ClassName);
            progressBarDescription = settings.level2Description;
            button.disabled = true;
            break;
          case 3:
            progressBarWidth = 75;
            progressBar.addClass(settings.level3ClassName);
            progressBarDescription = settings.level3Description;
            button.disabled = false;
            break;
          case 4:
            progressBarWidth = 100;
            progressBar.addClass(settings.level4ClassName);
            progressBarDescription = settings.level4Description;
            button.disabled = false;
            break;
        }
      } else {
        progressBarWidth = 0;
        progressBarDescription = '';
        button.disabled = true;
      }
      progressBar.css('width', progressBarWidth + '%');
      progressBar.text(progressBarDescription);
    });
  };
  $('#password').bootstrapPasswordMeter({minPasswordLength:6});
});
  </script>
  
</body>

                   
 <?php
}else {
  header("Location: .");
                    }
                
                 
?>