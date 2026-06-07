<?php
/**
 * mfa-verify.php — second factor step. Reached only when index.php has verified the password
 * for an MFA-enrolled user and stashed $_SESSION['mfa_pending']. The full authenticated session
 * (member_id/position/name) is established HERE, only after a valid TOTP or recovery code — so a
 * correct password alone never logs an enrolled user in.
 */
session_start();
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mfa.php';
require __DIR__ . '/dbconnect.php';

$pending = $_SESSION['mfa_pending'] ?? null;
// No pending hand-off (or it expired after 5 min) -> back to login.
if (empty($pending['member_id']) || (time() - (int) ($pending['ts'] ?? 0) > 300)) {
    unset($_SESSION['mfa_pending']);
    header('Location: index.php');
    exit;
}
$member_id = (int) $pending['member_id'];
$message = '';

if (!empty($_POST['verify'])) {
    csrf_verify();
    // Throttle: a handful of tries, then bounce back to the password step.
    $_SESSION['mfa_attempts'] = (int) ($_SESSION['mfa_attempts'] ?? 0) + 1;
    if ($_SESSION['mfa_attempts'] > 8) {
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_attempts']);
        header('Location: index.php');
        exit;
    }

    $code = trim($_POST['code'] ?? '');
    $ps = $mysqli->prepare('SELECT mfa_secret, mfa_recovery_codes FROM members WHERE member_id = ?');
    $ps->bind_param('i', $member_id);
    $ps->execute();
    $row = $ps->get_result()->fetch_assoc();

    $secret = mfa_decrypt($row['mfa_secret'] ?? null);
    $ok = false;
    $usedRecovery = false;

    if ($secret && mfa_totp_verify($secret, $code) !== false) {
        $ok = true;
    } else {
        // Fall back to a single-use recovery code.
        $rc = $row['mfa_recovery_codes'] ?? null;
        if (mfa_consume_recovery_code($code, $rc)) {
            $ok = true;
            $usedRecovery = true;
            $u = $mysqli->prepare('UPDATE members SET mfa_recovery_codes = ? WHERE member_id = ?');
            $u->bind_param('si', $rc, $member_id);
            $u->execute();
        }
    }

    if ($ok) {
        // Establish the real session now (mirrors index.php's success block). MFA logins are
        // intentionally not "remembered" — an enrolled user re-authenticates each session.
        session_regenerate_id(true);
        $_SESSION['member_id'] = $pending['member_id'];
        $_SESSION['position']  = $pending['position'];
        $_SESSION['name']      = $pending['member_name'];
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_attempts']);
        if ($usedRecovery) {
            $_SESSION['mfa_notice'] = 'You signed in with a recovery code. Regenerate your recovery codes in MFA settings.';
        }
        header('Location: dashboard.php');
        exit;
    }
    $message = 'Invalid code. Please try again.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>DMC Registry | Two-factor verification</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="vendor/bootstrap-5.3.3-dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link href="css/main.css" rel="stylesheet">
</head>
<body class="hold-transition login-page">
<div class="login-box">
    <div class="login-logo"><img src="dist/img/logo.png" width="100%" alt="DMC"></div>
    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">Two-factor verification</p>
            <p class="text-muted text-center" style="font-size:.9rem;">
                Enter the 6-digit code from your authenticator app, or a recovery code.
            </p>
            <?php if ($message !== '') { ?>
                <div class="error-message" style="color:red;text-align:center;font-weight:600;">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php } ?>
            <form action="mfa-verify.php" method="post" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="input-group mb-3">
                    <input class="form-control" name="code" type="text" inputmode="text"
                           autocomplete="one-time-code" placeholder="123456 or recovery code"
                           required autofocus>
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-shield-alt"></span></div>
                    </div>
                </div>
                <button type="submit" name="verify" value="1" class="btn btn-primary btn-block">Verify</button>
            </form>
            <div class="mt-3 text-center">
                <a href="index.php">Cancel and sign in again</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
