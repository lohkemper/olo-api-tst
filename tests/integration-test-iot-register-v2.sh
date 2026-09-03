#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for POST /iot/register with descriptor schema v2
# (EPIC-10 / STORY-10.3).
#
# Verifies against the live API:
#   - invalid v2 bodies are rejected with 400 (contract validation)
#   - the auth mechanics are UNCHANGED for v2: fleet token for first
#     registration, X-Api-Key for re-registration, 409 without it
#   - a changed descriptor on re-registration is accepted and rotates the key
#   - v1 bodies keep working (regression guard for the transition period)
#
# Auth required: IOT_ADMIN_EMAIL / IOT_ADMIN_PASSWORD (cleanup deletes the
# throw-away devices) and IOT_FLEET_TOKEN (first registrations need a valid
# X-Provisioning-Token).
#
# Usage:
#   IOT_ADMIN_EMAIL=admin@example.com IOT_ADMIN_PASSWORD=... IOT_FLEET_TOKEN=... \
#     ./integration-test-iot-register-v2.sh [BASE_URL]

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
TEST_CHIP_V2="testchip-10-3-v2"
TEST_CHIP_V1="testchip-10-3-v1"
PASS=0
FAIL=0
FAILED_TESTS=()
COOKIE_JAR=$(mktemp)
V2_DEVICE_ID=""
V1_DEVICE_ID=""

cleanup() {
    for id in "$V2_DEVICE_ID" "$V1_DEVICE_ID"; do
        if [[ -n "$id" ]]; then
            curl -s -o /dev/null -b "$COOKIE_JAR" \
                -X DELETE "${BASE_URL}/iot/devices/${id}" || true
        fi
    done
    rm -f "$COOKIE_JAR"
}
trap cleanup EXIT

if [[ -z "$IOT_ADMIN_EMAIL" || -z "$IOT_ADMIN_PASSWORD" || -z "$IOT_FLEET_TOKEN" ]]; then
    echo "ERROR: set IOT_ADMIN_EMAIL, IOT_ADMIN_PASSWORD and IOT_FLEET_TOKEN env vars" >&2
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
    local resp code
    resp=$(curl -s -w "\n%{http_code}" -c "$COOKIE_JAR" \
        -X POST "${BASE_URL}/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"$IOT_ADMIN_EMAIL\",\"password\":\"$IOT_ADMIN_PASSWORD\"}")
    code=$(echo "$resp" | tail -n1)
    if [[ "$code" != "200" ]]; then
        echo "ERROR: login failed (HTTP $code)" >&2
        exit 2
    fi
    echo "  [INFO] logged in as $IOT_ADMIN_EMAIL"
}

# post_register [extra curl args...] <payload>
post_register() {
    local payload="${!#}"
    local extra=("${@:1:$#-1}")
    curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/iot/register" \
        -H "Content-Type: application/json" "${extra[@]}" -d "$payload"
}

json_field() {
    local field="$1"
    python -c "import sys,json; print(json.load(sys.stdin).get('$field',''))"
}

# The reference v2 descriptor: pump with all five real endpoints, soil sensor
# with two values — the same example the contract and the concept carry.
v2_payload() {
    local chip="$1"
    local extra_sensor="${2:-}"
    cat <<EOF
{
  "schema": 2,
  "chip_id": "$chip",
  "name": "Testchip v2 (STORY-10.3)",
  "typ": "esp8266",
  "firmware_version": "2.0.0",
  "mqtt_base": "pks/test/v2chip",
  "sensoren": [
    {"id": "boden", "name": "Bodenfeuchtigkeit", "modell": "Kapazitiv v1.2",
     "values": {
       "bodenfeuchtigkeit": {"type": "number", "unit": "%", "intervall": 10},
       "raw": {"type": "number", "intervall": 10}
     }}${extra_sensor}
  ],
  "aktoren": [
    {"id": "pumpe", "name": "Wasserpumpe",
     "values": {
       "set": {"type": "number", "enum": [0, 64, 128, 192, 255]},
       "status": {"type": "number", "readonly": true, "retained": true},
       "auto": {"type": "string", "enum": ["an", "aus"], "retained": true},
       "speed": {"type": "number", "min": 0, "max": 255, "retained": true},
       "schwellwert": {"type": "number", "unit": "%", "readonly": true, "retained": true}
     }}
  ]
}
EOF
}

echo "=== Integration Test: POST /iot/register schema v2 (STORY-10.3) ==="
echo "Base URL: $BASE_URL"
do_login
echo

# ---- Test 1: invalid v2 bodies -> 400, before any auth question ----
echo "[1] Invalid v2 descriptors -> HTTP 400"

bad_base=$(v2_payload "$TEST_CHIP_V2" | python -c "import sys,json; d=json.load(sys.stdin); d['mqtt_base']='pks/test/v2chip/extra'; print(json.dumps(d))")
response=$(post_register -H "X-Provisioning-Token: ${IOT_FLEET_TOKEN}" "$bad_base")
http_code=$(echo "$response" | tail -n1)
assert "mqtt_base with 4 segments -> 400 (got $http_code)" "$([[ "$http_code" == "400" ]] && echo true || echo false)"

bad_schema=$(v2_payload "$TEST_CHIP_V2" | python -c "import sys,json; d=json.load(sys.stdin); d['schema']=3; print(json.dumps(d))")
response=$(post_register -H "X-Provisioning-Token: ${IOT_FLEET_TOKEN}" "$bad_schema")
http_code=$(echo "$response" | tail -n1)
assert "schema 3 -> 400 (got $http_code)" "$([[ "$http_code" == "400" ]] && echo true || echo false)"

bad_enum=$(v2_payload "$TEST_CHIP_V2" | python -c "import sys,json; d=json.load(sys.stdin); d['aktoren'][0]['values']['set']['enum']=['0','64']; print(json.dumps(d))")
response=$(post_register -H "X-Provisioning-Token: ${IOT_FLEET_TOKEN}" "$bad_enum")
http_code=$(echo "$response" | tail -n1)
assert "non-type-conform enum -> 400 (got $http_code)" "$([[ "$http_code" == "400" ]] && echo true || echo false)"
echo

# ---- Test 2: fresh v2 register without fleet token -> 401 ----
echo "[2] Fresh v2 register without X-Provisioning-Token -> HTTP 401"
response=$(post_register "$(v2_payload "$TEST_CHIP_V2")")
http_code=$(echo "$response" | tail -n1)
assert "HTTP 401 (got $http_code)" "$([[ "$http_code" == "401" ]] && echo true || echo false)"
echo

# ---- Test 3: fresh v2 register with fleet token -> 201 pending ----
echo "[3] Fresh v2 register with fleet token -> HTTP 201, pending, no network"
response=$(post_register -H "X-Provisioning-Token: ${IOT_FLEET_TOKEN}" "$(v2_payload "$TEST_CHIP_V2")")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | sed '$d')
assert "HTTP 201 (got $http_code)" "$([[ "$http_code" == "201" ]] && echo true || echo false)"
api_key=$(echo "$body" | json_field api_key)
assert "api_key is 64 hex chars" "$(echo "$api_key" | grep -qE '^[0-9a-f]{64}$' && echo true || echo false)"
assert "status is pending" "$([[ "$(echo "$body" | json_field status)" == "pending" ]] && echo true || echo false)"
assert "no network credentials in response (L2)" "$(echo "$body" | grep -q '"iot_ssid"' && echo false || echo true)"
V2_DEVICE_ID=$(echo "$body" | json_field device_id)
echo "  device_id = $V2_DEVICE_ID"
echo

# ---- Test 4: v2 re-register without api key -> 409 ----
echo "[4] v2 re-register without X-Api-Key -> HTTP 409"
response=$(post_register "$(v2_payload "$TEST_CHIP_V2")")
http_code=$(echo "$response" | tail -n1)
assert "HTTP 409 (got $http_code)" "$([[ "$http_code" == "409" ]] && echo true || echo false)"
echo

# ---- Test 5: v2 re-register with changed descriptor -> 200, key rotates ----
echo "[5] v2 re-register with api key and a CHANGED descriptor -> HTTP 200"
extra=',
    {"id": "licht", "name": "Lichtsensor",
     "values": {"lux": {"type": "number", "unit": "lx", "intervall": 60}}}'
response=$(post_register -H "X-Api-Key: ${api_key}" "$(v2_payload "$TEST_CHIP_V2" "$extra")")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | sed '$d')
assert "HTTP 200 (got $http_code)" "$([[ "$http_code" == "200" ]] && echo true || echo false)"
new_key=$(echo "$body" | json_field api_key)
assert "api_key rotated" "$([[ -n "$new_key" && "$new_key" != "$api_key" ]] && echo true || echo false)"
assert "status still pending (approval untouched)" "$([[ "$(echo "$body" | json_field status)" == "pending" ]] && echo true || echo false)"
echo

# ---- Test 6: v1 body keeps working (transition period) ----
echo "[6] v1 register (no schema field) -> unchanged behaviour"
v1_payload=$(cat <<EOF
{
  "chip_id": "$TEST_CHIP_V1",
  "name": "Testchip v1 (STORY-10.3)",
  "typ": "esp8266",
  "firmware_version": "1.0.0",
  "sensoren": [
    {"id": "temp", "typ": "temperature", "einheit": "C", "modell": "DS18B20",
     "intervall_sekunden": 30, "mqtt_topic": "pks/test/v1chip/temp"}
  ]
}
EOF
)
response=$(post_register -H "X-Provisioning-Token: ${IOT_FLEET_TOKEN}" "$v1_payload")
http_code=$(echo "$response" | tail -n1)
body=$(echo "$response" | sed '$d')
assert "HTTP 201 (got $http_code)" "$([[ "$http_code" == "201" ]] && echo true || echo false)"
V1_DEVICE_ID=$(echo "$body" | json_field device_id)
echo

# ---------- summary ----------
echo "=== Result: $PASS passed, $FAIL failed ==="
if [[ $FAIL -gt 0 ]]; then
    printf '  failed: %s\n' "${FAILED_TESTS[@]}"
    exit 1
fi
exit 0
