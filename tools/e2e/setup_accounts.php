<?php
/**
 * tools/e2e/setup_accounts.php — create/refresh the throwaway e2e_* role accounts in dmc_prod,
 * then smoke-test that each can log in over real HTTP. Idempotent: safe to re-run.
 */
require __DIR__ . '/lib.php';

$db = e2e_db();
$hash = password_hash(E2E_PASS, PASSWORD_DEFAULT);
$today = date('Y-m-d');                       // pass_exp_date = today => +3mo not expired => no forced change-password

echo "Creating/refreshing e2e_* accounts in " . e2e_dbname() . " ...\n";
foreach (e2e_accounts() as $role => $a) {
    [$name, $pos, $active, $assign, $add, $manage, $modify, $on_service, $specialty] = $a;
    $email = $name . '@example.test';
    $full  = 'E2E ' . ucfirst($role);

    $existing = e2e_member_id($name);
    if ($existing) {
        $sql = "UPDATE members SET member_password=?, mfa_secret=NULL, mfa_recovery_codes=NULL, mfa_enrolled_at=NULL,
                member_email=?, position=?, active=?, pass_exp_date=?, full_name=?, on_service=?, specialty_id=?,
                assign_access=?, add_new_patient=?, manage_patient=?, modify_patient=? WHERE member_id=?";
        $st = $db->prepare($sql);
        $st->bind_param('ssisssiiiiiii', $hash, $email, $pos, $active, $today, $full, $on_service, $specialty,
            $assign, $add, $manage, $modify, $existing);
        $st->execute();
        printf("  refreshed #%d %-16s pos=%d caps[a=%d add=%d m=%d mod=%d]\n", $existing, $name, $pos, $assign, $add, $manage, $modify);
    } else {
        $newId = (int) $db->query("SELECT COALESCE(MAX(member_id),0)+1 AS n FROM members")->fetch_assoc()['n'];
        $sql = "INSERT INTO members SET member_id=?, member_name=?, member_password=?, member_email=?, position=?,
                active=?, pass_exp_date=?, full_name=?, on_service=?, specialty_id=?, assign_access=?, add_new_patient=?,
                manage_patient=?, modify_patient=?";
        $st = $db->prepare($sql);
        $st->bind_param('isssiissiiiiii', $newId, $name, $hash, $email, $pos, $active, $today, $full, $on_service,
            $specialty, $assign, $add, $manage, $modify);
        $st->execute();
        printf("  created   #%d %-16s pos=%d caps[a=%d add=%d m=%d mod=%d]\n", $newId, $name, $pos, $assign, $add, $manage, $modify);
    }
}

echo "\nLogin smoke test (real HTTP) ...\n";
foreach (array_keys(e2e_accounts()) as $role) {
    $ok = e2e_login($role);
    $ctx = e2e_ctx($role);
    t_ok("login as $role", $ok, $ok ? ("member_id=" . $ctx['member_id'] . " csrf=" . substr($ctx['csrf'], 0, 8) . "…") : 'FAILED');
}

exit(e2e_summary());
