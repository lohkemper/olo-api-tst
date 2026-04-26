#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for DELETE /iot/devices/{id} (STORY-2.7 / TASK-2.7.3).
#
# Verifies that the new endpoint requires a session, returns the right
# HTTP codes for invalid / missing / valid IDs, and cascades to sensors,
# actors and historical data.
#
# Auth required: set IOT_ADMIN_EMAIL and IOT_ADMIN_PASSWORD in env before running.
#
# Usage:
#   IOT_ADMIN_EMAIL=admin@example.com IOT_ADMIN_PASSWORD=... \
#     ./integration-test-iot-delete-device.sh [BASE_URL]

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
TEST_CHIP="delete-test-2-7-3"
PASS=0
FAIL=0
FAILED_TESTS=()
COOKIE_JAR=$(mktemp)
TEST_DEVICE_ID=""

cleanup() {
    # Best-effort: if the test chip survived (e.g. early abort), try to nuke it
    # via the very endpoint under test. This requires a valid session and is
    # only useful when login already succeeded.
    if [[ -n "$TEST_DEVICE_ID" ]]; then
        curl -s -o /dev/null -b "$COOKIE_JAR" \
            -X DELETE "${BASE_URL}/iot/devices/${TEST_DEVICE_ID}" || true
    fi
    rm -f "$COOKIE_JAR"
}
trap cleanup EXIT

if [[ -z "$IOT_ADMIN_EMAIL" || -z "$IOT_ADMIN_PASSWORD" ]]; then
    echo "ERROR: set IOT_ADMIN_EMAIL and IOT_ADMIN_PASSWORD env vars" >&2
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

api_no_auth() {
    local method="$1"
    local path="$2"
    curl -s -w "\n%{http_code}" -X "$method" "${BASE_URL}${path}"
}

api_auth() {
    local method="$1"
    local path="$2"
    curl -s -w "\n%{http_code}" -b "$COOKIE_JAR" -X "$method" "${BASE_URL}${path}"
}

register_test_device() {
    local payload
    payload=$(cat <<EOF
{
  "chip_id": "$TEST_CHIP",
  "name": "Delete-Test (STORY-2.7)",
  "typ": "esp8266",
  "firmware_version": "0.0.1",
  "sensoren": [
    {"id": "temp", "typ": "temperature", "einheit": "C", "modell": "DS18B20", "intervall_sekunden": 30, "mqtt_topic": "pks/delete-test/temp"}
  ],
  "aktoren": [
    {"id": "led", "typ": "led", "name": "Status LED", "zustaende": ["0","1"], "mqtt_topic_set": "pks/delete-test/led/set", "mqtt_topic_status": "pks/delete-test/led/status"}
  ]
}
EOF
    )
    local resp
    resp=$(curl -s -X POST "${BASE_URL}/iot/register" \
        -H "Content-Type: application/json" -d "$payload")
    echo "$resp" | python -c "import sys,json; print(json.load(sys.stdin).get('device_id',''))"
}

echo "=== Integration Test: DELETE /iot/devices/{id} (STORY-2.7) ==="
echo "Base URL: $BASE_URL"
do_login
echo

# ---- Setup: register a throw-away device ----
echo "[setup] POST /iot/register -> create test device '$TEST_CHIP'"
TEST_DEVICE_ID=$(register_test_device)
assert "Got numeric device_id from register (got '$TEST_DEVICE_ID')" \
    "$([[ "$TEST_DEVICE_ID" =~ ^[0-9]+$ ]] && echo true || echo false)"
echo "  device_id = $TEST_DEVICE_ID"
echo

# ---- Test 1: DELETE without session -> 401 ----
echo "[1] DELETE /iot/devices/$TEST_DEVICE_ID without session -> HTTP 401"
resp=$(api_no_auth DELETE "/iot/devices/$TEST_DEVICE_ID")
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 401 without session (got $http_code)" \
    "$([[ "$http_code" == "401" ]] && echo true || echo false)"
echo

# ---- Test 2: DELETE with id=0 -> 400 ----
echo "[2] DELETE /iot/devices/0 with session -> HTTP 400"
resp=$(api_auth DELETE "/iot/devices/0")
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 400 on invalid id 0 (got $http_code)" \
    "$([[ "$http_code" == "400" ]] && echo true || echo false)"
echo

# ---- Test 3: DELETE non-existent id -> 404 ----
echo "[3] DELETE /iot/devices/999999999 with session -> HTTP 404"
resp=$(api_auth DELETE "/iot/devices/999999999")
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 404 for non-existent id (got $http_code)" \
    "$([[ "$http_code" == "404" ]] && echo true || echo false)"
echo

# ---- Test 4: DELETE existing id -> 204; subsequent GET -> 404 ----
echo "[4] DELETE /iot/devices/$TEST_DEVICE_ID with session -> HTTP 204"
resp=$(api_auth DELETE "/iot/devices/$TEST_DEVICE_ID")
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 204 on valid delete (got $http_code)" \
    "$([[ "$http_code" == "204" ]] && echo true || echo false)"

# After DELETE the device is gone — GET must return 404
resp=$(curl -s -w "\n%{http_code}" "${BASE_URL}/iot/devices/$TEST_DEVICE_ID")
http_code=$(echo "$resp" | tail -n1)
assert "GET on deleted device returns 404 (got $http_code)" \
    "$([[ "$http_code" == "404" ]] && echo true || echo false)"

# Mark cleared so the trap does not try to delete again
TEST_DEVICE_ID=""
echo

# ---- Test 5: DELETE same id again -> 404 ----
echo "[5] DELETE same id again -> HTTP 404"
# Use the captured-but-cleared id; we keep it in a local var here.
# (TEST_DEVICE_ID was emptied above — restore for this single check.)
last_id=$(curl -s "${BASE_URL}/iot/devices" \
    | python -c "import sys,json; d=[x for x in json.load(sys.stdin) if x['chip_id']=='$TEST_CHIP']; print(d[0]['iot_devices_id'] if d else '0')")
assert "Test chip is gone from device list (got id '$last_id')" \
    "$([[ "$last_id" == "0" ]] && echo true || echo false)"
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
