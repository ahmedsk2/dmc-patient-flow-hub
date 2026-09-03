#!/usr/bin/env bash
# laravel/scripts/smoke.sh — post-deploy smoke test for the DMC Internal Medicine Laravel app.
#
# Runs from ANY machine that has bash + curl (Git Bash on Windows is fine). It is strictly
# read-only: a handful of GETs against public or auth-gated URLs. It never logs in, never sends
# credentials, never fetches or prints patient data.
#
#   BASE_URL=https://dmc-new.towardpcc.com bash laravel/scripts/smoke.sh
#
# Each check prints PASS / WARN / FAIL (or SKIP). Exit status is 1 if ANY check FAILed, else 0.
# WARN never fails the run — it marks things expected to be absent until a parallel workstream
# ships them (/health, /.well-known/security.txt) or a proxy-layer nuisance worth knowing about.
#
# Environment:
#   BASE_URL        origin to test                       (default: the production host)
#   SMOKE_TIMEOUT   per-request timeout, seconds         (default: 20)
#   MANIFEST        path to public/build/manifest.json   (default: ../public/build/manifest.json
#                   relative to this script). The bundle-hash check is SKIPPED when the file is
#                   not there, i.e. when the script runs outside a repository checkout.
#
# The bundle-hash check compares the JS/CSS the server actually serves on /login with the
# committed Vite manifest of THIS checkout — so run it with the deployed commit checked out.
# A mismatch means either the deploy did not take, or you are not on the deployed commit.

set -u -o pipefail

usage() {
    sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}
case "${1:-}" in
    -h|--help) usage; exit 0 ;;
esac

BASE_URL="${BASE_URL:-https://dmc-new.towardpcc.com}"
BASE_URL="${BASE_URL%/}"
TIMEOUT="${SMOKE_TIMEOUT:-20}"
UA="dmc-smoke/1.0"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST="${MANIFEST:-$SCRIPT_DIR/../public/build/manifest.json}"

if ! command -v curl >/dev/null 2>&1; then
    echo "FAIL  curl is not installed or not on PATH"
    exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass_n=0; warn_n=0; fail_n=0; skip_n=0
pass() { pass_n=$((pass_n + 1)); printf 'PASS  %s\n' "$1"; }
warn() { warn_n=$((warn_n + 1)); printf 'WARN  %s\n' "$1"; }
fail() { fail_n=$((fail_n + 1)); printf 'FAIL  %s\n' "$1"; }
skip() { skip_n=$((skip_n + 1)); printf 'SKIP  %s\n' "$1"; }

# fetch <name> <path>
#   Sets CODE (HTTP status, "000" on transport failure) and CURL_RC (curl exit code).
#   Body → $TMP/<name>.body, response headers (CR stripped) → $TMP/<name>.hdr, stderr → .err
#   Redirects are NOT followed: a 302 is itself the thing several checks assert.
fetch() {
    local name="$1" path="$2"
    CURL_RC=0
    CODE="$(curl -sS --max-time "$TIMEOUT" -A "$UA" \
        -o "$TMP/$name.body" -D "$TMP/$name.hdr.raw" -w '%{http_code}' \
        "$BASE_URL$path" 2>"$TMP/$name.err")" || CURL_RC=$?
    if [ -f "$TMP/$name.hdr.raw" ]; then
        tr -d '\r' <"$TMP/$name.hdr.raw" >"$TMP/$name.hdr"
    else
        : >"$TMP/$name.hdr"
    fi
    [ -f "$TMP/$name.body" ] || : >"$TMP/$name.body"
}

# header <name> <header-name>  → every value of that header, one per line (case-insensitive)
header() {
    grep -i "^$2:" "$TMP/$1.hdr" | sed -E 's/^[^:]+:[[:space:]]*//'
}
header_count() {
    grep -ic "^$2:" "$TMP/$1.hdr" || true
}
curl_error() {
    printf 'curl error %s: %s' "$CURL_RC" "$(tr -d '\n' <"$TMP/$1.err" | head -c 200)"
}
body_excerpt() {
    head -c 300 "$TMP/$1.body" | tr -d '\n'
}

# manifest_file <entry-key>  → the built filename Vite recorded for that source entry
manifest_file() {
    awk -v key="\"$1\": {" '
        index($0, key) { inside = 1; next }
        inside && /"file"[[:space:]]*:/ {
            sub(/.*"file"[[:space:]]*:[[:space:]]*"/, ""); sub(/".*/, ""); print; exit
        }
        inside && /^  }/ { exit }
    ' "$MANIFEST"
}

echo "DMC smoke test  $BASE_URL  $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo

# ── 1. Liveness ────────────────────────────────────────────────────────────────────────────────
fetch up /up
if [ "$CURL_RC" -ne 0 ]; then
    fail "/up — $(curl_error up)"
elif [ "$CODE" = "200" ]; then
    pass "/up → 200 (framework boots)"
elif [ "$CODE" = "403" ] && grep -qi cloudflare "$TMP/up.body"; then
    fail "/up → 403 from Cloudflare (bot challenge is blocking curl — every check below will fail for the same reason)"
else
    fail "/up → $CODE (expected 200)"
fi

# ── 2. Health (db, storage, scheduler heartbeat) — WARN while not yet deployed ────────────────
fetch health /health
if [ "$CURL_RC" -ne 0 ]; then
    fail "/health — $(curl_error health)"
elif [ "$CODE" = "404" ]; then
    warn "/health → 404 (endpoint not deployed yet — expected until the health-endpoint workstream ships)"
elif [ "$CODE" = "200" ]; then
    if grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$TMP/health.body"; then
        pass "/health → 200, status ok"
    else
        fail "/health → 200 but status is not ok: $(body_excerpt health)"
    fi
else
    fail "/health → $CODE: $(body_excerpt health)"
fi

# ── 3. Login page renders with a CSRF token ───────────────────────────────────────────────────
LOGIN_OK=0
fetch login /login
if [ "$CURL_RC" -ne 0 ]; then
    fail "/login — $(curl_error login)"
elif [ "$CODE" = "200" ]; then
    if grep -q 'name="csrf-token"' "$TMP/login.body"; then
        LOGIN_OK=1
        pass "/login → 200 with <meta name=\"csrf-token\">"
    else
        fail "/login → 200 but no csrf-token meta tag (root Blade layout not rendered?)"
    fi
else
    fail "/login → $CODE (expected 200)"
fi

# ── 4. Security headers (inspected on /login: a `web` route, so SecurityHeaders applies) ──────
if [ "$CURL_RC" -eq 0 ] && [ -s "$TMP/login.hdr" ]; then
    hsts="$(header login strict-transport-security | head -1)"
    if [ -n "$hsts" ]; then
        pass "strict-transport-security: $hsts"
    else
        fail "strict-transport-security missing (the app sends it only when the request is seen as HTTPS — TrustProxies / forwarded headers?)"
    fi

    csp="$(header login content-security-policy | head -1)"
    csp_ro="$(header login content-security-policy-report-only | head -1)"
    if [ -n "$csp" ]; then
        case "$csp" in
            *"'nonce-"*) pass "content-security-policy enforced, script-src carries a per-request nonce" ;;
            *)           fail "content-security-policy present but has no 'nonce-' (script-src is not nonce-locked)" ;;
        esac
    elif [ -n "$csp_ro" ]; then
        fail "CSP is Report-Only (CSP_MODE=report) — production must enforce"
    else
        fail "content-security-policy missing (CSP_MODE=off, or a public/hot dev sentinel is inside the image)"
    fi

    xfo_n="$(header_count login x-frame-options)"
    xfo="$(header login x-frame-options | tr '\n' ' ' | sed 's/ $//')"
    if printf '%s' "$xfo" | grep -qi 'deny'; then
        if [ "$xfo_n" -gt 1 ]; then
            warn "x-frame-options sent $xfo_n times (\"$xfo\") — a proxy layer adds its own; the app's DENY is present"
        else
            pass "x-frame-options: DENY"
        fi
    else
        fail "x-frame-options: \"${xfo:-<missing>}\" (expected DENY)"
    fi

    xcto_n="$(header_count login x-content-type-options)"
    xcto="$(header login x-content-type-options | tr '\n' ' ' | sed 's/ $//')"
    if printf '%s' "$xcto" | grep -qi 'nosniff'; then
        if [ "$xcto_n" -gt 1 ]; then
            warn "x-content-type-options sent $xcto_n times — duplicate from a proxy layer; nosniff is present"
        else
            pass "x-content-type-options: nosniff"
        fi
    else
        fail "x-content-type-options: \"${xcto:-<missing>}\" (expected nosniff)"
    fi

    xpb="$(header login x-powered-by | head -1)"
    if [ -z "$xpb" ]; then
        pass "x-powered-by absent"
    else
        fail "x-powered-by leaks \"$xpb\" — set expose_php=Off in the image's php.ini, or strip it at the proxy"
    fi
else
    fail "security headers — no /login response to inspect"
fi

# ── 5. Consultation-ledger routes exist (auth redirect proves the route, no login needed) ─────
for path in /consultations /consultations/handover /consultations/dashboard; do
    name="r$(printf '%s' "$path" | tr '/' '_')"
    fetch "$name" "$path"
    if [ "$CURL_RC" -ne 0 ]; then
        fail "$path — $(curl_error "$name")"
    elif [ "$CODE" = "302" ]; then
        loc="$(header "$name" location | head -1)"
        case "$loc" in
            *"/login"*) pass "$path → 302 → /login (route exists, auth gate in place)" ;;
            *)          warn "$path → 302 → ${loc:-<no Location>} (expected the /login redirect)" ;;
        esac
    elif [ "$CODE" = "404" ]; then
        fail "$path → 404 (route missing — consultation ledger not deployed?)"
    else
        fail "$path → $CODE (expected 302 auth redirect)"
    fi
done

# ── 6. Vulnerability-disclosure contact — WARN until shipped ──────────────────────────────────
fetch sectxt /.well-known/security.txt
if [ "$CURL_RC" -ne 0 ]; then
    fail "/.well-known/security.txt — $(curl_error sectxt)"
elif [ "$CODE" = "200" ]; then
    if grep -qi '^Contact:' "$TMP/sectxt.body"; then
        pass "/.well-known/security.txt → 200 with a Contact: line"
    else
        warn "/.well-known/security.txt → 200 but has no Contact: line (RFC 9116 requires Contact + Expires)"
    fi
elif [ "$CODE" = "404" ]; then
    warn "/.well-known/security.txt → 404 (not shipped yet)"
else
    fail "/.well-known/security.txt → $CODE"
fi

# ── 7. Served bundle == committed public/build (only meaningful from a repo checkout) ─────────
if [ -f "$MANIFEST" ]; then
    js="$(manifest_file resources/js/app.js)"
    css="$(manifest_file resources/css/app.css)"
    if [ -z "$js" ]; then
        warn "bundle check — no resources/js/app.js entry in $MANIFEST (manifest format changed?)"
    elif [ "$LOGIN_OK" -ne 1 ]; then
        fail "bundle check — no /login HTML to compare against the manifest"
    else
        if grep -q "/build/$js" "$TMP/login.body"; then
            pass "served JS bundle matches this checkout's manifest ($js)"
        else
            served="$(grep -oE '/build/assets/app-[A-Za-z0-9_-]+\.js' "$TMP/login.body" | head -1)"
            fail "served JS bundle is ${served:-<none found in /login HTML>} but this checkout's manifest says /build/$js — the deploy did not take, or you are not on the deployed commit"
        fi
        if [ -n "$css" ]; then
            if grep -q "/build/$css" "$TMP/login.body"; then
                pass "served CSS bundle matches this checkout's manifest ($css)"
            else
                served="$(grep -oE '/build/assets/app-[A-Za-z0-9_-]+\.css' "$TMP/login.body" | head -1)"
                fail "served CSS bundle is ${served:-<none found>} but this checkout's manifest says /build/$css"
            fi
        fi
    fi
else
    skip "bundle-hash check — $MANIFEST not found (run from a repository checkout at the deployed commit to enable)"
fi

# ── Summary ───────────────────────────────────────────────────────────────────────────────────
echo
printf 'Summary: %d pass, %d warn, %d fail, %d skip  —  %s\n' \
    "$pass_n" "$warn_n" "$fail_n" "$skip_n" "$BASE_URL"

if [ "$fail_n" -eq 0 ]; then
    exit 0
fi
exit 1
