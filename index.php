<?php
session_start();
require_once __DIR__ . '/csrf.php';

require_once "Auth.php";
require_once "Util.php";

$auth = new Auth();
$db_handle = new DBController();
$util = new Util();

require_once "authCookieSessionValidate.php";
$active = "";
if ($isLoggedIn) {
    $util->redirect("dashboard.php");
}

if (!empty($_POST["login"])) {
    csrf_verify();
    $isAuthenticated = false;

    $username = $_POST["member_name"];
    $password = $_POST["member_password"];

    $user = $auth->getMemberByUsername($username);
    if (password_verify($password, $user[0]["member_password"])) {
        $active = $user[0]["active"];
        $pass_date = $user[0]["pass_exp_date"];
        $expirydate = date('Y-m-d', strtotime("+3 months", strtotime($pass_date)));

        if (date('Y-m-d') > $expirydate) {
            $_SESSION["member_id"] = $user[0]["member_id"];
            $util->redirect("change-password.php");
        } else {
            $isAuthenticated = true;
        }
    }

    if ($isAuthenticated) {
        if ($active == 1) {
            session_regenerate_id(true); // prevent session fixation: new id on login
            $_SESSION["member_id"] = $user[0]["member_id"];
            $_SESSION["position"] = $user[0]["position"];
            $_SESSION["name"] = $user[0]["member_name"];
            if (!empty($_POST["remember"])) {
                setcookie("member_login", $username, $cookie_expiration_time);

                $random_password = $util->getToken(16);
                setcookie("random_password", $random_password, $cookie_expiration_time);

                $random_selector = $util->getToken(32);
                setcookie("random_selector", $random_selector, $cookie_expiration_time);

                $random_password_hash = password_hash($random_password, PASSWORD_DEFAULT);
                $random_selector_hash = password_hash($random_selector, PASSWORD_DEFAULT);

                $expiry_date = date("Y-m-d H:i:s", $cookie_expiration_time);

                $userToken = $auth->getTokenByUsername($username, 0);
                if (!empty($userToken[0]["id"])) {
                    $auth->markAsExpired($userToken[0]["id"]);
                }
                $auth->insertToken($username, $random_password_hash, $random_selector_hash, $expiry_date);
            } else {
                $util->clearAuthCookie();
            }

            $util->redirect("dashboard.php");
        } else {
            $message = "Need for activation, contact your manager";
        }
    } else {
        $message = "Invalid Login";
    }
}
?>
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>DMC Registry | Log in</title>

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
            <a href="https://www.dmc-im.com/"><img src="dist/img/logo-wt.png" width="100%"></a>
        </div>

        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Log in</p>
                <div class="error-message" style="color:green;font-weight: 700;">
                    <?php
                    if (isset($_GET['s']) && strcmp($_GET['s'], 'success') == 0) {
                        echo "You registered successfully, Wait for your account activation";
                    } else if (isset($_GET['s']) && $_GET['s'] == 'changed') {
                        echo "Your password changed successfully, Please login";
                    }
                    ?>
                </div>

                <form action="" method="post" id="frmLogin">
                    <?php echo csrf_field(); ?>
                    <div class="input-group mb-3">
                        <input class="form-control" name="member_name" type="text" aria-label="Username" placeholder="Username" value="<?php if (isset($_COOKIE["member_login"])) {
                                                                                                                        echo htmlspecialchars($_COOKIE["member_login"], ENT_QUOTES, 'UTF-8');
                                                                                                                    } ?>" required autofocus>
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-user"></span>
                            </div>
                        </div>
                    </div>
                    <div class="input-group mb-3">
                        <input type="password" class="form-control" name="member_password" aria-label="Password" placeholder="Password" value="<?php if (isset($_COOKIE["member_password"])) {
                                                                                                                                echo htmlspecialchars($_COOKIE["member_password"], ENT_QUOTES, 'UTF-8');
                                                                                                                            } ?>">
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-lock"></span>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-8">
                            <div class="error-message" style="color:red;"><?php if (isset($message)) {
                                                                                echo $message;
                                                                            } ?></div>

                            <div class="icheck-primary">
                                <input type="checkbox" name="remember" id="remember" <?php if (isset($_COOKIE["member_login"])) { ?> checked <?php } ?> />
                                <label for="remember">
                                    Remember Me
                                </label>
                            </div>
                            <div class="mt-2">
                                <a href="register.php">Don't have an account?</a><br>
                                <a href="forget-password.php">Reset Lost password?</a>
                            </div>
                        </div>
                        <div class="col-4">
                            <button type="submit" name="login" value="Login" class="btn btn-primary btn-block" style="font-size: 1.2rem; padding: 10px;">Sign In</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>
