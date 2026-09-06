#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for network assignment on approval (STORY-11.4 / TASK-11.4.3).
#
# Verifies:
#   - POST /iot/devices/{id}/approve with {"network_id": N} binds the device to N
#   - an unknown network_id is rejected with 400 and the device stays pending
#   - approve without network_id keeps the network set at registration
#   - re-registration (X-Api-Key) keeps the assigned network instead of falling
#     back to the server-side active one
#   - the heartbeat delivers the credentials of the assigned network
#
# Auth required: IOT_ADMIN_EMAIL, IOT_ADMIN_PASSWORD (admin session) and
# IOT_FLEET_TOKEN (first registration needs X-Provisioning-Token).
#
# Usage:
#   IOT_ADMIN_EMAIL=admin@example.com IOT_ADMIN_PASSWORD=... IOT_FLEET_TOKEN=... \
#     ./integration-test-iot-approve-network.sh [BASE_URL]
#
# Default BASE_URL: https://oliverlohkemper.de/rest2
#
# Leaves a test network and two test devices behind (chip ids start with
# "test-approve-"); the network is deleted at the end only if no device still
# references it.

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
PASS=0
FAIL=0
FAILED_TESTS=()
COOKIE_JAR=$(mktemp)
CSRF_TOKEN=""
trap 'rm -f "$COOKIE_JAR"' EXIT

RUN_ID=$(date +%s)
TEST_CHIP_A="test-approve-a-${RUN_ID}"
TEST_CHIP_B="test-approve-b-${RUN_ID}"

if [[ -z "$IOT_ADMIN_EMAIL" || -z "$IOT_ADMIN_PASSWORD" || -z "$IOT_FLEET_TOKEN" ]]; then
    echo "ERROR: set IOT_ADMIN_EMAIL, IOT_ADMIN_PASSWORD and IOT_FLEET_TOKEN env vars" >&2
    exit 2
fi

# ---------- helpers ----------

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

json_field() {
    python -c "import sys,json; v=json.load(sys.stdin).get('$1',''); print('' if v is None else v)"
}

json_nested() {
    python -c "import sys,json; d=json.load(sys.stdin)
for k in '$1'.split('.'):
    d = d.get(k, {}) if isinstance(d, dict) else {}
print('' if d in ({}, None) else d)"
}

do_login() {
    local resp code
    resp=$(curl -s -w "\n%{http_code}" -c "$COOKIE_JAR" \
        -X POST "${BASE_URL}/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"$IOT_ADMIN_EMAIL\",\"password\":\"$IOT_ADMIN_PASSWORD\"}")
    code=$(echo "$resp" | tail -n1)
    if [[ "$code" != "200" ]]; then
        echo "ERROR: login failed (HTTP $code)" >&2
        echo "$resp" | sed '$d' >&2
        exit 2
    fi
    # Admin POST/PUT/DELETE calls need the session CSRF token from the login response
    CSRF_TOKEN=$(echo "$resp" | sed '$d' | json_field csrfToken)
    if [[ -z "$CSRF_TOKEN" ]]; then
        echo "ERROR: login response carries no csrfToken" >&2
        exit 2
    fi
    # Accounts with MFA get a pending session first; complete it with
    # IOT_MFA_METHOD (totp|email|backup) + IOT_MFA_CODE, or interactively.
    if [[ "$(echo "$resp" | sed '$d' | json_field mfaRequired)" == "True" ]]; then
        local method="${IOT_MFA_METHOD:-}" mfa_code="${IOT_MFA_CODE:-}"
        if [[ -z "$method" || -z "$mfa_code" ]]; then
            if [[ -t 0 ]]; then
                echo "  [INFO] MFA required, methods: $(echo "$resp" | sed '$d' | python -c 'import sys,json; print(", ".join(json.load(sys.stdin).get("methods", [])))')"
                read -rp "  MFA method [totp/email/backup]: " method
                if [[ "$method" == "email" ]]; then
                    curl -s -o /dev/null -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "${BASE_URL}/auth/mfa-email-send" \
                        -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_TOKEN" -d '{}'
                fi
                read -rp "  MFA code: " mfa_code
            else
                echo "ERROR: MFA required — set IOT_MFA_METHOD and IOT_MFA_CODE" >&2
                exit 2
            fi
        fi
        resp=$(curl -s -w "\n%{http_code}" -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
            -X POST "${BASE_URL}/auth/mfa-verify" \
            -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_TOKEN" \
            -d "{\"method\":\"$method\",\"code\":\"$mfa_code\",\"rememberDevice\":false}")
        code=$(echo "$resp" | tail -n1)
        if [[ "$code" != "200" ]]; then
            echo "ERROR: MFA verify failed (HTTP $code)" >&2
            echo "$resp" | sed '$d' >&2
            exit 2
        fi
        CSRF_TOKEN=$(echo "$resp" | sed '$d' | json_field csrfToken)
    fi
    echo "  [INFO] logged in as $IOT_ADMIN_EMAIL"
}

# admin call with session cookie
api() {
    local method="$1" path="$2" body="${3:-}"
    if [[ -n "$body" ]]; then
        curl -s -w "\n%{http_code}" -b "$COOKIE_JAR" -X "$method" "${BASE_URL}${path}" \
            -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF_TOKEN" -d "$body"
    else
        curl -s -w "\n%{http_code}" -b "$COOKIE_JAR" -X "$method" "${BASE_URL}${path}" \
            -H "X-CSRF-Token: $CSRF_TOKEN"
    fi
}

# device call: first registration with fleet token, re-registration with api key
post_register() {
    local header="$1" payload="$2"
    curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/iot/register" \
        -H "Content-Type: application/json" -H "$header" -d "$payload"
}

register_payload() {
    local chip="$1"
    cat <<EOF
{
  "chip_id": "$chip",
  "name": "Approve-Test $chip",
  "typ": "esp8266",
  "firmware_version": "1.0.0",
  "sensoren": [
    {"id": "temp", "typ": "temperature", "einheit": "C", "modell": "DS18B20",
     "intervall_sekunden": 30, "mqtt_topic": "pks/test/$chip/temp"}
  ]
}
EOF
}

heartbeat() {
    local key="$1"
    curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/iot/heartbeat" \
        -H "Content-Type: application/json" -H "X-Api-Key: $key" -d '{}'
}

# ---------- tests ----------

echo "=== Integration Test: network assignment on approve (STORY-11.4 / TASK-11.4.3) ==="
echo "Base URL: $BASE_URL"
do_login
echo

# ---- [1] create a second, inactive network ----
echo "[1] POST /iot/networks (is_active=0) -> HTTP 201"
resp=$(api POST /iot/networks "$(cat <<EOF
{
  "name": "Approve-Test-Net-${RUN_ID}",
  "iot_ssid": "PKS-Approve-Test",
  "iot_password": "approve-test-pw-${RUN_ID}",
  "pi_local_ip": "192.168.99.1",
  "mqtt_port": 1883,
  "is_active": 0
}
EOF
)")
code=$(echo "$resp" | tail -n1); body=$(echo "$resp" | sed '$d')
assert "HTTP 201 (got $code)" "$([[ "$code" == "201" ]] && echo true || echo false)"
NET_ID=$(echo "$body" | json_field iot_networks_id)
assert "network id returned" "$([[ -n "$NET_ID" ]] && echo true || echo false)"
echo "  network_id = $NET_ID"
echo

# ---- [2] register device A (pending, bound to the active network) ----
echo "[2] POST /iot/register (fleet token) -> HTTP 201, pending"
resp=$(post_register "X-Provisioning-Token: ${IOT_FLEET_TOKEN}" "$(register_payload "$TEST_CHIP_A")")
code=$(echo "$resp" | tail -n1); body=$(echo "$resp" | sed '$d')
assert "HTTP 201 (got $code)" "$([[ "$code" == "201" ]] && echo true || echo false)"
DEV_A=$(echo "$body" | json_field device_id)
KEY_A=$(echo "$body" | json_field api_key)
assert "device id + api key returned" "$([[ -n "$DEV_A" && -n "$KEY_A" ]] && echo true || echo false)"
echo "  device_id = $DEV_A"
echo

# ---- [3] approve with unknown network -> 400, still pending ----
echo "[3] POST /iot/devices/$DEV_A/approve {network_id: 999999} -> HTTP 400"
resp=$(api POST "/iot/devices/$DEV_A/approve" '{"network_id": 999999}')
code=$(echo "$resp" | tail -n1)
assert "HTTP 400 (got $code)" "$([[ "$code" == "400" ]] && echo true || echo false)"
resp=$(api GET "/iot/devices/$DEV_A"); body=$(echo "$resp" | sed '$d')
status=$(echo "$body" | json_field provisioning_status)
assert "device still pending (got '$status')" "$([[ "$status" == "pending" ]] && echo true || echo false)"
echo

# ---- [4] approve with invalid value -> 400 ----
echo "[4] POST /iot/devices/$DEV_A/approve {network_id: \"abc\"} -> HTTP 400"
resp=$(api POST "/iot/devices/$DEV_A/approve" '{"network_id": "abc"}')
code=$(echo "$resp" | tail -n1)
assert "HTTP 400 (got $code)" "$([[ "$code" == "400" ]] && echo true || echo false)"
echo

# ---- [5] approve with the test network -> bound ----
echo "[5] POST /iot/devices/$DEV_A/approve {network_id: $NET_ID} -> HTTP 200, network_id echoed"
resp=$(api POST "/iot/devices/$DEV_A/approve" "{\"network_id\": $NET_ID}")
code=$(echo "$resp" | tail -n1); body=$(echo "$resp" | sed '$d')
assert "HTTP 200 (got $code)" "$([[ "$code" == "200" ]] && echo true || echo false)"
got=$(echo "$body" | json_field network_id)
assert "response network_id == $NET_ID (got '$got')" "$([[ "$got" == "$NET_ID" ]] && echo true || echo false)"
status=$(echo "$body" | json_field provisioning_status)
assert "provisioning_status approved" "$([[ "$status" == "approved" ]] && echo true || echo false)"
resp=$(api GET "/iot/devices/$DEV_A"); body=$(echo "$resp" | sed '$d')
got=$(echo "$body" | json_field networkId)
assert "GET device networkId == $NET_ID (got '$got')" "$([[ "$got" == "$NET_ID" ]] && echo true || echo false)"
echo

# ---- [6] heartbeat delivers the assigned network ----
echo "[6] POST /iot/heartbeat (device A) -> credentials of network $NET_ID"
resp=$(heartbeat "$KEY_A")
code=$(echo "$resp" | tail -n1); body=$(echo "$resp" | sed '$d')
assert "HTTP 200 (got $code)" "$([[ "$code" == "200" ]] && echo true || echo false)"
ssid=$(echo "$body" | json_nested network.iot_ssid)
assert "heartbeat iot_ssid == PKS-Approve-Test (got '$ssid')" "$([[ "$ssid" == "PKS-Approve-Test" ]] && echo true || echo false)"
ip=$(echo "$body" | json_nested network.pi_local_ip)
assert "heartbeat pi_local_ip == 192.168.99.1 (got '$ip')" "$([[ "$ip" == "192.168.99.1" ]] && echo true || echo false)"
echo

# ---- [7] re-registration keeps the assignment ----
echo "[7] POST /iot/register (X-Api-Key, re-register) -> network stays $NET_ID"
resp=$(post_register "X-Api-Key: ${KEY_A}" "$(register_payload "$TEST_CHIP_A")")
code=$(echo "$resp" | tail -n1); body=$(echo "$resp" | sed '$d')
assert "HTTP 200 (got $code)" "$([[ "$code" == "200" ]] && echo true || echo false)"
KEY_A2=$(echo "$body" | json_field api_key)
ssid=$(echo "$body" | json_nested network.iot_ssid)
assert "register response network is the assigned one (got '$ssid')" "$([[ "$ssid" == "PKS-Approve-Test" ]] && echo true || echo false)"
resp=$(api GET "/iot/devices/$DEV_A"); body=$(echo "$resp" | sed '$d')
got=$(echo "$body" | json_field networkId)
assert "GET device networkId still $NET_ID (got '$got')" "$([[ "$got" == "$NET_ID" ]] && echo true || echo false)"
echo

# ---- [8] approve without network_id keeps the registration network ----
echo "[8] device B: approve without body -> network unchanged (active network)"
resp=$(post_register "X-Provisioning-Token: ${IOT_FLEET_TOKEN}" "$(register_payload "$TEST_CHIP_B")")
body=$(echo "$resp" | sed '$d')
DEV_B=$(echo "$body" | json_field device_id)
resp=$(api GET "/iot/devices/$DEV_B"); body=$(echo "$resp" | sed '$d')
before=$(echo "$body" | json_field networkId)
resp=$(api POST "/iot/devices/$DEV_B/approve")
code=$(echo "$resp" | tail -n1); body=$(echo "$resp" | sed '$d')
assert "HTTP 200 (got $code)" "$([[ "$code" == "200" ]] && echo true || echo false)"
after=$(echo "$body" | json_field network_id)
assert "network_id unchanged ($before -> $after)" "$([[ -n "$before" && "$before" == "$after" ]] && echo true || echo false)"
assert "device B not on test network" "$([[ "$after" != "$NET_ID" ]] && echo true || echo false)"
echo

# ---- cleanup: test network can only go once device A is moved off it ----
echo "[cleanup] move device A back to network $before, delete test network"
api POST "/iot/devices/$DEV_A/approve" "{\"network_id\": $before}" >/dev/null
resp=$(api DELETE "/iot/networks/$NET_ID")
code=$(echo "$resp" | tail -n1)
assert "DELETE test network -> 200 (got $code)" "$([[ "$code" == "200" ]] && echo true || echo false)"
echo "  devices $DEV_A ($TEST_CHIP_A) and $DEV_B ($TEST_CHIP_B) remain; delete via DELETE /iot/devices/{id} if desired"
echo

# ---------- summary ----------
echo "=== Result: $PASS passed, $FAIL failed ==="
if [[ $FAIL -gt 0 ]]; then
    for t in "${FAILED_TESTS[@]}"; do echo "  - $t"; done
    exit 1
fi
exit 0
