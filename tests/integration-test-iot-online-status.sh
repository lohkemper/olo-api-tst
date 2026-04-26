#!/usr/bin/env bash
# SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
#
# Integration test for computed online_status on GET /iot/devices (STORY-2.6 / TASK-2.6.1).
#
# Verifies that the API response derives online_status from last_heartbeat
# instead of returning the stale DB column.
#
# Requires:
#   ESP_API_KEY  — api_key of any registered esp8266/esp32 device (used to send heartbeats)
#
# Usage:
#   ESP_API_KEY=... ./integration-test-iot-online-status.sh [BASE_URL]

set -o pipefail

BASE_URL="${1:-https://oliverlohkemper.de/rest2}"
PASS=0
FAIL=0
FAILED_TESTS=()

if [[ -z "$ESP_API_KEY" ]]; then
    echo "ERROR: set ESP_API_KEY env var (api_key of any registered esp device)" >&2
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

echo "=== Integration Test: computed online_status (STORY-2.6) ==="
echo "Base URL: $BASE_URL"
echo

# ---- Test 1: GET /iot/devices returns valid online_status for every device ----
echo "[1] All devices have valid online_status in {online, offline, unbekannt}"
list=$(curl -s "${BASE_URL}/iot/devices")
invalid=$(echo "$list" | python -c "
import sys, json
allowed = {'online', 'offline', 'unbekannt'}
devices = json.load(sys.stdin)
bad = [d for d in devices if d.get('online_status') not in allowed]
print(len(bad))
")
assert "Every device has online_status in allowed set (bad=$invalid)" \
    "$([[ "$invalid" == "0" ]] && echo true || echo false)"
echo

# ---- Test 2: Heartbeat -> device shows as 'online' ----
echo "[2] Send heartbeat -> GET /iot/devices/{id} returns online_status='online'"
hb=$(curl -s -w "\n%{http_code}" -X POST "${BASE_URL}/iot/heartbeat" \
    -H "X-Api-Key: $ESP_API_KEY")
hb_code=$(echo "$hb" | tail -n1)
hb_body=$(echo "$hb" | sed '$d')
assert "Heartbeat returns HTTP 200" \
    "$([[ "$hb_code" == "200" ]] && echo true || echo false)"

device_id=$(echo "$hb_body" | python -c "import sys,json; print(json.load(sys.stdin).get('device_id',''))" 2>/dev/null)
assert "Heartbeat response contains device_id" \
    "$([[ -n "$device_id" ]] && echo true || echo false)"

if [[ -n "$device_id" ]]; then
    detail=$(curl -s "${BASE_URL}/iot/devices/${device_id}")
    status=$(echo "$detail" | python -c "import sys,json; print(json.load(sys.stdin).get('online_status',''))" 2>/dev/null)
    assert "Device ${device_id} reports online_status='online' after heartbeat (got '$status')" \
        "$([[ "$status" == "online" ]] && echo true || echo false)"
fi
echo

# ---- Test 3: Devices with NULL last_heartbeat report 'unbekannt' ----
echo "[3] Devices without last_heartbeat report online_status='unbekannt'"
unknown_check=$(echo "$list" | python -c "
import sys, json
devices = json.load(sys.stdin)
bad = [d for d in devices
       if d.get('last_heartbeat') in (None, '', '0000-00-00 00:00:00')
       and d.get('online_status') != 'unbekannt']
print(len(bad))
")
assert "Devices with NULL/empty last_heartbeat are 'unbekannt' (bad=$unknown_check)" \
    "$([[ "$unknown_check" == "0" ]] && echo true || echo false)"
echo

# ---- Test 4: Devices with stale last_heartbeat report 'offline' ----
# A heartbeat older than 15 minutes must yield online_status='offline'.
# We rely on existing devices for this: a device with last_heartbeat older
# than 15 min must NOT report 'online'. Fail soft if no such device exists.
echo "[4] Devices with last_heartbeat older than 15 min report 'offline'"
stale_bad=$(echo "$list" | python -c "
import sys, json
from datetime import datetime, timedelta, timezone

devices = json.load(sys.stdin)
threshold = datetime.now(timezone.utc) - timedelta(minutes=15)
bad = []
checked = 0
for d in devices:
    hb = d.get('last_heartbeat')
    if not hb or hb in ('0000-00-00 00:00:00',):
        continue
    try:
        hb_dt = datetime.fromisoformat(hb.replace(' ', 'T')).replace(tzinfo=timezone.utc)
    except ValueError:
        continue
    if hb_dt < threshold:
        checked += 1
        if d.get('online_status') == 'online':
            bad.append(d.get('chip_id'))
print(f'{len(bad)}|{checked}')
")
bad_count="${stale_bad%%|*}"
checked_count="${stale_bad##*|}"
if [[ "$checked_count" == "0" ]]; then
    echo "  [SKIP] No devices with stale heartbeat in current data set"
else
    assert "All stale-heartbeat devices report offline (bad=$bad_count of $checked_count checked)" \
        "$([[ "$bad_count" == "0" ]] && echo true || echo false)"
fi
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
