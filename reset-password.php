<?php
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/reset_tokens.php';
  require ('dbconnect.php');
$errors = array(); 
if (isset($_POST['reset_pass'])) {
  csrf_verify();
  
  // receive all input values from the form
  $password_11 = $_POST['password_11'] ?? '';
  $password_22 = $_POST['password_22'] ?? '';

  // form validation: ensure that the form is correctly filled ...
  // by adding (array_push()) corresponding error unto $errors array
  if (empty($password_11)) { array_push($errors, "Password is required"); }
    if ($password_11 != $password_22) {
      array_push($errors, "The two passwords do not match");
    }

    if (count($errors) == 0) {
      $password =  password_hash($password_22, PASSWORD_DEFAULT);//encrypt the password before saving in the database
      $reset_row = password_reset_lookup($_POST['token'] ?? '');
       $today=date("Y-m-d");
      // $query = "UPDATE members set  member_password='".$password."', pass_exp_date='".$today."' where member_email='".$member_mail."'";
      
      // mysqli_query($mysqli, $query) or die(mysqli_error($mysqli));
     if (!$reset_row) {
       header('location: index.php'); exit;
     }
     $member_id_reset = (int) $reset_row['member_id'];
     $upd = $mysqli->prepare("UPDATE members SET member_password = ?, pass_exp_date = ? WHERE member_id = ?");
     $upd->bind_param("ssi", $password, $today, $member_id_reset);
     if (!$upd->execute()) {
    error_log(__FILE__ . ": password reset update " . $mysqli->error); $error = "A database error occurred.";
} else {$error= "sucess"; password_reset_consume($reset_row['id']);}
      $_SESSION['success'] = "Password changed";
      //  echo "ahmed";
      header('location: index.php?s=changed');
  }

  }

  // Finally, register user if there are no errors in the form
  

if (!empty($_GET['token']))
{
  $token = $_GET['token'];
  $reset_row = password_reset_lookup($token);


// token already validated above into $reset_row



// var_dump($patintsendorcement);
} else {
    header("Location: .");
}

?>
 <head>
 
  <title> Reset Password</title>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">


  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">

  <!-- Password strength meter -->
 

  <!--<script language="javascript" src="vendor\pwdMeter-master\jquery.pwdMeter.js"></script> -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" integrity="sha384-1q8mTJOASx8j1Au+a5WDVnPi2lkFfwwEAa8hDDdjZlpLegxhjVME1fgjWPGmkzs7" crossorigin="anonymous">
 

  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">

</head>
<?php //echo $member_id;?>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
	
    <a href="https://www.healthpro.ai/main/">  <img src="dist/img/logo.png" width="100%"></a>
  </div>

 
  <div class="card">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Reset Password</p>
      
      <?php
      if(!empty($reset_row))
  {
    $link = "reset-password.php?token=".$token;
    ?>
<form method="post" autocomplete="off" action="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8');?>">
<?php echo csrf_field(); ?>

<input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8');?>" >

<div class="input-group mb-3">

    <div class="form-group" style=" display: table; margin-bottom: 0px;width: 100%; ">
    <div class="col-5"  style=" display: table-cell;">
      <label for="password">Password</label>
      </div>
      <div class="col-7">
      <input type="password" autocomplete="false" id="password" name="password_11" placeholder="Password">
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
        <input autocomplete="false" type="password" name="password_22" placeholder="Confirm password">
  	</div>
</div>
<div class="input-group mb-3" style='color: red;'>
<?php include('errors.php'); ?>
  	</div>

      <div class="input-group mb-3">
  	  <button type="submit" id='registerbtn' class="btn btn-primary btn-block" name="reset_pass" disabled>Submit Password</button>
  	</div>
  </form>
  <?php
   
  } else{

    echo "There is an error, request for lost password again.";
  } ?>
  </div>
    <!-- /.login-card-body -->
  </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/zxcvbn/4.2.0/zxcvbn.js" type="text/javascript"></script>
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
