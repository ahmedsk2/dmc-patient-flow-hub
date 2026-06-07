<?php 
require_once __DIR__ . '/guard.php';
require_role([0]);   // Admin only (centralized auth gate)
csrf_verify();       // was a CSRF-able GET link; now a POST form carrying a token

if (empty($_POST['email'])) {
    header("Location: control.php");
    exit();
}

require_once __DIR__ . '/dbconnect.php';
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
            $mail->FromName = SMTP_FROM_NAME; // "DMC Help Desk" (was a hardcoded "Help Disk" typo)
            $mail->AddAddress($email, '');
            $mail->Subject  =  'DMC System: Reset Password';
            $mail->IsHTML(true);
        $mail->Body = 'Click on this link to reset your password: '.$link.'<br>Or copy the following link to your browser:<br>'.$reset_url;
            
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
                    error_log(__FILE__ . ": Error executing query: " . $stmt->error); die("A database error occurred. Please try again later.");
                }

                // Close the statement
                $stmt->close();
            

            header("Location: control.php");
            // echo "error";
            exit();
?>