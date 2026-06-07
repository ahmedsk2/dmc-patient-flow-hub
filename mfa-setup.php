<?php
/**
 * mfa-setup.php — self-service TOTP enrollment / disable for the logged-in user.
 *
 * Enroll: generate a secret (held in the session until confirmed) -> user scans/keys it into an
 * authenticator app -> confirms with a live code -> we encrypt + persist it and show one-time
 * recovery codes. Disable: requires a current code (so a hijacked session without the device
 * cannot turn MFA off). No external dependency; QR is provided as an otpauth:// URI + manual key.
 */
session_start();
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mfa.php';
require __DIR__ . '/dbconnect.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: index.php');
    exit;
}
$member_id = (int) $_SESSION['member_id'];

$ps = $mysqli->prepare('SELECT member_name, member_email, mfa_secret FROM members WHERE member_id = ?');
$ps->bind_param('i', $member_id);
$ps->execute();
$user = $ps->get_result()->fetch_assoc();
$enrolled = !empty($user['mfa_secret']);
$account  = $user['member_email'] ?: $user['member_name'];

$errors = [];
$newRecoveryCodes = null; // shown once, right after enrollment
$flash = '';

if (!mfa_key_available()) {
    $errors[] = 'MFA is not configured on this server (missing MFA_KEY). Contact an administrator.';
}

/* ---- Enroll: confirm the pending secret with a live code ---- */
if (!$errors && !$enrolled && !empty($_POST['confirm_enroll'])) {
    csrf_verify();
    $pendingSecret = $_SESSION['mfa_setup_secret'] ?? '';
    $code = trim($_POST['code'] ?? '');
    if ($pendingSecret === '') {
        $errors[] = 'Your setup session expired. Please restart enrollment.';
    } elseif (mfa_totp_verify($pendingSecret, $code) === false) {
        $errors[] = 'That code did not match. Make sure your device clock is correct and try again.';
    } else {
        $plainCodes = mfa_generate_recovery_codes(10);
        $enc = mfa_encrypt($pendingSecret);
        $rcJson = mfa_hash_recovery_codes($plainCodes);
        $now = date('Y-m-d H:i:s');
        $u = $mysqli->prepare('UPDATE members SET mfa_secret = ?, mfa_recovery_codes = ?, mfa_enrolled_at = ? WHERE member_id = ?');
        $u->bind_param('sssi', $enc, $rcJson, $now, $member_id);
        $u->execute();
        unset($_SESSION['mfa_setup_secret']);
        $enrolled = true;
        $newRecoveryCodes = $plainCodes;
        $flash = 'Two-factor authentication is now enabled on your account.';
    }
}

/* ---- Disable: require a current code (TOTP or recovery) ---- */
if (!$errors && $enrolled && !$newRecoveryCodes && !empty($_POST['disable_mfa'])) {
    csrf_verify();
    $code = trim($_POST['code'] ?? '');
    $row = $mysqli->query('SELECT mfa_secret, mfa_recovery_codes FROM members WHERE member_id = ' . $member_id)->fetch_assoc();
    $secret = mfa_decrypt($row['mfa_secret'] ?? null);
    $rc = $row['mfa_recovery_codes'] ?? null;
    if (($secret && mfa_totp_verify($secret, $code) !== false) || mfa_consume_recovery_code($code, $rc)) {
        $u = $mysqli->prepare('UPDATE members SET mfa_secret = NULL, mfa_recovery_codes = NULL, mfa_enrolled_at = NULL WHERE member_id = ?');
        $u->bind_param('i', $member_id);
        $u->execute();
        $enrolled = false;
        $flash = 'Two-factor authentication has been disabled.';
    } else {
        $errors[] = 'That code did not match — MFA was not disabled.';
    }
}

/* ---- Prepare a pending secret for the enrollment view (stable across reloads) ---- */
$setupSecret = '';
$otpauth = '';
if (!$enrolled && !$errors) {
    if (empty($_SESSION['mfa_setup_secret'])) {
        $_SESSION['mfa_setup_secret'] = mfa_generate_secret();
    }
    $setupSecret = $_SESSION['mfa_setup_secret'];
    $otpauth = mfa_otpauth_uri($setupSecret, $account);
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>DMC Registry | Two-factor authentication</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="vendor/bootstrap-5.3.3-dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link href="css/main.css" rel="stylesheet">
</head>
<body class="hold-transition login-page">
<div class="login-box" style="width:30rem;max-width:95%;">
    <div class="login-logo"><img src="dist/img/logo.png" width="100%" alt="DMC"></div>
    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">Two-factor authentication</p>

            <?php if ($flash !== '') { ?>
                <div class="alert alert-success py-2"><?php echo $h($flash); ?></div>
            <?php } ?>
            <?php foreach ($errors as $e) { ?>
                <div class="alert alert-danger py-2"><?php echo $h($e); ?></div>
            <?php } ?>

            <?php if ($newRecoveryCodes) { ?>
                <div class="alert alert-warning">
                    <strong>Save your recovery codes now.</strong> Each can be used once if you lose your
                    device. They will not be shown again.
                </div>
                <pre style="font-size:1rem;background:#f4f4f4;padding:.75rem;border-radius:4px;">
<?php foreach ($newRecoveryCodes as $c) {
                    echo $h($c) . "\n";
                } ?></pre>
                <a href="dashboard.php" class="btn btn-primary btn-block">I've saved them — continue</a>

            <?php } elseif ($enrolled) { ?>
                <div class="alert alert-success py-2"><span class="fas fa-shield-alt"></span>
                    MFA is <strong>enabled</strong> on your account.</div>
                <form action="mfa-setup.php" method="post" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <p class="text-muted" style="font-size:.9rem;">To turn MFA off, confirm with a current code:</p>
                    <div class="input-group mb-3">
                        <input class="form-control" name="code" type="text" autocomplete="one-time-code"
                               placeholder="6-digit or recovery code" required>
                    </div>
                    <button type="submit" name="disable_mfa" value="1" class="btn btn-outline-danger btn-block"
                            onclick="return confirm('Disable two-factor authentication?');">Disable MFA</button>
                </form>
                <div class="mt-3 text-center"><a href="dashboard.php">Back to dashboard</a></div>

            <?php } elseif (!$errors) { ?>
                <ol style="font-size:.92rem;padding-left:1.1rem;">
                    <li>Install an authenticator app (Google Authenticator, Authy, Microsoft Authenticator…).</li>
                    <li>Add an account using this key (or the link below):</li>
                </ol>
                <div class="text-center mb-2">
                    <div class="text-muted" style="font-size:.8rem;">Manual key</div>
                    <code style="font-size:1.1rem;letter-spacing:1px;word-break:break-all;"><?php echo $h($setupSecret); ?></code>
                </div>
                <div class="text-center mb-3">
                    <a href="<?php echo $h($otpauth); ?>" style="font-size:.85rem;">Open in authenticator app</a>
                </div>
                <form action="mfa-setup.php" method="post" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <p class="text-muted" style="font-size:.9rem;">Then enter the 6-digit code it shows to confirm:</p>
                    <div class="input-group mb-3">
                        <input class="form-control" name="code" type="text" inputmode="numeric"
                               autocomplete="one-time-code" placeholder="123456" required autofocus>
                    </div>
                    <button type="submit" name="confirm_enroll" value="1" class="btn btn-primary btn-block">Enable MFA</button>
                </form>
                <div class="mt-3 text-center"><a href="dashboard.php">Cancel</a></div>
            <?php } else { ?>
                <div class="text-center"><a href="dashboard.php">Back to dashboard</a></div>
            <?php } ?>
        </div>
    </div>
</div>
</body>
</html>
