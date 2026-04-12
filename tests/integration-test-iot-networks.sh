#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for /iot/networks CRUD endpoints (STORY-2.3 / TASK-2.3.3).
# Verifies PUT /iot/networks/{id} and DELETE /iot/networks/{id} behavior.
#
# Auth required: set IOT_ADMIN_EMAIL and IOT_ADMIN_PASSWORD in env before running.
#
# Usage:
#   IOT_ADMIN_EMAIL=admin@example.com IOT_ADMIN_PASSWORD=... \
#     ./integration-test-iot-networks.sh [BASE_URL]
#
# Default BASE_URL: https://oliverlohkemper.de/rest2

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
PASS=0
FAIL=0
FAILED_TESTS=()
COOKIE_JAR=$(mktemp)
trap 'rm -f "$COOKIE_JAR"' EXIT

if [[ -z "$IOT_ADMIN_EMAIL" || -z "$IOT_ADMIN_PASSWORD" ]]; then
    echo "ERROR: set IOT_ADMIN_EMAIL and IOT_ADMIN_PASSWORD env vars" >&2
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

# Login and persist session cookie
do_login() {
    local resp
    resp=$(curl -s -w "\n%{http_code}" -c "$COOKIE_JAR" \
        -X POST "${BASE_URL}/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"$IOT_ADMIN_EMAIL\",\"password\":\"$IOT_ADMIN_PASSWORD\"}")
    local code
    code=$(echo "$resp" | tail -n1)
    if [[ "$code" != "200" ]]; then
        echo "ERROR: login failed (HTTP $code)" >&2
        echo "$resp" | sed '$d' >&2
        exit 2
    fi
    echo "  [INFO] logged in as $IOT_ADMIN_EMAIL"
}

api() {
    local method="$1"
    local path="$2"
    local body="${3:-}"
    if [[ -n "$body" ]]; then
        curl -s -w "\n%{http_code}" -b "$COOKIE_JAR" \
            -X "$method" "${BASE_URL}${path}" \
            -H "Content-Type: application/json" -d "$body"
    else
        curl -s -w "\n%{http_code}" -b "$COOKIE_JAR" \
            -X "$method" "${BASE_URL}${path}"
    fi
}

# ---------- tests ----------

echo "=== Integration Test: /iot/networks CRUD (STORY-2.3 / TASK-2.3.3) ==="
echo "Base URL: $BASE_URL"
do_login
echo

# ---- Test 1: POST creates a fresh test network ----
echo "[1] POST /iot/networks -> HTTP 201, returns id"
payload=$(cat <<EOF
{
  "name": "Test-Network-2-3-3",
  "iot_ssid": "PKS-Test-IoT",
  "iot_password": "test-pw-1234567890",
  "inet_ssid": "Test-Inet",
  "inet_password": "test-inet-pw",
  "pi_local_ip": "192.168.99.1",
  "mqtt_port": 1883,
  "is_active": 0
}
EOF
)
resp=$(api POST /iot/networks "$payload")
http_code=$(echo "$resp" | tail -n1)
body=$(echo "$resp" | sed '$d')
assert "HTTP 201 returned (got $http_code)" "$([[ "$http_code" == "201" ]] && echo true || echo false)"

network_id=$(echo "$body" | python -c "import sys,json; print(json.load(sys.stdin).get('iot_networks_id',''))")
assert "Response contains iot_networks_id" "$([[ -n "$network_id" ]] && echo true || echo false)"
echo "  network_id = $network_id"
echo

# ---- Test 2: GET reads back the values ----
echo "[2] GET /iot/networks/$network_id -> values match"
resp=$(api GET "/iot/networks/$network_id")
body=$(echo "$resp" | sed '$d')
assert "name = Test-Network-2-3-3" "$(echo "$body" | python -c "import sys,json; print(json.load(sys.stdin).get('name')=='Test-Network-2-3-3')" | grep -q True && echo true || echo false)"
assert "pi_local_ip = 192.168.99.1"  "$(echo "$body" | python -c "import sys,json; print(json.load(sys.stdin).get('pi_local_ip')=='192.168.99.1')" | grep -q True && echo true || echo false)"
echo

# ---- Test 3: PUT partial update ----
echo "[3] PUT /iot/networks/$network_id -> HTTP 200, fields updated"
resp=$(api PUT "/iot/networks/$network_id" '{"pi_local_ip":"192.168.99.42","mqtt_port":1884}')
http_code=$(echo "$resp" | tail -n1)
body=$(echo "$resp" | sed '$d')
assert "HTTP 200" "$([[ "$http_code" == "200" ]] && echo true || echo false)"
assert "pi_local_ip updated to 192.168.99.42" "$(echo "$body" | python -c "import sys,json; print(json.load(sys.stdin).get('pi_local_ip')=='192.168.99.42')" | grep -q True && echo true || echo false)"
assert "mqtt_port updated to 1884"             "$(echo "$body" | python -c "import sys,json; print(int(json.load(sys.stdin).get('mqtt_port',0))==1884)" | grep -q True && echo true || echo false)"
assert "name unchanged"                        "$(echo "$body" | python -c "import sys,json; print(json.load(sys.stdin).get('name')=='Test-Network-2-3-3')" | grep -q True && echo true || echo false)"
echo

# ---- Test 4: PUT unknown id -> 404 ----
echo "[4] PUT /iot/networks/999999 -> HTTP 404"
resp=$(api PUT "/iot/networks/999999" '{"name":"x"}')
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 404 for unknown id" "$([[ "$http_code" == "404" ]] && echo true || echo false)"
echo

# ---- Test 5: PUT is_active=1 deactivates others ----
echo "[5] PUT is_active=1 -> all other networks deactivated"
resp=$(api PUT "/iot/networks/$network_id" '{"is_active":1}')
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 200" "$([[ "$http_code" == "200" ]] && echo true || echo false)"

all=$(api GET /iot/networks | sed '$d')
active_count=$(echo "$all" | python -c "import sys,json; print(sum(1 for n in json.load(sys.stdin) if int(n['is_active'])==1))")
assert "exactly 1 active network in DB (got $active_count)" "$([[ "$active_count" == "1" ]] && echo true || echo false)"

# Restore: re-activate the original Hauptnetz so live device keeps working
hauptnetz_id=$(echo "$all" | python -c "import sys,json; d=[n for n in json.load(sys.stdin) if n['name']=='PKS Hauptnetz']; print(d[0]['iot_networks_id'] if d else '')")
if [[ -n "$hauptnetz_id" ]]; then
    api PUT "/iot/networks/$hauptnetz_id" '{"is_active":1}' >/dev/null
    echo "  [INFO] restored is_active=1 on PKS Hauptnetz (id=$hauptnetz_id)"
fi
echo

# ---- Test 6: DELETE network with no devices -> 204 ----
echo "[6] DELETE /iot/networks/$network_id -> HTTP 204 (no devices reference it)"
resp=$(api DELETE "/iot/networks/$network_id")
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 204 on successful delete" "$([[ "$http_code" == "204" ]] && echo true || echo false)"

resp=$(api GET "/iot/networks/$network_id")
http_code=$(echo "$resp" | tail -n1)
assert "GET after DELETE returns 404" "$([[ "$http_code" == "404" ]] && echo true || echo false)"
echo

# ---- Test 7: DELETE network with devices -> 409 ----
echo "[7] DELETE /iot/networks/{Hauptnetz} -> HTTP 409 (devices still reference it)"
if [[ -n "$hauptnetz_id" ]]; then
    resp=$(api DELETE "/iot/networks/$hauptnetz_id")
    http_code=$(echo "$resp" | tail -n1)
    body=$(echo "$resp" | sed '$d')
    assert "HTTP 409 when devices reference network" "$([[ "$http_code" == "409" ]] && echo true || echo false)"
    assert "Response contains device_count" "$(echo "$body" | grep -q 'device_count' && echo true || echo false)"
else
    echo "  [SKIP] no PKS Hauptnetz found"
fi
echo

# ---- Test 8: DELETE unknown id -> 404 ----
echo "[8] DELETE /iot/networks/999999 -> HTTP 404"
resp=$(api DELETE "/iot/networks/999999")
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 404 for unknown id" "$([[ "$http_code" == "404" ]] && echo true || echo false)"
echo

# ---- Summary ----
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
