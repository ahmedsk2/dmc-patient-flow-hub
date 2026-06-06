<?php 
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
// var_dump($_SESSION);
require_once "authCookieSessionValidate.php";

if(!$isLoggedIn) {
    header("Location: ./");
}

$access_PICU_control=[0];

        if (!in_array($_SESSION['position'],$access_PICU_control)){
            header("Location: control.php");
            // echo "error";
            exit();
                  }

        if (!isset($_GET['email']) || empty($_GET['email'])){
            header("Location: control.php");
            // echo "error";
            exit();
        }

            require('dbconnect.php');

           $email = $_GET['email'];


                // Prepare the SQL statement with placeholders
        $formationSQL = "SELECT member_email, member_password FROM members WHERE member_email = ? OR member_name = ?";

                $stmt = $mysqli->prepare($formationSQL);

                if ($stmt === false) {
                    // Handle errors with preparing the statement
                    die("Error preparing statement: " . $mysqli->error);
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
        $email=$row['member_email'];
          $email1=md5($row['member_email']);
          $pass=md5($row['member_password']);
            $link="<a href='www.dmc-im.com/reset-password.php?key=".$email1."&reset=".$pass."'>Click To Reset password</a>";


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
        $mail->Body = 'Click on this link to reset your password: <a href="'.$link.'">'.$link.'</a><br>Or copy the following link to your browser:<br>www.dmc-im.com/reset-password.php?key='.$email1.'&reset='.$pass;
            
           if ($mail->Send()) {
                  $_SESSION['message'] = 'Reset message sent to "'.$email.'"';
              } else {
                  $_SESSION['message'] = "Mail Error - >" . $mail->ErrorInfo;
              }


                    } else {
                         $_SESSION['message'] =  "Account not found";
                    }
                } else {
                    // Handle execution error
                    die("Error executing query: " . $stmt->error);
                }

                // Close the statement
                $stmt->close();
            

            header("Location: control.php");
            // echo "error";
            exit();
?>