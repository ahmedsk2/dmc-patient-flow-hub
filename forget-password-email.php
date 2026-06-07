<?php require_once __DIR__ . '/csrf.php'; ?>
<head>
  <title> Forget Password</title>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">


  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">

  <!-- Password strength meter -->
 

  <!--<script language="javascript" src="vendor\pwdMeter-master\jquery.pwdMeter.js"></script> -->
  <!-- Bootstrap 3 CDN removed (S10): AdminLTE supplies the styling. -->
 

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
      <p class="login-box-msg">Forget Password</p>

      <?php
if (isset($_POST['submit_email']) && $_POST['email']) {
    csrf_verify();
    require('dbconnect.php');
    require_once __DIR__ . '/reset_tokens.php';

    $email = $_POST['email'];


        // Prepare the SQL statement with placeholders
$formationSQL = "SELECT member_id, member_email FROM members WHERE member_email = ? OR member_name = ?";

        $stmt = $mysqli->prepare($formationSQL);

        if ($stmt === false) {
            // Handle errors with preparing the statement
            error_log(__FILE__ . ": Error preparing statement: " . $mysqli->error); die("A database error occurred. Please try again later.");
        }

        // Bind the email parameter
$stmt->bind_param("ss", $email, $email); // 'ss' indicates that both parameters are strings

        // Execute the statement
        if ($stmt->execute()) {
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
  $row= $result->fetch_assoc();
// var_dump($row);
// echo $row['member_email']."</br>"."</br>";
// echo $row['member_password']."</br>"."</br>";
$email = $row['member_email'];
   $reset_token = password_reset_create($row['member_id']);
   $reset_url = "https://www.dmc-im.com/reset-password.php?token=" . $reset_token;
    $link = "<a href='".$reset_url."'>Click To Reset password</a>";


    require 'vendor/PHPMailer/src/Exception.php';
    require 'vendor/PHPMailer/src/PHPMailer.php';
    require 'vendor/PHPMailer/src/SMTP.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer();
    $mail->CharSet =  "utf-8";
    $mail->IsSMTP();
    // enable SMTP authentication
    $mail->SMTPAuth = true;                  
    // GMAIL username
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->From = SMTP_FROM;
    $mail->FromName='DMC Help Disk';
    $mail->AddAddress($email, '');
    $mail->Subject  =  'DMC System: Reset Password';
    $mail->IsHTML(true);
$mail->Body = 'Click on this link to reset your password: '.$link.'<br>Or copy the following link to your browser:<br>'.$reset_url;
    
    if($mail->Send())
    {
      echo "Check Your Email and Click on the link sent to your email";
    }
    else
    {
      echo "Mail Error - >".$mail->ErrorInfo;
    } 

            } else {
                echo "Account not found";
            }
        } else {
            // Handle execution error
            error_log(__FILE__ . ": Error executing query: " . $stmt->error); die("A database error occurred. Please try again later.");
        }

        // Close the statement
        $stmt->close();
    
}
?>

  </div>
    <!-- /.login-card-body -->
  </div>
</div>

<script src="vendor/jquery-3.7.1.min.js"></script>

  
</body>

                   
 <?php
     
                 
?>