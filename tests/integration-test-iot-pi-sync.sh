#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for POST /iot/pi-sync (STORY-2.4 / TASK-2.4.3).
#
# Requires a Pi-device API key (typ=pi) via env PI_API_KEY.
# Optional: ESP_API_KEY (typ=esp8266) for the 403-negative test.
#
# Usage:
#   PI_API_KEY=... [ESP_API_KEY=...] ./integration-test-iot-pi-sync.sh [BASE_URL]

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
PASS=0
FAIL=0
FAILED_TESTS=()

if [[ -z "$PI_API_KEY" ]]; then
    echo "ERROR: set PI_API_KEY env var (api_key of a typ=pi device)" >&2
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

post_sync() {
    local apikey="$1"
    local body="$2"
    local header_args=()
    if [[ -n "$apikey" ]]; then
        header_args=(-H "X-Api-Key: $apikey")
    fi
    curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/iot/pi-sync" \
        -H "Content-Type: application/json" \
        "${header_args[@]}" \
        -d "$body"
}

echo "=== Integration Test: POST /iot/pi-sync (STORY-2.4) ==="
echo "Base URL: $BASE_URL"
echo

# ---- Test 1: Missing X-Api-Key -> 401 ----
echo "[1] Missing X-Api-Key -> HTTP 401"
resp=$(post_sync "" '{"devices":[]}')
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 401 without api key" "$([[ "$http_code" == "401" ]] && echo true || echo false)"
echo

# ---- Test 2: Invalid api key -> 401 ----
echo "[2] Invalid X-Api-Key -> HTTP 401"
resp=$(post_sync "invalid-key-nonexistent" '{"devices":[]}')
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 401 with bogus key" "$([[ "$http_code" == "401" ]] && echo true || echo false)"
echo

# ---- Test 3: ESP key (typ!=pi) -> 403 ----
echo "[3] ESP key (typ!=pi) -> HTTP 403"
if [[ -n "$ESP_API_KEY" ]]; then
    resp=$(post_sync "$ESP_API_KEY" '{"devices":[]}')
    http_code=$(echo "$resp" | tail -n1)
    assert "HTTP 403 for non-pi key" "$([[ "$http_code" == "403" ]] && echo true || echo false)"
else
    echo "  [SKIP] ESP_API_KEY not set"
fi
echo

# ---- Test 4: Empty devices array -> 400 ----
echo "[4] Empty devices array -> HTTP 400"
resp=$(post_sync "$PI_API_KEY" '{"devices":[]}')
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 400 for empty devices" "$([[ "$http_code" == "400" ]] && echo true || echo false)"
echo

# ---- Test 5: Unknown chip_id -> 200 + warning ----
echo "[5] Unknown chip_id -> HTTP 200 + warning in response"
ts=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
payload=$(cat <<EOF
{
  "devices": [
    {"chip_id":"nonexistent-chip-xyz","data":[
      {"sensor_key":"temp","wert":23.0,"anzahl_messungen":1,"zeitstempel":"$ts"}
    ]}
  ]
}
EOF
)
resp=$(post_sync "$PI_API_KEY" "$payload")
http_code=$(echo "$resp" | tail -n1)
body=$(echo "$resp" | sed '$d')
assert "HTTP 200 (unknown chip is tolerated)" "$([[ "$http_code" == "200" ]] && echo true || echo false)"
assert "Response has 'warnings' with unknown chip_id" "$(echo "$body" | grep -q 'Unknown chip_id' && echo true || echo false)"
echo

# ---- Test 6: Missing sensor_key -> 400 ----
echo "[6] Data point missing sensor_key -> HTTP 400 (validation)"
payload=$(cat <<EOF
{
  "devices": [
    {"chip_id":"3cf8f4","data":[
      {"wert":50.0}
    ]}
  ]
}
EOF
)
resp=$(post_sync "$PI_API_KEY" "$payload")
http_code=$(echo "$resp" | tail -n1)
body=$(echo "$resp" | sed '$d')
assert "HTTP 400 for missing sensor_key" "$([[ "$http_code" == "400" ]] && echo true || echo false)"
assert "Error mentions sensor_key"        "$(echo "$body" | grep -q 'sensor_key' && echo true || echo false)"
echo

# ---- Test 7: Missing wert -> 400 ----
echo "[7] Data point missing wert -> HTTP 400 (validation)"
payload=$(cat <<EOF
{
  "devices": [
    {"chip_id":"3cf8f4","data":[
      {"sensor_key":"test"}
    ]}
  ]
}
EOF
)
resp=$(post_sync "$PI_API_KEY" "$payload")
http_code=$(echo "$resp" | tail -n1)
body=$(echo "$resp" | sed '$d')
assert "HTTP 400 for missing wert" "$([[ "$http_code" == "400" ]] && echo true || echo false)"
assert "Error mentions wert"        "$(echo "$body" | grep -q '\"wert\"\\|wert' && echo true || echo false)"
echo

# ---- Test 8: Valid full sync -> 200, row inserted, idempotent on retry ----
echo "[8] Valid full sync -> HTTP 200, 1 row inserted"
ts=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
payload=$(cat <<EOF
{
  "devices": [
    {"chip_id":"3cf8f4","data":[
      {"sensor_key":"test-pi-sync","wert":42.5,"min_wert":40.0,"max_wert":45.0,"avg_wert":42.1,"anzahl_messungen":10,"zeitstempel":"$ts"}
    ]}
  ]
}
EOF
)
resp=$(post_sync "$PI_API_KEY" "$payload")
http_code=$(echo "$resp" | tail -n1)
body=$(echo "$resp" | sed '$d')
assert "HTTP 200 on valid sync" "$([[ "$http_code" == "200" ]] && echo true || echo false)"
assert "data_points_inserted = 1" "$(echo "$body" | python -c "import sys,json; print(json.load(sys.stdin).get('data_points_inserted')==1)" | grep -q True && echo true || echo false)"

# Retry same payload -> should NOT create a duplicate (ON DUPLICATE KEY UPDATE)
resp=$(post_sync "$PI_API_KEY" "$payload")
http_code=$(echo "$resp" | tail -n1)
assert "HTTP 200 on retry" "$([[ "$http_code" == "200" ]] && echo true || echo false)"

# Count rows with this specific sensor_key+timestamp
count=$(curl -s "${BASE_URL}/iot/data/1?sensor_key=test-pi-sync&from=$(date -u -d '-5 minutes' +'%Y-%m-%d %H:%M:%S')" \
    | python -c "import sys,json; d=json.load(sys.stdin); print(sum(1 for r in d['data'] if r['zeitstempel'].startswith('${ts%T*}')))" 2>/dev/null || echo "0")
assert "No duplicate after retry (count=$count on test-pi-sync today)" "$([[ "$count" -le "1" ]] && echo true || echo false)"
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
