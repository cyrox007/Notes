import fs from 'node:fs';
import { performance } from 'node:perf_hooks';

const origin = (process.env.E2E_ORIGIN || 'http://127.0.0.1:18100').replace(/\/$/, '');
const basePathRaw = process.env.E2E_BASE_PATH || '/workspace';
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + (basePath === '/' ? '' : basePath);
const cookieFile = process.env.E2E_COOKIE_FILE || '/tmp/release-evidence-cookie.txt';
const resultOut = process.env.E2E_LOAD_RESULT_OUT || '/tmp/release-load-evidence.json';

const totalRequests = Math.max(100, Number.parseInt(process.env.LOAD_TOTAL_REQUESTS || '600', 10));
const loadConcurrency = Math.max(1, Math.min(64, Number.parseInt(process.env.LOAD_CONCURRENCY || '12', 10)));
const soakSeconds = Math.max(10, Math.min(300, Number.parseInt(process.env.SOAK_SECONDS || '45', 10)));
const soakConcurrency = Math.max(1, Math.min(32, Number.parseInt(process.env.SOAK_CONCURRENCY || '4', 10)));
const maxP95Ms = Math.max(100, Number.parseInt(process.env.MAX_P95_MS || '2500', 10));
const minLoadRps = Math.max(0.1, Number.parseFloat(process.env.MIN_LOAD_RPS || '5'));
const minSoakRps = Math.max(0.1, Number.parseFloat(process.env.MIN_SOAK_RPS || '3'));

const endpoints = ['/', '/notes/', '/tasks/', '/files/', '/profile/'];

if (!fs.existsSync(cookieFile)) {
  throw new Error('Authenticated cookie file is missing: ' + cookieFile);
}
const cookie = fs.readFileSync(cookieFile, 'utf8').trim();
if (!cookie.includes('PHPSESSID=')) {
  throw new Error('Authenticated cookie file does not contain PHPSESSID');
}

function percentile(values, percentileValue) {
  if (values.length === 0) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const index = Math.min(
    sorted.length - 1,
    Math.max(0, Math.ceil((percentileValue / 100) * sorted.length) - 1),
  );
  return sorted[index];
}

async function oneRequest(index) {
  const endpoint = endpoints[index % endpoints.length];
  const started = performance.now();

  try {
    const response = await fetch(baseUrl + endpoint, {
      method: 'GET',
      headers: {
        Cookie: cookie,
        Accept: 'text/html,application/xhtml+xml',
        'User-Agent': 'Workspace-Organizer-Release-Evidence/1.0',
      },
      redirect: 'manual',
      signal: AbortSignal.timeout(10000),
    });

    const durationMs = performance.now() - started;
    const location = response.headers.get('location') || '';
    await response.arrayBuffer();

    const authRedirect = response.status >= 300
      && response.status < 400
      && location.includes('/auth/login');
    const ok = response.status === 200 && !authRedirect;

    return {
      ok,
      endpoint,
      status: response.status,
      duration_ms: durationMs,
      error: ok ? null : (authRedirect ? 'auth_redirect' : 'unexpected_status'),
    };
  } catch (error) {
    return {
      ok: false,
      endpoint,
      status: 0,
      duration_ms: performance.now() - started,
      error: error instanceof Error ? error.name + ': ' + error.message : String(error),
    };
  }
}

async function runFixedPhase(total, concurrency) {
  let nextIndex = 0;
  const results = [];
  const started = performance.now();

  async function worker() {
    while (true) {
      const index = nextIndex++;
      if (index >= total) return;
      results.push(await oneRequest(index));
    }
  }

  await Promise.all(Array.from({ length: concurrency }, () => worker()));
  return summarize('load', results, performance.now() - started);
}

async function runSoakPhase(seconds, concurrency) {
  let sequence = 0;
  const results = [];
  const started = performance.now();
  const deadline = started + (seconds * 1000);

  async function worker() {
    while (performance.now() < deadline) {
      const index = sequence++;
      results.push(await oneRequest(index));
    }
  }

  await Promise.all(Array.from({ length: concurrency }, () => worker()));
  return summarize('soak', results, performance.now() - started);
}

function summarize(name, results, elapsedMs) {
  const durations = results.map(result => result.duration_ms);
  const errors = results.filter(result => !result.ok);
  const statusCounts = {};
  const endpointCounts = {};

  for (const result of results) {
    const statusKey = String(result.status);
    statusCounts[statusKey] = (statusCounts[statusKey] || 0) + 1;
    endpointCounts[result.endpoint] = (endpointCounts[result.endpoint] || 0) + 1;
  }

  return {
    phase: name,
    requests: results.length,
    successes: results.length - errors.length,
    errors: errors.length,
    elapsed_ms: Math.round(elapsedMs),
    throughput_rps: Number((results.length / Math.max(0.001, elapsedMs / 1000)).toFixed(2)),
    latency_ms: {
      p50: Math.round(percentile(durations, 50)),
      p95: Math.round(percentile(durations, 95)),
      p99: Math.round(percentile(durations, 99)),
      max: Math.round(durations.length ? Math.max(...durations) : 0),
    },
    status_counts: statusCounts,
    endpoint_counts: endpointCounts,
    error_samples: errors.slice(0, 10).map(result => ({
      endpoint: result.endpoint,
      status: result.status,
      error: result.error,
      duration_ms: Math.round(result.duration_ms),
    })),
  };
}

// Small authenticated warmup removes one-time template/runtime initialization from
// the evidence window while still failing immediately on an auth or HTTP regression.
for (let index = 0; index < endpoints.length; index++) {
  const warmup = await oneRequest(index);
  if (!warmup.ok) {
    throw new Error('Warmup failed for ' + warmup.endpoint + ': ' + warmup.status + ' ' + warmup.error);
  }
}

const load = await runFixedPhase(totalRequests, loadConcurrency);
const soak = await runSoakPhase(soakSeconds, soakConcurrency);

const failures = [];
for (const phase of [load, soak]) {
  if (phase.errors !== 0) failures.push(phase.phase + ' produced ' + phase.errors + ' request errors');
  if (phase.latency_ms.p95 > maxP95Ms) {
    failures.push(phase.phase + ' p95 ' + phase.latency_ms.p95 + 'ms exceeds ' + maxP95Ms + 'ms');
  }
}
if (load.requests !== totalRequests) failures.push('load request count drifted');
if (load.throughput_rps < minLoadRps) {
  failures.push('load throughput ' + load.throughput_rps + 'rps below ' + minLoadRps + 'rps');
}
if (soak.throughput_rps < minSoakRps) {
  failures.push('soak throughput ' + soak.throughput_rps + 'rps below ' + minSoakRps + 'rps');
}

const evidence = {
  status: failures.length === 0 ? 'ok' : 'fail',
  target: { origin, base_path: basePath, endpoints },
  thresholds: {
    load_total_requests: totalRequests,
    load_concurrency: loadConcurrency,
    soak_seconds: soakSeconds,
    soak_concurrency: soakConcurrency,
    max_p95_ms: maxP95Ms,
    min_load_rps: minLoadRps,
    min_soak_rps: minSoakRps,
    allowed_errors: 0,
  },
  load,
  soak,
  failures,
};

fs.writeFileSync(resultOut, JSON.stringify(evidence, null, 2) + '\n');

console.log(
  'Load evidence: ' + load.requests + ' requests, '
  + load.throughput_rps + ' rps, p95=' + load.latency_ms.p95 + 'ms, errors=' + load.errors,
);
console.log(
  'Soak evidence: ' + soak.requests + ' requests/' + soakSeconds + 's, '
  + soak.throughput_rps + ' rps, p95=' + soak.latency_ms.p95 + 'ms, errors=' + soak.errors,
);

if (failures.length > 0) {
  for (const failure of failures) console.error('[FAIL] ' + failure);
  process.exit(1);
}
