<?php
/**
 * tools/mfa_test.php — correctness harness for mfa.php.
 *
 * The TOTP core is validated against the PUBLISHED RFC-6238 Appendix-B test vectors
 * (SHA-1, secret "12345678901234567890", 8 digits). If these pass, the implementation
 * interoperates with Google Authenticator / Authy / Microsoft Authenticator. Also
 * round-trips Base32, the 6-digit verify window, AES-256-GCM at-rest encryption, and
 * single-use recovery codes.
 *
 *   php tools/mfa_test.php       # exit 0 = all pass
 */
require_once __DIR__ . '/../mfa.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ✓ %-46s\n", $label); }
    else { $fail++; printf("  ✗ %-46s got[%s] want[%s]\n", $label, var_export($got, true), var_export($want, true)); }
}

echo "RFC-6238 Appendix-B vectors (secret \"12345678901234567890\", SHA-1, 8 digits):\n";
$secretB32 = mfa_base32_encode('12345678901234567890');
check('base32(ASCII secret) == known', $secretB32, 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
$vectors = [
    [59,          '94287082'],
    [1111111109,  '07081804'],
    [1111111111,  '14050471'],
    [1234567890,  '89005924'],
    [2000000000,  '69279037'],
    [20000000000, '65353130'],
];
foreach ($vectors as [$t, $want]) {
    check("TOTP(t=$t, 8 digits)", mfa_totp($secretB32, $t, 8), $want);
}

echo "\nBase32 round-trip:\n";
foreach (['', 'A', 'hello world', "\x00\x01\x02\xff", random_bytes(20)] as $i => $bin) {
    check("round-trip #$i", mfa_base32_decode(mfa_base32_encode($bin)), $bin);
}

echo "\n6-digit verify (the real authenticator config):\n";
$sec = mfa_generate_secret();
$now = 1700000000;
$code6 = mfa_totp($sec, $now, 6);
check('6-digit code length', strlen($code6), 6);
check('verify current step', mfa_totp_verify($sec, $code6, $now) !== false, true);
check('verify accepts -1 step (drift)', mfa_totp_verify($sec, mfa_totp($sec, $now - 30, 6), $now) !== false, true);
check('verify accepts +1 step (drift)', mfa_totp_verify($sec, mfa_totp($sec, $now + 30, 6), $now) !== false, true);
check('verify rejects -2 steps (outside window)', mfa_totp_verify($sec, mfa_totp($sec, $now - 60, 6), $now), false);
check('verify rejects wrong code', mfa_totp_verify($sec, '000000', $now) === false || mfa_totp($sec, $now, 6) === '000000', true);
check('verify rejects non-numeric', mfa_totp_verify($sec, 'abcdef', $now), false);

echo "\nAES-256-GCM at-rest encryption of the secret:\n";
if (mfa_key_available()) {
    $enc = mfa_encrypt($sec);
    check('encrypt produces ciphertext', is_string($enc) && $enc !== $sec, true);
    check('decrypt round-trips', mfa_decrypt($enc), $sec);
    check('two encryptions differ (random IV)', mfa_encrypt($sec) !== mfa_encrypt($sec), true);
    check('tampered ciphertext fails (GCM auth)', mfa_decrypt(strrev($enc)), null);
} else {
    echo "  (skipped — MFA_KEY not configured; set it in config.local.php to test crypto)\n";
}

echo "\nRecovery codes (single-use):\n";
$codes = mfa_generate_recovery_codes(10);
check('generates 10 codes', count($codes), 10);
$json = mfa_hash_recovery_codes($codes);
check('stored as hashes (not plaintext)', strpos($json, $codes[0]) === false, true);
$store = $json;
check('valid code consumes', mfa_consume_recovery_code($codes[3], $store), true);
check('same code cannot be reused', mfa_consume_recovery_code($codes[3], $store), false);
check('a different code still works', mfa_consume_recovery_code($codes[7], $store), true);
check('unknown code rejected', mfa_consume_recovery_code('zzzzz-zzzzz', $store), false);
check('8 codes remain after 2 used', count(json_decode($store, true)), 8);

echo "\n" . ($fail === 0 ? "✓ ALL $pass CHECKS PASS\n" : "✗ $fail FAILED, $pass passed\n");
exit($fail === 0 ? 0 : 1);
