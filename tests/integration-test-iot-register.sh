#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for POST /iot/register
# Verifies all STORY-2.2 acceptance criteria against the live API.
#
# Uses a throwaway test chip_id so real devices are not touched.
# Idempotent: safe to re-run — cleans up via a final test-chip re-register
# to a minimal state, but does not delete rows (no DELETE endpoint exposed).
#
# Usage:
#   ./integration-test-iot-register.sh [BASE_URL]
#
# Default BASE_URL: https://oliverlohkemper.de/rest2

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
TEST_CHIP="testchip-2-2-6"
PASS=0
FAIL=0
FAILED_TESTS=()

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

post_register() {
    local payload="$1"
    curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/iot/register" \
        -H "Content-Type: application/json" \
        -d "$payload"
}

# Returns the numeric device id for a given chip_id, or empty if not found.
get_device_id() {
    local chip="$1"
    curl -s "${BASE_URL}/iot/devices" \
        | python -c "import sys,json; d=[x for x in json.load(sys.stdin) if x['chip_id']=='$chip']; print(d[0]['iot_devices_id'] if d else '')"
}

# ---------- tests ----------

echo "=== Integration Test: POST /iot/register (STORY-2.2 / TASK-2.2.6) ==="
echo "Base URL: $BASE_URL"
echo "Test chip: $TEST_CHIP"
echo

# ---- Test 1: Fresh register returns HTTP 201 ----
echo "[1] Fresh register (new chip) -> HTTP 201"

# If the test chip already exists from a previous run, the first call here
# will be a re-register (200). We detect that and note it — the subsequent
# tests still validate the full contract.
existing_id=$(get_device_id "$TEST_CHIP")
expected_fresh_code="201"
if [[ -n "$existing_id" ]]; then
    echo "  [INFO] Test chip already registered (id=$existing_id). Expecting 200 on first call."
    expected_fresh_code="200"
fi

payload_fresh=$(cat <<EOF
{
  "chip_id": "$TEST_CHIP",
  "name": "Testchip v1",
  "typ": "esp8266",
  "firmware_version": "0.0.1",
  "sensoren": [
    {"id": "temp", "typ": "temperature", "einheit": "C", "modell": "DS18B20", "intervall_sekunden": 30, "mqtt_topic": "pks/test/temp"}
  ],
  "aktoren": [
    {"id": "led", "typ": "led", "name": "Status LED", "zustaende": ["0","1"], "mqtt_topic_set": "pks/test/led/set", "mqtt_topic_status": "pks/test/led/status"}
  ]
}
EOF
)
response=$(post_register "$payload_fresh")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | sed '$d')

assert "HTTP $expected_fresh_code returned (got $http_code)" "$([[ "$http_code" == "$expected_fresh_code" ]] && echo true || echo false)"
assert "Response contains device_id"  "$(echo "$body" | grep -q '"device_id"' && echo true || echo false)"
assert "Response contains api_key (64 hex chars)" "$(echo "$body" | python -c "import sys,json,re; d=json.load(sys.stdin); print(bool(re.fullmatch(r'[0-9a-f]{64}', d.get('api_key',''))))" | grep -q True && echo true || echo false)"
assert "Response contains network.iot_ssid"  "$(echo "$body" | grep -q '"iot_ssid"' && echo true || echo false)"
assert "Response contains network.mqtt_port" "$(echo "$body" | grep -q '"mqtt_port"' && echo true || echo false)"

device_id=$(echo "$body" | python -c "import sys,json; print(json.load(sys.stdin)['device_id'])")
echo "  device_id = $device_id"
echo

# ---- Test 2: GET /iot/devices/{id} returns full structure ----
echo "[2] GET /iot/devices/$device_id -> device + sensoren + aktoren"
detail=$(curl -s "${BASE_URL}/iot/devices/$device_id")

assert "chip_id matches"       "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['chip_id']=='$TEST_CHIP')" | grep -q True && echo true || echo false)"
assert "typ = esp8266"         "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['typ']=='esp8266')" | grep -q True && echo true || echo false)"
assert "online_status = online" "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['online_status']=='online')" | grep -q True && echo true || echo false)"
assert "sensoren[0].sensor_key = temp" "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['sensoren'][0]['sensor_key']=='temp')" | grep -q True && echo true || echo false)"
assert "sensoren[0].einheit = C" "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['sensoren'][0]['einheit']=='C')" | grep -q True && echo true || echo false)"
assert "sensoren[0].mqtt_topic = pks/test/temp" "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['sensoren'][0]['mqtt_topic']=='pks/test/temp')" | grep -q True && echo true || echo false)"
assert "aktoren[0].aktor_key = led" "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['aktoren'][0]['aktor_key']=='led')" | grep -q True && echo true || echo false)"
assert "aktoren[0].mqtt_topic_set = pks/test/led/set" "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['aktoren'][0]['mqtt_topic_set']=='pks/test/led/set')" | grep -q True && echo true || echo false)"
assert "aktoren[0].mqtt_topic_status = pks/test/led/status" "$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['aktoren'][0]['mqtt_topic_status']=='pks/test/led/status')" | grep -q True && echo true || echo false)"
echo

# ---- Test 3: Re-register returns HTTP 200, updates firmware, no duplicates ----
echo "[3] Re-register with firmware bump -> HTTP 200, UPDATE instead of INSERT"

sensoren_id_before=$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin)['sensoren'][0]['iot_sensoren_id'])")

payload_rereg=$(cat <<EOF
{
  "chip_id": "$TEST_CHIP",
  "name": "Testchip v2",
  "typ": "esp8266",
  "firmware_version": "0.0.2",
  "sensoren": [
    {"id": "temp", "typ": "temperature", "einheit": "C", "modell": "DS18B20", "intervall_sekunden": 30, "mqtt_topic": "pks/test/temp"}
  ],
  "aktoren": [
    {"id": "led", "typ": "led", "name": "Status LED", "zustaende": ["0","1"], "mqtt_topic_set": "pks/test/led/set", "mqtt_topic_status": "pks/test/led/status"}
  ]
}
EOF
)
response=$(post_register "$payload_rereg")
http_code=$(echo "$response" | tail -n1)

assert "HTTP 200 (re-register)" "$([[ "$http_code" == "200" ]] && echo true || echo false)"

detail2=$(curl -s "${BASE_URL}/iot/devices/$device_id")
assert "firmware_version updated to 0.0.2" "$(echo "$detail2" | python -c "import sys,json; print(json.load(sys.stdin)['firmware_version']=='0.0.2')" | grep -q True && echo true || echo false)"
assert "name updated to 'Testchip v2'"     "$(echo "$detail2" | python -c "import sys,json; print(json.load(sys.stdin)['name']=='Testchip v2')" | grep -q True && echo true || echo false)"

sensor_count=$(echo "$detail2" | python -c "import sys,json; print(len(json.load(sys.stdin)['sensoren']))")
aktor_count=$(echo "$detail2" | python -c "import sys,json; print(len(json.load(sys.stdin)['aktoren']))")
assert "exactly 1 sensor row (no duplicate)" "$([[ "$sensor_count" == "1" ]] && echo true || echo false)"
assert "exactly 1 aktor row (no duplicate)"  "$([[ "$aktor_count" == "1" ]] && echo true || echo false)"

sensoren_id_after=$(echo "$detail2" | python -c "import sys,json; print(json.load(sys.stdin)['sensoren'][0]['iot_sensoren_id'])")
assert "sensor row replaced (id changed: $sensoren_id_before -> $sensoren_id_after)" "$([[ "$sensoren_id_after" != "$sensoren_id_before" ]] && echo true || echo false)"
echo

# ---- Test 4: Incomplete sensor -> HTTP 400 ----
echo "[4] Incomplete sensor (missing einheit) -> HTTP 400"
payload_bad_sensor=$(cat <<EOF
{
  "chip_id": "$TEST_CHIP",
  "name": "Testchip v3",
  "typ": "esp8266",
  "firmware_version": "0.0.3",
  "sensoren": [
    {"id": "broken", "typ": "temperature"}
  ]
}
EOF
)
response=$(post_register "$payload_bad_sensor")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | sed '$d')
assert "HTTP 400 for incomplete sensor" "$([[ "$http_code" == "400" ]] && echo true || echo false)"
assert "Error message mentions 'einheit'" "$(echo "$body" | grep -q 'einheit' && echo true || echo false)"
echo

# ---- Test 5: Incomplete aktor -> HTTP 400 ----
echo "[5] Incomplete aktor (missing name) -> HTTP 400"
payload_bad_aktor=$(cat <<EOF
{
  "chip_id": "$TEST_CHIP",
  "name": "Testchip v4",
  "typ": "esp8266",
  "aktoren": [
    {"id": "broken", "typ": "led"}
  ]
}
EOF
)
response=$(post_register "$payload_bad_aktor")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | sed '$d')
assert "HTTP 400 for incomplete aktor" "$([[ "$http_code" == "400" ]] && echo true || echo false)"
assert "Error message mentions 'name'" "$(echo "$body" | grep -q 'name' && echo true || echo false)"
echo

# ---- Test 6: Invalid typ -> HTTP 400 ----
echo "[6] Invalid device typ -> HTTP 400"
payload_bad_typ=$(cat <<EOF
{"chip_id": "$TEST_CHIP", "name": "x", "typ": "arduino"}
EOF
)
response=$(post_register "$payload_bad_typ")
http_code=$(echo "$response" | tail -n1)
assert "HTTP 400 for invalid typ" "$([[ "$http_code" == "400" ]] && echo true || echo false)"
echo

# ---- Test 7: Client-supplied network_id is ignored ----
echo "[7] Client network_id=999 is ignored -> uses active network"
payload_bad_net=$(cat <<EOF
{
  "chip_id": "$TEST_CHIP",
  "name": "Testchip v5",
  "typ": "esp8266",
  "firmware_version": "0.0.5",
  "network_id": 999,
  "sensoren": [
    {"id": "temp", "typ": "temperature", "einheit": "C", "modell": "DS18B20", "intervall_sekunden": 30, "mqtt_topic": "pks/test/temp"}
  ]
}
EOF
)
response=$(post_register "$payload_bad_net")
http_code=$(echo "$response" | tail -n1)
assert "Register succeeds (HTTP 200) despite bogus network_id" "$([[ "$http_code" == "200" ]] && echo true || echo false)"

detail3=$(curl -s "${BASE_URL}/iot/devices/$device_id")
assert "network_name is still 'PKS Hauptnetz' (active net, not 999)" "$(echo "$detail3" | python -c "import sys,json; print(json.load(sys.stdin)['network_name']=='PKS Hauptnetz')" | grep -q True && echo true || echo false)"
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
