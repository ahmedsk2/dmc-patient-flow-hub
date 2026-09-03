#!/usr/bin/env bash
# deploy-on-green.sh — host-side, opt-in continuous deployment for the Laravel app (CICD-04/08).
#
# Deploys `main` through Coolify ONLY when all of the following hold, then runs the smoke test:
#   1. the Laravel CI workflow run for the exact HEAD commit of `main` concluded "success"
#      (GitHub's public API — the repository is public; no token needed while it stays so), and
#   2. that commit is not already the image running in the app container (Coolify tags the image
#      with the commit SHA), and
#   3. no Coolify deployment for the app is currently in progress.
#
# NOT installed by default (docs/DEPLOY-LARAVEL.md §6/§10): the operator decides whether to move
# from operator-triggered deploys to this. To enable: put a Coolify API token in a root-only file
# (see COOLIFY_TOKEN_FILE), then a root cron such as `*/5 * * * * /usr/local/bin/deploy-on-green.sh`.
# The token is read from the file and never echoed. Exit 0 = nothing to do or deployed+smoke passed.
set -euo pipefail

REPO="ahmedsk2/dmc-patient-flow-hub"
BRANCH="main"
WORKFLOW_NAME="Laravel CI"
APP_UUID="v5d8vrnp418stpcwnup3yhta"
COOLIFY_API="http://localhost:8000/api/v1"
COOLIFY_TOKEN_FILE="${COOLIFY_TOKEN_FILE:-/root/.coolify-deploy-token}"   # mode 600, root-only
PUBLIC_URL="https://dmc-new.towardpcc.com"
SMOKE="${SMOKE:-/opt/dmc/laravel/scripts/smoke.sh}"                        # a checkout of the same commit
LOG_TAG="deploy-on-green"

log() { logger -t "$LOG_TAG" -- "$*" 2>/dev/null || true; echo "$(date -Is) $*"; }

# 1. HEAD of main and its CI conclusion (public API, unauthenticated, 60 req/h is plenty for */5)
HEAD=$(curl -fsS -m 20 -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$REPO/commits/$BRANCH" | python3 -c 'import sys,json; print(json.load(sys.stdin)["sha"])')
RUNS=$(curl -fsS -m 20 -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$REPO/actions/runs?branch=$BRANCH&head_sha=$HEAD&per_page=10")
CONCLUSION=$(echo "$RUNS" | python3 -c "
import sys,json
runs=[r for r in json.load(sys.stdin).get('workflow_runs',[]) if r.get('name')=='$WORKFLOW_NAME']
print(runs[0]['conclusion'] if runs and runs[0].get('status')=='completed' else 'pending')")
[ "$CONCLUSION" = "success" ] || { log "main@${HEAD:0:7}: CI $CONCLUSION — not deploying"; exit 0; }

# 2. already live?
APP=$(docker ps -q -f "label=coolify.name=$APP_UUID" | head -1)
LIVE=$(docker inspect --format '{{.Config.Image}}' "$APP" 2>/dev/null | sed -E 's/.*://')
[ "$LIVE" = "$HEAD" ] && { log "main@${HEAD:0:7} already live"; exit 0; }

# 3. token + no deployment in progress
[ -r "$COOLIFY_TOKEN_FILE" ] || { log "no token file at $COOLIFY_TOKEN_FILE — not enabled"; exit 0; }
TOKEN=$(tr -d '\r\n' < "$COOLIFY_TOKEN_FILE")
AUTH="Authorization: Bearer $TOKEN"
INPROG=$(curl -fsS -m 20 -H "$AUTH" "$COOLIFY_API/deployments" | python3 -c "
import sys,json
d=json.load(sys.stdin); d=d if isinstance(d,list) else d.get('deployments',d.get('data',[]))
print(sum(1 for x in d if x.get('application_uuid','')=='$APP_UUID' and x.get('status') in ('in_progress','queued')))" 2>/dev/null || echo 0)
[ "${INPROG:-0}" = "0" ] || { log "a deployment is already in progress"; exit 0; }

# 4. deploy + poll
D=$(curl -fsS -m 30 -H "$AUTH" "$COOLIFY_API/deploy?uuid=$APP_UUID" | python3 -c 'import sys,json; print(json.load(sys.stdin)["deployments"][0]["deployment_uuid"])')
log "deploying main@${HEAD:0:7} (deployment $D)"
for _ in $(seq 1 40); do
  sleep 20
  S=$(curl -fsS -m 20 -H "$AUTH" "$COOLIFY_API/deployments/$D" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("status"))')
  case "$S" in finished) break;; failed|cancelled) log "deployment $D $S"; exit 1;; esac
done
[ "${S:-}" = "finished" ] || { log "deployment $D did not finish in time"; exit 1; }

# 5. smoke against the public hostname; a red smoke is the rollback trigger (DEPLOY-LARAVEL.md §4)
if [ -x "$SMOKE" ]; then
  if bash "$SMOKE" "$PUBLIC_URL"; then log "main@${HEAD:0:7} deployed and smoke PASS"; else log "SMOKE FAILED after deploying ${HEAD:0:7} — roll back per §4.1"; exit 2; fi
else
  log "deployed ${HEAD:0:7}; smoke script not found at $SMOKE (skipped)"
fi
