#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for STORY-2.5 (security hardening).
#
# Verifies against a live API:
#   - HTTPS redirect (HTTP -> 301)
#   - HSTS header on HTTPS responses
#   - API-Key authentication (hash lookup, missing, invalid)
#   - Rate limiting (60/min/device on heartbeat, returns 429 + Retry-After)
#   - Admin key rotation (old key invalidated, new key immediately works)
#
# Required environment:
#   ADMIN_EMAIL     admin account email (for key-rotation test)
#   ADMIN_PASSWORD  admin account password
#
# Optional:
#   BASE_URL        default: https://oliverlohkemper.de/rest2
#
# Usage:
#   ADMIN_EMAIL=... ADMIN_PASSWORD=... ./integration-test-iot-security.sh

set -o pipefail

BASE_URL="${BASE_URL:-https://oliverlohkemper.de/rest2}"
HTTP_URL="${BASE_URL/https:\/\//http://}"
CHIP_ID="security-test"
COOKIE_JAR="$(mktemp -t olo-session.XXXXXX)"
trap 'rm -f "$COOKIE_JAR"' EXIT

PASS=0
FAIL=0
FAILED_TESTS=()

if [[ -z "$ADMIN_EMAIL" || -z "$ADMIN_PASSWORD" ]]; then
    echo "ERROR: set ADMIN_EMAIL and ADMIN_PASSWORD env vars" >&2
    exit 2
fi

assert() {
    local label="$1"
    local condition="$2"
    if [[ "$condition" == "true" ]]; then
        echo "  [PASS] $label"
        PASS=$((PASS + 1))
    else
        echo "  [FAIL] $label"
        FAIL=$((FAIL + 1))
        FAILED_TESTS+=("$label")
    fi
}

echo "=== Integration Test: IoT Security (STORY-2.5) ==="
echo "Base URL: $BASE_URL"
echo

# ---- [1] HTTPS redirect: HTTP -> 301 -----------------------------------
echo "[1] HTTP -> HTTPS 301 redirect"
headers=$(curl -sS -I "$HTTP_URL/iot/health" 2>&1)
http_code=$(echo "$headers" | head -n1 | awk '{print $2}')
location=$(echo "$headers" | grep -i '^Location:' | tr -d '\r' | awk '{print $2}')
assert "HTTP returns 301"                  "$([[ "$http_code" == "301" ]] && echo true || echo false)"
assert "Location points to HTTPS"           "$([[ "$location" == https://* ]] && echo true || echo false)"
echo

# ---- [2] HSTS header on HTTPS ------------------------------------------
echo "[2] HSTS header on HTTPS response"
headers=$(curl -sS -I "$BASE_URL/iot/health" 2>&1)
hsts=$(echo "$headers" | grep -i '^Strict-Transport-Security:' | tr -d '\r')
assert "HSTS header present"                "$([[ -n "$hsts" ]] && echo true || echo false)"
assert "HSTS includes includeSubDomains"    "$(echo "$hsts" | grep -qi 'includeSubDomains' && echo true || echo false)"
echo

# ---- [3] Register a test device (fresh api key) ------------------------
echo "[3] Register test device (chip_id=$CHIP_ID)"
reg_body=$(cat <<EOF
{"chip_id":"$CHIP_ID","name":"Security Integration Test","typ":"esp32","firmware_version":"1.0.0"}
EOF
)
reg_response=$(curl -sS -X POST "$BASE_URL/iot/register" \
    -H "Content-Type: application/json" -d "$reg_body")
DEVICE_ID=$(echo "$reg_response" | python -c "import sys,json; print(json.load(sys.stdin).get('device_id',''))" 2>/dev/null)
INITIAL_KEY=$(echo "$reg_response" | python -c "import sys,json; print(json.load(sys.stdin).get('api_key',''))" 2>/dev/null)
assert "Register returned a device_id"      "$([[ -n "$DEVICE_ID" && "$DEVICE_ID" =~ ^[0-9]+$ ]] && echo true || echo false)"
assert "Register returned an api_key"       "$([[ -n "$INITIAL_KEY" && ${#INITIAL_KEY} -eq 64 ]] && echo true || echo false)"
echo "    device_id=$DEVICE_ID"
echo

# ---- [4] Heartbeat with valid key -> 200 -------------------------------
echo "[4] Heartbeat with valid key -> 200 (hash lookup works)"
resp=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/iot/heartbeat" \
    -H "Content-Type: application/json" -H "X-Api-Key: $INITIAL_KEY" -d '{}')
assert "HTTP 200 with valid api key"        "$([[ "$resp" == "200" ]] && echo true || echo false)"
echo

# ---- [5] Heartbeat without key -> 401 ----------------------------------
echo "[5] Heartbeat without key -> 401"
resp=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/iot/heartbeat" \
    -H "Content-Type: application/json" -d '{}')
assert "HTTP 401 without key"               "$([[ "$resp" == "401" ]] && echo true || echo false)"
echo

# ---- [6] Heartbeat with bogus key -> 401 -------------------------------
echo "[6] Heartbeat with bogus key -> 401"
resp=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/iot/heartbeat" \
    -H "Content-Type: application/json" -H "X-Api-Key: invalid-no-such-key" -d '{}')
assert "HTTP 401 with bogus key"            "$([[ "$resp" == "401" ]] && echo true || echo false)"
echo

# ---- [7] Rate limit on heartbeat (60/min/device) -----------------------
echo "[7] Rate limit: burst 65 heartbeats -> at least one 429"
codes=$(for i in $(seq 1 65); do
    curl -sS -o /dev/null -w "%{http_code}\n" -X POST "$BASE_URL/iot/heartbeat" \
        -H "Content-Type: application/json" -H "X-Api-Key: $INITIAL_KEY" -d '{}'
done)
count_429=$(echo "$codes" | grep -c '^429$' || true)
assert "at least one 429 in burst"          "$([[ "$count_429" -gt 0 ]] && echo true || echo false)"
retry=$(curl -sS -i -X POST "$BASE_URL/iot/heartbeat" \
    -H "Content-Type: application/json" -H "X-Api-Key: $INITIAL_KEY" -d '{}' \
    | grep -i '^Retry-After:' | tr -d '\r' | awk '{print $2}')
assert "Retry-After header present"         "$([[ -n "$retry" ]] && echo true || echo false)"
assert "Retry-After within window (<=60s)"  "$([[ -n "$retry" && "$retry" -le 60 ]] && echo true || echo false)"
echo "    429 count in burst: $count_429, Retry-After: ${retry:-?}s"
echo

# ---- [8] Login as admin -------------------------------------------------
echo "[8] Login as admin for rotate-key test"
login_body=$(python -c "import json,sys,os; print(json.dumps({'email':os.environ['ADMIN_EMAIL'],'password':os.environ['ADMIN_PASSWORD']}))")
login_code=$(curl -sS -o /dev/null -w "%{http_code}" -c "$COOKIE_JAR" -X POST "$BASE_URL/auth/login" \
    -H "Content-Type: application/json" -d "$login_body")
assert "Login HTTP 200"                     "$([[ "$login_code" == "200" ]] && echo true || echo false)"
echo

# ---- [9] Rotate key for test device -> 200 + new api_key ---------------
echo "[9] POST /iot/devices/$DEVICE_ID/rotate-key -> 200"
rot_response=$(curl -sS -b "$COOKIE_JAR" -X POST "$BASE_URL/iot/devices/$DEVICE_ID/rotate-key")
NEW_KEY=$(echo "$rot_response" | python -c "import sys,json; print(json.load(sys.stdin).get('api_key',''))" 2>/dev/null)
rotated_at=$(echo "$rot_response" | python -c "import sys,json; print(json.load(sys.stdin).get('rotated_at',''))" 2>/dev/null)
assert "Rotation returned a new api_key"    "$([[ -n "$NEW_KEY" && ${#NEW_KEY} -eq 64 ]] && echo true || echo false)"
assert "Rotation returned rotated_at"       "$([[ -n "$rotated_at" ]] && echo true || echo false)"
assert "New key differs from old key"       "$([[ "$NEW_KEY" != "$INITIAL_KEY" ]] && echo true || echo false)"
echo

# ---- [10] Rotate for unknown device id -> 404 --------------------------
echo "[10] POST /iot/devices/99999/rotate-key -> 404"
resp=$(curl -sS -o /dev/null -w "%{http_code}" -b "$COOKIE_JAR" -X POST "$BASE_URL/iot/devices/99999/rotate-key")
assert "HTTP 404 for unknown device"        "$([[ "$resp" == "404" ]] && echo true || echo false)"
echo

# ---- [11] Old key is rejected, new key works --------------------------
# Wait until the heartbeat rate-limit window has expired so we can
# actually exercise the auth path instead of bouncing off 429.
echo "[11] Wait for rate-limit window, then verify old key 401 and new key 200"
sleep 65
resp_old=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/iot/heartbeat" \
    -H "Content-Type: application/json" -H "X-Api-Key: $INITIAL_KEY" -d '{}')
resp_new=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/iot/heartbeat" \
    -H "Content-Type: application/json" -H "X-Api-Key: $NEW_KEY" -d '{}')
assert "Old key returns 401 after rotation" "$([[ "$resp_old" == "401" ]] && echo true || echo false)"
assert "New key returns 200 after rotation" "$([[ "$resp_new" == "200" ]] && echo true || echo false)"
echo

# ---- Summary -----------------------------------------------------------
echo "=== Summary ==="
echo "Passed: $PASS"
echo "Failed: $FAIL"
if [[ $FAIL -gt 0 ]]; then
    echo
    echo "Failed tests:"
    for t in "${FAILED_TESTS[@]}"; do
        echo "  - $t"
    done
    exit 1
fi
exit 0
