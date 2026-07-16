#!/usr/bin/env bash
# ApexOne production watchdog — keep the CRM stack alive.
# Runs as root via systemd timer. Restarts failed/hung core services.
set -u

APP_ROOT="${APP_ROOT:-/var/www/apexone}"
LOG_FILE="${LOG_FILE:-$APP_ROOT/storage/logs/apexone-watchdog.log}"
STATE_FILE="${STATE_FILE:-$APP_ROOT/storage/logs/apexone-watchdog.state}"
DOMAIN="${WATCHDOG_DOMAIN:-crm.apexonepayments.com}"
HEALTH_URL="${WATCHDOG_HEALTH_URL:-https://127.0.0.1/up}"
WS_HEALTH_URL="${WATCHDOG_WS_HEALTH_URL:-http://127.0.0.1:8787/health}"
FAIL_THRESHOLD="${WATCHDOG_FAIL_THRESHOLD:-2}"

mkdir -p "$(dirname "$LOG_FILE")" "$(dirname "$STATE_FILE")"
touch "$LOG_FILE"
chmod 664 "$LOG_FILE" 2>/dev/null || true

ts() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }

log() {
  echo "[$(ts)] $*" | tee -a "$LOG_FILE" >/dev/null
  echo "[$(ts)] $*"
}

read_fail_count() {
  if [[ -f "$STATE_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$STATE_FILE" 2>/dev/null || true
  fi
  echo "${HTTP_FAIL_COUNT:-0}"
}

write_fail_count() {
  local count="$1"
  printf 'HTTP_FAIL_COUNT=%s\nLAST_CHECK=%s\n' "$count" "$(ts)" > "$STATE_FILE"
}

unit_exists() {
  systemctl cat "$1" >/dev/null 2>&1
}

ensure_unit() {
  local unit="$1"
  if ! unit_exists "$unit"; then
    return 0
  fi
  if systemctl is-active --quiet "$unit"; then
    return 0
  fi
  log "RESTART $unit (was not active: $(systemctl is-active "$unit" 2>/dev/null || true))"
  if systemctl restart "$unit"; then
    sleep 1
    if systemctl is-active --quiet "$unit"; then
      log "OK $unit is active after restart"
    else
      log "FAIL $unit still inactive after restart"
    fi
  else
    log "FAIL systemctl restart $unit"
  fi
}

http_code() {
  curl -sk --max-time 8 -o /dev/null -w "%{http_code}" \
    -H "Host: ${DOMAIN}" \
    "$HEALTH_URL" 2>/dev/null || echo "000"
}

ws_ok() {
  local body
  body="$(curl -fsS --max-time 5 "$WS_HEALTH_URL" 2>/dev/null || true)"
  [[ "$body" == *'"ok":true'* ]] || [[ "$body" == *'"ok": true'* ]]
}

# --- keep core units alive ---
ensure_unit nginx
ensure_unit php8.3-fpm
ensure_unit mysql
ensure_unit mariadb
ensure_unit apexone-queue
ensure_unit apex-call-events-ws

# --- HTTP health (/up) ---
code="$(http_code)"
fail_count="$(read_fail_count)"

if [[ "$code" == "200" || "$code" == "204" ]]; then
  if [[ "$fail_count" != "0" ]]; then
    log "RECOVERED http_up code=$code (after ${fail_count} failure(s))"
  fi
  write_fail_count 0
else
  fail_count=$((fail_count + 1))
  write_fail_count "$fail_count"
  log "WARN http_up code=$code fail_count=$fail_count/${FAIL_THRESHOLD}"

  if (( fail_count >= FAIL_THRESHOLD )); then
    log "ACTION restarting php8.3-fpm + nginx after repeated /up failures"
    systemctl restart php8.3-fpm || log "FAIL restart php8.3-fpm"
    systemctl restart nginx || log "FAIL restart nginx"
    sleep 2
    code2="$(http_code)"
    if [[ "$code2" == "200" || "$code2" == "204" ]]; then
      log "RECOVERED http_up after service restart code=$code2"
      write_fail_count 0
    else
      log "FAIL http_up still bad after restart code=$code2"
      # Second-line recovery: queue/ws often share load symptoms.
      systemctl restart apexone-queue || true
      systemctl restart apex-call-events-ws || true
    fi
  fi
fi

# --- WebSocket bridge health ---
if unit_exists apex-call-events-ws; then
  if ! ws_ok; then
    log "WARN call-events-ws health failed — restarting"
    systemctl restart apex-call-events-ws || log "FAIL restart apex-call-events-ws"
    sleep 1
    if ws_ok; then
      log "RECOVERED call-events-ws health"
    else
      log "FAIL call-events-ws health still bad"
    fi
  fi
fi

# --- Queue process sanity (unit active but worker dead) ---
if unit_exists apexone-queue && systemctl is-active --quiet apexone-queue; then
  if ! pgrep -af 'artisan queue:' >/dev/null 2>&1; then
    log "WARN apexone-queue active but no queue worker process — restarting"
    systemctl restart apexone-queue || log "FAIL restart apexone-queue"
  fi
fi

log "OK nginx=$(systemctl is-active nginx 2>/dev/null || true) fpm=$(systemctl is-active php8.3-fpm 2>/dev/null || true) queue=$(systemctl is-active apexone-queue 2>/dev/null || true) ws=$(systemctl is-active apex-call-events-ws 2>/dev/null || true) http=${code:-?}"

exit 0
