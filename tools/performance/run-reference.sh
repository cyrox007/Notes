#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROFILE="${PERF_PROFILE:-$ROOT/tools/performance/profile.json}"
OUTPUT_DIR="${PERF_OUTPUT_DIR:-$ROOT/private/performance/run-$(date -u +%Y%m%dT%H%M%SZ)}"
CONFIRM="${PERF_FIXTURE_CONFIRM:-}"

if [[ "$CONFIRM" != "WORKSPACE_PERFORMANCE_ONLY" ]]; then
  echo "Нужно задать PERF_FIXTURE_CONFIRM=WORKSPACE_PERFORMANCE_ONLY." >&2
  exit 2
fi
if [[ -z "${PERF_BASE_URL:-}" || -z "${PERF_CREDENTIALS:-}" ]]; then
  echo "Нужно задать PERF_BASE_URL и PERF_CREDENTIALS." >&2
  exit 2
fi
if [[ ! -f "$PERF_CREDENTIALS" ]]; then
  echo "Файл учётных данных не найден: $PERF_CREDENTIALS" >&2
  exit 2
fi
if [[ ! -f "$PROFILE" ]]; then
  echo "Профиль нагрузки не найден: $PROFILE" >&2
  exit 2
fi

cd "$ROOT"
COMMIT_SHA="$(git rev-parse HEAD)"
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Эталонный прогон разрешён только на чистом рабочем дереве exact commit." >&2
  exit 2
fi

mkdir -p "$OUTPUT_DIR"
chmod 700 "$OUTPUT_DIR"

export PERF_MODE=full
export PERF_COMMIT_SHA="$COMMIT_SHA"
export PERF_ENVIRONMENT_NAME="${PERF_ENVIRONMENT_NAME:-reference-1}"
export PERF_PROFILE="$PROFILE"

ENVIRONMENT_FILE="$OUTPUT_DIR/environment.json"
METRICS_FILE="$OUTPUT_DIR/server-metrics.ndjson"
REPORT_FILE="$OUTPUT_DIR/load-report.json"
RUN_LOG="$OUTPUT_DIR/load-runner.log"
METRICS_LOG="$OUTPUT_DIR/metrics.log"
MANIFEST_FILE="$OUTPUT_DIR/manifest.txt"

php tools/performance/collect-environment.php \
  --output="$ENVIRONMENT_FILE" \
  --commit="$COMMIT_SHA" \
  --environment="$PERF_ENVIRONMENT_NAME"

TOTAL_SECONDS="$(php -r '
$p=json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$total=(int)$p["ramp_seconds"]+(int)$p["steady_seconds"]+(int)$p["soak_seconds"]+(int)$p["peak_seconds"]+(int)$p["recovery_seconds"];
echo $total;
' "$PROFILE")"

php tools/performance/sample-metrics.php \
  --duration="$TOTAL_SECONDS" \
  --interval="${PERF_METRICS_INTERVAL:-5}" \
  --output="$METRICS_FILE" \
  --commit="$COMMIT_SHA" \
  --environment="$PERF_ENVIRONMENT_NAME" \
  >"$METRICS_LOG" 2>&1 &
METRICS_PID=$!

cleanup() {
  if kill -0 "$METRICS_PID" 2>/dev/null; then
    kill "$METRICS_PID" 2>/dev/null || true
    wait "$METRICS_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

set +e
node tools/performance/load-runner.mjs \
  --profile="$PROFILE" \
  --output="$REPORT_FILE" \
  >"$RUN_LOG" 2>&1
RUNNER_STATUS=$?
set -e

if kill -0 "$METRICS_PID" 2>/dev/null; then
  wait "$METRICS_PID" || METRICS_STATUS=$?
else
  METRICS_STATUS=0
fi
METRICS_STATUS="${METRICS_STATUS:-0}"
trap - EXIT INT TERM

{
  echo "Workspace Organizer 1.1 — эталонный нагрузочный прогон"
  echo "commit=$COMMIT_SHA"
  echo "environment=$PERF_ENVIRONMENT_NAME"
  echo "started_profile_seconds=$TOTAL_SECONDS"
  echo "runner_exit=$RUNNER_STATUS"
  echo "metrics_exit=$METRICS_STATUS"
  echo "base_url_host=$(php -r '$u=parse_url($argv[1]); echo is_array($u)?($u["host"]??""):"";' "$PERF_BASE_URL")"
  echo "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo
  sha256sum "$ENVIRONMENT_FILE" "$METRICS_FILE" "$REPORT_FILE" "$RUN_LOG" "$METRICS_LOG" 2>/dev/null || true
} >"$MANIFEST_FILE"
chmod 600 "$ENVIRONMENT_FILE" "$METRICS_FILE" "$REPORT_FILE" "$RUN_LOG" "$METRICS_LOG" "$MANIFEST_FILE" 2>/dev/null || true

echo "Артефакты эталонного прогона: $OUTPUT_DIR"
echo "Exact commit: $COMMIT_SHA"
echo "Код runner: $RUNNER_STATUS; код сборщика метрик: $METRICS_STATUS"

if [[ "$METRICS_STATUS" -ne 0 ]]; then
  exit "$METRICS_STATUS"
fi
exit "$RUNNER_STATUS"
