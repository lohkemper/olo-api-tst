#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for GET /iot/device-keys (STORY-1.11 / TASK-1.11.1).
#
# The Pi-Zentrale reads this endpoint to keep its local chip_id_map, which is
# what lets it attribute MQTT readings to the right device. STORY-1.8 relied
# on GET /iot/devices for that; the field it needed was never deployed, the
# response later moved to camelCase and the endpoint was put behind session
# auth — so the mapping silently stopped updating for three months. This test
# exists so that cannot repeat unnoticed.
#
# Runs against the LIVE server by default. That is deliberate: the defect this
# guards against was "works locally, never deployed".
#
# Required in env:
#   PI_API_KEY   — X-Api-Key of a device with typ='pi'
# Optional:
#   ESP_API_KEY  — X-Api-Key of an esp32/esp8266 device; enables the 403 case
#
# Usage:
#   PI_API_KEY=... [ESP_API_KEY=...] ./integration-test-iot-device-keys.sh [BASE_URL]

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
PASS=0
FAIL=0
FAILED_TESTS=()

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

status_of() {
    curl -s -o /dev/null -w "%{http_code}" "$@"
}

echo "=== Integration Test: GET /iot/device-keys (STORY-1.11) ==="
echo "Base URL: $BASE_URL"
echo

if [[ -z "$PI_API_KEY" ]]; then
    echo "ERROR: PI_API_KEY is required" >&2
    exit 2
fi

# ---- 1. No credentials -> 401 ----
echo "[1] without any credentials"
code=$(status_of "${BASE_URL}/iot/device-keys")
assert "401 without X-Api-Key (got $code)" "$([[ "$code" == "401" ]] && echo true || echo false)"

# ---- 2. Invalid key -> 401 ----
echo "[2] with an invalid key"
code=$(status_of -H "X-Api-Key: definitely-not-a-real-key" "${BASE_URL}/iot/device-keys")
assert "401 with an unknown key (got $code)" "$([[ "$code" == "401" ]] && echo true || echo false)"

# ---- 3. ESP key -> 403 (endpoint is pi-only) ----
if [[ -n "$ESP_API_KEY" ]]; then
    echo "[3] with an ESP key"
    code=$(status_of -H "X-Api-Key: ${ESP_API_KEY}" "${BASE_URL}/iot/device-keys")
    assert "403 for a non-pi device (got $code)" "$([[ "$code" == "403" ]] && echo true || echo false)"
else
    echo "[3] skipped — ESP_API_KEY not set"
fi

# ---- 4. Pi key -> 200 with usable pairs ----
echo "[4] with the Pi key"
body=$(curl -s -H "X-Api-Key: ${PI_API_KEY}" "${BASE_URL}/iot/device-keys")
code=$(status_of -H "X-Api-Key: ${PI_API_KEY}" "${BASE_URL}/iot/device-keys")
assert "200 for a pi device (got $code)" "$([[ "$code" == "200" ]] && echo true || echo false)"

# The field names are the actual regression: the Pi parses chipId/deviceKey.
echo "$body" | grep -q '"chipId"' \
    && assert "response uses chipId" "true" \
    || assert "response uses chipId (body: ${body:0:120})" "false"

echo "$body" | grep -q '"deviceKey"' \
    && assert "response uses deviceKey" "true" \
    || assert "response uses deviceKey (body: ${body:0:120})" "false"

# A deviceKey must look like "projekt/bereich" — the shape the Pi's
# chip_id_map is keyed on.
echo "$body" | grep -qE '"deviceKey":"[^"/]+/[^"/]+"' \
    && assert "deviceKey has the projekt/bereich shape" "true" \
    || assert "deviceKey has the projekt/bereich shape (body: ${body:0:120})" "false"

# Empty is a valid response only if no device has sensors or actors — on the
# live fleet that would mean the derivation broke.
count=$(echo "$body" | grep -o '"chipId"' | wc -l)
assert "at least one mapping returned (got $count)" "$([[ "$count" -ge 1 ]] && echo true || echo false)"

# The narrow endpoint must not leak the full inventory.
for leaked in networkName piLocalIp firmwareVersion lastHeartbeat; do
    echo "$body" | grep -q "\"$leaked\"" \
        && assert "does not expose $leaked" "false" \
        || assert "does not expose $leaked" "true"
done

echo
echo "=== Result: $PASS passed, $FAIL failed ==="
if [[ "$FAIL" -gt 0 ]]; then
    printf '  - %s\n' "${FAILED_TESTS[@]}"
    exit 1
fi
exit 0
