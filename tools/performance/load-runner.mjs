#!/usr/bin/env node

import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const args = parseArgs(process.argv.slice(2));
const profilePath = path.resolve(args.profile || process.env.PERF_PROFILE || path.join(here, 'profile.json'));
const credentialPath = path.resolve(args.credentials || process.env.PERF_CREDENTIALS || '');
const baseUrl = normalizeBaseUrl(args['base-url'] || process.env.PERF_BASE_URL || '');
const mode = String(args.mode || process.env.PERF_MODE || 'full').toLowerCase();
const outputPath = path.resolve(args.output || process.env.PERF_OUTPUT || 'performance-report.json');
const insecureTls = truthy(args['insecure-tls'] ?? process.env.PERF_INSECURE_TLS);
const profile = loadJson(profilePath);

validateProfile(profile);
if (!baseUrl) fail('Укажите PERF_BASE_URL или --base-url.');
if (!credentialPath || !fs.existsSync(credentialPath)) {
  fail('Файл PERF_CREDENTIALS не найден. Сначала подготовьте синтетических пользователей.');
}

const credentials = loadCredentials(credentialPath);
const settings = resolveSettings(profile, mode, args);
if (credentials.length < settings.maxUsers) {
  fail(`Недостаточно учётных записей: нужно ${settings.maxUsers}, доступно ${credentials.length}.`);
}

const target = new URL(baseUrl);
const agents = {
  http: new http.Agent({ keepAlive: true, maxSockets: Math.max(64, settings.maxUsers * 2) }),
  https: new https.Agent({
    keepAlive: true,
    maxSockets: Math.max(64, settings.maxUsers * 2),
    rejectUnauthorized: !insecureTls,
  }),
};

let currentPhase = 'startup';
let stopping = false;
let globalSequence = 0;
const virtualUsers = [];
const metrics = new Metrics(settings, baseUrl, profilePath, mode);

process.on('SIGINT', () => {
  console.error('\nПолучен SIGINT: завершаю новые действия и сохраняю накопленный отчёт.');
  stopping = true;
});

try {
  console.log(`Профиль: ${mode}; база: ${baseUrl}; пользователей: ${settings.nominalUsers}; пик: ${settings.peakUsers}`);
  currentPhase = 'ramp';
  await rampUsers(settings.nominalUsers, settings.rampSeconds);

  if (!stopping) {
    currentPhase = 'steady';
    await sleepSeconds(settings.steadySeconds);
  }
  if (!stopping && settings.soakSeconds > 0) {
    currentPhase = 'soak';
    await sleepSeconds(settings.soakSeconds);
  }
  if (!stopping && settings.peakUsers > settings.nominalUsers && settings.peakSeconds > 0) {
    currentPhase = 'peak';
    const peakRamp = Math.min(60, settings.peakSeconds);
    await rampUsers(settings.peakUsers, peakRamp);
    await sleepSeconds(Math.max(0, settings.peakSeconds - peakRamp));

    for (let i = settings.nominalUsers; i < virtualUsers.length; i += 1) {
      virtualUsers[i].enabled = false;
    }
  }
  if (!stopping && settings.recoverySeconds > 0) {
    currentPhase = 'recovery';
    await sleepSeconds(settings.recoverySeconds);
  }
} finally {
  currentPhase = 'shutdown';
  stopping = true;
  for (const vu of virtualUsers) vu.enabled = false;
  await Promise.allSettled(virtualUsers.map(vu => vu.promise).filter(Boolean));
  agents.http.destroy();
  agents.https.destroy();

  const report = metrics.report();
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
  printSummary(report, outputPath);

  if (!report.acceptance.passed) {
    process.exitCode = 2;
  }
}

async function rampUsers(targetCount, seconds) {
  const missing = Math.max(0, targetCount - virtualUsers.length);
  if (missing === 0) return;

  const spacing = seconds > 0 ? (seconds * 1000) / missing : 0;
  for (let i = 0; i < missing && !stopping; i += 1) {
    const id = virtualUsers.length + 1;
    const credential = credentials[id - 1];
    const vu = {
      id,
      credential,
      enabled: true,
      cookies: new CookieJar(),
      csrfToken: '',
      calendarUids: [],
      promise: null,
    };
    virtualUsers.push(vu);
    vu.promise = runVirtualUser(vu);
    if (spacing > 0 && i < missing - 1) await sleep(spacing);
  }
}

async function runVirtualUser(vu) {
  const loginOk = await login(vu);
  if (!loginOk) {
    metrics.recordSessionFailure();
    return;
  }
  metrics.recordSessionReady();

  while (!stopping && vu.enabled) {
    const op = chooseOperation(profile.operations);
    try {
      await executeOperation(vu, op);
    } catch (error) {
      metrics.recordTransportFailure(error);
    }
    await sleep(randomInt(settings.thinkMinMs, settings.thinkMaxMs));
  }
}

async function login(vu) {
  for (let attempt = 1; attempt <= 5 && !stopping; attempt += 1) {
    const started = performance.now();
    try {
      await request(vu, 'GET', '/auth/login', { captureBody: false });
      const body = new URLSearchParams({
        login: vu.credential.username,
        password: vu.credential.password,
      }).toString();
      const response = await request(vu, 'POST', '/auth/login', {
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
        captureBody: true,
        followRedirects: true,
      });

      if (response.status >= 200 && response.status < 400 && !response.url.pathname.includes('/auth/login')) {
        vu.csrfToken = extractCsrf(response.body);
        if (!vu.csrfToken) {
          const home = await request(vu, 'GET', '/', { captureBody: true });
          vu.csrfToken = extractCsrf(home.body);
        }
        metrics.recordLogin(performance.now() - started, true, response.status);
        return true;
      }
      metrics.recordLogin(performance.now() - started, false, response.status);
    } catch (error) {
      metrics.recordLogin(performance.now() - started, false, 0, error);
    }
    await sleep(Math.min(5000, 500 * 2 ** attempt));
  }
  return false;
}

async function executeOperation(vu, op) {
  switch (op.name) {
    case 'home':
      return measuredRequest(vu, op, 'GET', '/');
    case 'notes_list':
      return measuredRequest(vu, op, 'GET', `/notes/?page=${randomInt(1, 3)}&limit=50`);
    case 'notes_search':
      return measuredRequest(vu, op, 'GET', `/notes/?q=${encodeURIComponent('perf')}&page=1&limit=50`);
    case 'tasks_list':
      return measuredRequest(vu, op, 'GET', '/tasks/');
    case 'calendar_page':
      return measuredRequest(vu, op, 'GET', '/calendar/');
    case 'calendar_range': {
      const now = Date.now();
      const from = new Date(now - 7 * 86400000).toISOString();
      const to = new Date(now + 30 * 86400000).toISOString();
      return measuredRequest(vu, op, 'GET', `/api/calendar/events/?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
    }
    case 'files_list':
      return measuredRequest(vu, op, 'GET', '/files/');
    case 'messenger_page':
      return measuredRequest(vu, op, 'GET', '/messenger/');
    case 'notifications_count':
      return measuredRequest(vu, op, 'GET', '/api/notifications/unread-count');
    case 'messenger_long_poll':
      return measuredRequest(vu, op, 'GET', '/messenger/realtime/poll?wait_seconds=1');
    case 'calendar_create':
      return calendarCreate(vu, op);
    case 'calendar_update':
      return calendarUpdate(vu, op);
    case 'calendar_delete':
      return calendarDelete(vu, op);
    default:
      throw new Error(`Неизвестная операция профиля: ${op.name}`);
  }
}

async function calendarCreate(vu, op) {
  if (!vu.csrfToken) return calendarReadFallback(vu, op);
  if (vu.calendarUids.length >= 20) return calendarDelete(vu, op);

  const payload = calendarPayload(vu, ++globalSequence);
  const response = await measuredRequest(vu, op, 'POST', '/api/calendar/events/', {
    headers: jsonHeaders(vu),
    body: JSON.stringify(payload),
    captureBody: true,
  });
  if (response.status >= 200 && response.status < 300) {
    const parsed = safeJson(response.body);
    const uid = String(parsed?.event?.uid || '');
    if (uid) vu.calendarUids.push(uid);
  }
  return response;
}

async function calendarUpdate(vu, op) {
  if (!vu.csrfToken || vu.calendarUids.length === 0) return calendarReadFallback(vu, op);
  const uid = vu.calendarUids[randomInt(0, vu.calendarUids.length - 1)];
  return measuredRequest(vu, op, 'PUT', `/api/calendar/events/${encodeURIComponent(uid)}`, {
    headers: jsonHeaders(vu),
    body: JSON.stringify(calendarPayload(vu, ++globalSequence)),
  });
}

async function calendarDelete(vu, op) {
  if (!vu.csrfToken || vu.calendarUids.length === 0) return calendarReadFallback(vu, op);
  const index = randomInt(0, vu.calendarUids.length - 1);
  const [uid] = vu.calendarUids.splice(index, 1);
  return measuredRequest(vu, op, 'DELETE', `/api/calendar/events/${encodeURIComponent(uid)}`, {
    headers: jsonHeaders(vu),
  });
}

async function calendarReadFallback(vu, op) {
  const fallback = { ...op, name: `${op.name}_fallback_read`, kind: 'read' };
  const now = Date.now();
  const from = new Date(now - 86400000).toISOString();
  const to = new Date(now + 7 * 86400000).toISOString();
  return measuredRequest(vu, fallback, 'GET', `/api/calendar/events/?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
}

function calendarPayload(vu, sequence) {
  const start = new Date(Date.now() + randomInt(1, 30) * 86400000 + randomInt(8, 16) * 3600000);
  start.setUTCMinutes(0, 0, 0);
  const end = new Date(start.getTime() + 3600000);
  return {
    title: `Нагрузочное событие ${vu.id}-${sequence}`,
    description: 'Синтетическая запись performance-runner',
    location: '',
    all_day: false,
    timezone: 'UTC',
    starts_at: start.toISOString().slice(0, 16),
    ends_at: end.toISOString().slice(0, 16),
    recurrence: null,
    reminder_offsets: [],
  };
}

function jsonHeaders(vu) {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-Token': vu.csrfToken,
  };
}

async function measuredRequest(vu, op, method, requestPath, options = {}) {
  const started = performance.now();
  try {
    const response = await request(vu, method, requestPath, options);
    const duration = performance.now() - started;
    const ok = response.status >= 200 && response.status < 400;
    metrics.record(op.name, op.kind, currentPhase, duration, response.status, ok);
    return response;
  } catch (error) {
    const duration = performance.now() - started;
    metrics.record(op.name, op.kind, currentPhase, duration, 0, false, error);
    throw error;
  }
}

async function request(vu, method, requestPath, options = {}) {
  let url = new URL(requestPath, target);
  let currentMethod = method;
  let body = options.body ?? null;
  const headers = { ...(options.headers || {}) };
  const follow = options.followRedirects !== false;
  const maxRedirects = 5;

  for (let redirect = 0; redirect <= maxRedirects; redirect += 1) {
    const response = await requestOnce(vu, url, currentMethod, headers, body, options.captureBody === true);
    const location = response.headers.location;
    if (!follow || !location || ![301, 302, 303, 307, 308].includes(response.status)) return response;
    if (redirect === maxRedirects) throw new Error('Превышен предел HTTP-переходов.');

    url = new URL(location, url);
    if ([301, 302, 303].includes(response.status) && currentMethod !== 'GET' && currentMethod !== 'HEAD') {
      currentMethod = 'GET';
      body = null;
      delete headers['Content-Type'];
      delete headers['Content-Length'];
    }
  }
  throw new Error('Не удалось завершить HTTP-запрос.');
}

function requestOnce(vu, url, method, headers, body, captureBody) {
  return new Promise((resolve, reject) => {
    const isHttps = url.protocol === 'https:';
    const client = isHttps ? https : http;
    const requestHeaders = {
      Accept: headers.Accept || 'text/html,application/json;q=0.9,*/*;q=0.8',
      'User-Agent': `Workspace-Performance-Runner/1.1 vu/${vu.id}`,
      ...headers,
    };
    const cookie = vu.cookies.header();
    if (cookie) requestHeaders.Cookie = cookie;
    if (body !== null && body !== undefined) {
      requestHeaders['Content-Length'] = Buffer.byteLength(body);
    }

    const req = client.request({
      protocol: url.protocol,
      hostname: url.hostname,
      port: url.port || undefined,
      path: `${url.pathname}${url.search}`,
      method,
      headers: requestHeaders,
      agent: isHttps ? agents.https : agents.http,
    }, res => {
      vu.cookies.absorb(res.headers['set-cookie']);
      const chunks = [];
      let bytes = 0;
      res.on('data', chunk => {
        if (!captureBody || bytes >= 2_000_000) return;
        bytes += chunk.length;
        if (bytes <= 2_000_000) chunks.push(chunk);
      });
      res.on('end', () => {
        resolve({
          status: res.statusCode || 0,
          headers: res.headers,
          body: captureBody ? Buffer.concat(chunks).toString('utf8') : '',
          url,
        });
      });
    });
    req.setTimeout(settings.requestTimeoutMs, () => req.destroy(new Error('HTTP timeout')));
    req.on('error', reject);
    if (body !== null && body !== undefined) req.write(body);
    req.end();
  });
}

class CookieJar {
  constructor() {
    this.values = new Map();
  }

  absorb(setCookie) {
    if (!setCookie) return;
    const rows = Array.isArray(setCookie) ? setCookie : [setCookie];
    for (const row of rows) {
      const pair = String(row).split(';', 1)[0];
      const index = pair.indexOf('=');
      if (index <= 0) continue;
      const name = pair.slice(0, index).trim();
      const value = pair.slice(index + 1).trim();
      if (!value) this.values.delete(name);
      else this.values.set(name, value);
    }
  }

  header() {
    return [...this.values.entries()].map(([key, value]) => `${key}=${value}`).join('; ');
  }
}

class Histogram {
  constructor(maxMs = 60000) {
    this.maxMs = maxMs;
    this.counts = new Uint32Array(maxMs + 1);
    this.overflow = 0;
    this.total = 0;
    this.sum = 0;
    this.min = Infinity;
    this.max = 0;
  }

  add(value) {
    const ms = Math.max(0, Math.round(Number(value) || 0));
    this.total += 1;
    this.sum += ms;
    this.min = Math.min(this.min, ms);
    this.max = Math.max(this.max, ms);
    if (ms <= this.maxMs) this.counts[ms] += 1;
    else this.overflow += 1;
  }

  percentile(percent) {
    if (this.total === 0) return 0;
    const targetCount = Math.max(1, Math.ceil(this.total * percent));
    let seen = 0;
    for (let ms = 0; ms <= this.maxMs; ms += 1) {
      seen += this.counts[ms];
      if (seen >= targetCount) return ms;
    }
    return this.max;
  }

  summary() {
    return {
      count: this.total,
      min_ms: this.total ? this.min : 0,
      mean_ms: this.total ? Math.round((this.sum / this.total) * 100) / 100 : 0,
      p50_ms: this.percentile(0.50),
      p95_ms: this.percentile(0.95),
      p99_ms: this.percentile(0.99),
      max_ms: this.max,
      overflow_60s: this.overflow,
    };
  }
}

class Metrics {
  constructor(runSettings, targetUrl, sourceProfile, runMode) {
    this.startedAt = new Date();
    this.startedMono = performance.now();
    this.runSettings = runSettings;
    this.targetUrl = targetUrl;
    this.sourceProfile = sourceProfile;
    this.runMode = runMode;
    this.total = 0;
    this.errors = 0;
    this.statuses = {};
    this.sessionsReady = 0;
    this.sessionFailures = 0;
    this.login = new Histogram();
    this.loginFailures = 0;
    this.byKind = new Map();
    this.byOperation = new Map();
    this.byPhase = new Map();
    this.transportFailures = 0;
  }

  recordSessionReady() {
    this.sessionsReady += 1;
  }

  recordSessionFailure() {
    this.sessionFailures += 1;
  }

  recordLogin(duration, ok, status) {
    this.login.add(duration);
    if (!ok) this.loginFailures += 1;
    const key = `login:${status}`;
    this.statuses[key] = (this.statuses[key] || 0) + 1;
  }

  record(name, kind, phase, duration, status, ok) {
    this.total += 1;
    if (!ok) this.errors += 1;
    this.statuses[String(status)] = (this.statuses[String(status)] || 0) + 1;
    this.bucket(this.byKind, kind).record(duration, ok, status);
    this.bucket(this.byOperation, name).record(duration, ok, status);
    this.bucket(this.byPhase, phase).record(duration, ok, status);
  }

  recordTransportFailure() {
    this.transportFailures += 1;
  }

  bucket(map, key) {
    if (!map.has(key)) map.set(key, new MetricBucket());
    return map.get(key);
  }

  report() {
    const elapsedSeconds = Math.max(0.001, (performance.now() - this.startedMono) / 1000);
    const errorRate = this.total ? this.errors / this.total : 1;
    const read = this.byKind.get('read')?.histogram.summary() || emptySummary();
    const write = this.byKind.get('write')?.histogram.summary() || emptySummary();
    const overall = mergeBuckets([...this.byKind.values()]).histogram.summary();
    const acceptance = {
      passed: this.runMode === 'full'
        && this.sessionsReady >= this.runSettings.nominalUsers
        && errorRate < 0.005
        && read.p95_ms <= 700
        && write.p95_ms <= 1200
        && overall.p99_ms <= 2500,
      evaluated_as_release_evidence: this.runMode === 'full',
      requirements: {
        nominal_sessions_ready: { expected: this.runSettings.nominalUsers, actual: this.sessionsReady },
        error_rate_lt_0_5_percent: { expected: 0.005, actual: errorRate },
        read_p95_ms_le_700: { expected: 700, actual: read.p95_ms },
        write_p95_ms_le_1200: { expected: 1200, actual: write.p95_ms },
        overall_p99_ms_le_2500: { expected: 2500, actual: overall.p99_ms },
      },
    };

    return {
      schema: 1,
      runner: 'Workspace Organizer performance runner',
      started_at: this.startedAt.toISOString(),
      finished_at: new Date().toISOString(),
      elapsed_seconds: Math.round(elapsedSeconds * 100) / 100,
      mode: this.runMode,
      base_url: this.targetUrl,
      profile_path: this.sourceProfile,
      settings: this.runSettings,
      sessions: {
        ready: this.sessionsReady,
        failed: this.sessionFailures,
        login_failures: this.loginFailures,
        login_latency: this.login.summary(),
      },
      requests: {
        total: this.total,
        errors: this.errors,
        error_rate: errorRate,
        rps: Math.round((this.total / elapsedSeconds) * 100) / 100,
        transport_failures: this.transportFailures,
        statuses: this.statuses,
        latency: overall,
      },
      by_kind: mapSummary(this.byKind),
      by_operation: mapSummary(this.byOperation),
      by_phase: mapSummary(this.byPhase),
      acceptance,
      environment: {
        node: process.version,
        platform: process.platform,
        arch: process.arch,
        commit: process.env.PERF_COMMIT_SHA || '',
        environment_name: process.env.PERF_ENVIRONMENT_NAME || '',
        infrastructure: process.env.PERF_INFRASTRUCTURE || '',
      },
    };
  }
}

class MetricBucket {
  constructor() {
    this.histogram = new Histogram();
    this.total = 0;
    this.errors = 0;
    this.statuses = {};
  }

  record(duration, ok, status) {
    this.total += 1;
    if (!ok) this.errors += 1;
    this.histogram.add(duration);
    this.statuses[String(status)] = (this.statuses[String(status)] || 0) + 1;
  }
}

function mergeBuckets(buckets) {
  const merged = new MetricBucket();
  for (const bucket of buckets) {
    merged.total += bucket.total;
    merged.errors += bucket.errors;
    for (const [status, count] of Object.entries(bucket.statuses)) {
      merged.statuses[status] = (merged.statuses[status] || 0) + count;
    }
    const h = bucket.histogram;
    merged.histogram.total += h.total;
    merged.histogram.sum += h.sum;
    merged.histogram.min = Math.min(merged.histogram.min, h.min);
    merged.histogram.max = Math.max(merged.histogram.max, h.max);
    merged.histogram.overflow += h.overflow;
    for (let i = 0; i < h.counts.length; i += 1) merged.histogram.counts[i] += h.counts[i];
  }
  if (!Number.isFinite(merged.histogram.min)) merged.histogram.min = 0;
  return merged;
}

function mapSummary(map) {
  return Object.fromEntries([...map.entries()].map(([key, bucket]) => [key, {
    requests: bucket.total,
    errors: bucket.errors,
    error_rate: bucket.total ? bucket.errors / bucket.total : 0,
    statuses: bucket.statuses,
    latency: bucket.histogram.summary(),
  }]));
}

function emptySummary() {
  return { count: 0, min_ms: 0, mean_ms: 0, p50_ms: 0, p95_ms: 0, p99_ms: 0, max_ms: 0, overflow_60s: 0 };
}

function chooseOperation(operations) {
  const roll = Math.random() * operations.reduce((sum, op) => sum + op.weight, 0);
  let cursor = 0;
  for (const op of operations) {
    cursor += op.weight;
    if (roll < cursor) return op;
  }
  return operations[operations.length - 1];
}

function resolveSettings(source, selectedMode, cli) {
  if (!['full', 'smoke'].includes(selectedMode)) fail('PERF_MODE должен быть full или smoke.');
  if (selectedMode === 'smoke') {
    const users = positiveInt(cli.users || process.env.PERF_USERS || 20, 'users');
    return {
      nominalUsers: users,
      peakUsers: users,
      maxUsers: users,
      rampSeconds: Number(cli.ramp || process.env.PERF_RAMP_SECONDS || 5),
      steadySeconds: Number(cli.steady || process.env.PERF_STEADY_SECONDS || 20),
      soakSeconds: 0,
      peakSeconds: 0,
      recoverySeconds: 0,
      thinkMinMs: 100,
      thinkMaxMs: 500,
      requestTimeoutMs: source.request_timeout_ms,
    };
  }
  const nominalUsers = positiveInt(cli.users || process.env.PERF_USERS || source.nominal_users, 'users');
  const peakUsers = positiveInt(process.env.PERF_PEAK_USERS || source.peak_users, 'peak_users');
  if (peakUsers < nominalUsers) fail('peak_users не может быть меньше nominal_users.');
  return {
    nominalUsers,
    peakUsers,
    maxUsers: peakUsers,
    rampSeconds: Number(process.env.PERF_RAMP_SECONDS || source.ramp_seconds),
    steadySeconds: Number(process.env.PERF_STEADY_SECONDS || source.steady_seconds),
    soakSeconds: Number(process.env.PERF_SOAK_SECONDS || source.soak_seconds),
    peakSeconds: Number(process.env.PERF_PEAK_SECONDS || source.peak_seconds),
    recoverySeconds: Number(process.env.PERF_RECOVERY_SECONDS || source.recovery_seconds),
    thinkMinMs: source.think_time_ms.min,
    thinkMaxMs: source.think_time_ms.max,
    requestTimeoutMs: source.request_timeout_ms,
  };
}

function validateProfile(source) {
  if (source?.schema !== 1) fail('Неподдерживаемая версия профиля нагрузки.');
  if (!Array.isArray(source.operations) || source.operations.length === 0) fail('Профиль не содержит операций.');
  const names = new Set();
  let weight = 0;
  for (const op of source.operations) {
    if (!op || typeof op.name !== 'string' || names.has(op.name)) fail('Имена операций должны быть уникальными строками.');
    if (!['read', 'write', 'realtime'].includes(op.kind)) fail(`Некорректный тип операции ${op.name}.`);
    if (!Number.isFinite(op.weight) || op.weight <= 0) fail(`Некорректный вес операции ${op.name}.`);
    names.add(op.name);
    weight += op.weight;
  }
  if (Math.abs(weight - 100) > 0.0001) fail(`Сумма весов операций должна быть 100, сейчас ${weight}.`);
  if (source.nominal_users < 1000) fail('Полный профиль обязан содержать не менее 1000 пользователей.');
  if (source.peak_users <= source.nominal_users) fail('Пиковая нагрузка должна быть выше штатной.');
  if (source.ramp_seconds < 300 || source.ramp_seconds > 600) fail('Разогрев полного профиля должен длиться 5–10 минут.');
  if (source.steady_seconds < 1800) fail('Устойчивый этап должен длиться не менее 30 минут.');
  if (source.soak_seconds < 7200) fail('Длительная проверка должна длиться не менее 2 часов.');
}

function loadCredentials(file) {
  const rows = fs.readFileSync(file, 'utf8').split(/\r?\n/).filter(Boolean);
  return rows.map((row, index) => {
    const value = safeJson(row);
    if (!value || typeof value.username !== 'string' || typeof value.password !== 'string') {
      fail(`Некорректная строка учётных данных ${index + 1}.`);
    }
    return value;
  });
}

function loadJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    fail(`Не удалось прочитать JSON ${file}: ${error.message}`);
  }
}

function safeJson(value) {
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

function extractCsrf(html) {
  if (!html) return '';
  const direct = html.match(/name=["']csrf_token["'][^>]*value=["']([^"']+)["']/i);
  if (direct) return direct[1];
  const reverse = html.match(/value=["']([^"']+)["'][^>]*name=["']csrf_token["']/i);
  return reverse ? reverse[1] : '';
}

function parseArgs(values) {
  const result = {};
  for (const value of values) {
    if (!value.startsWith('--')) continue;
    const [key, ...rest] = value.slice(2).split('=');
    result[key] = rest.length ? rest.join('=') : true;
  }
  return result;
}

function normalizeBaseUrl(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  const url = new URL(raw);
  if (!['http:', 'https:'].includes(url.protocol)) fail('PERF_BASE_URL должен использовать http или https.');
  url.hash = '';
  url.search = '';
  if (!url.pathname.endsWith('/')) url.pathname += '/';
  return url.toString();
}

function positiveInt(value, name) {
  const number = Number.parseInt(String(value), 10);
  if (!Number.isInteger(number) || number < 1) fail(`${name} должен быть положительным целым числом.`);
  return number;
}

function truthy(value) {
  return ['1', 'true', 'yes', 'on'].includes(String(value || '').toLowerCase());
}

function randomInt(min, max) {
  const lo = Math.ceil(min);
  const hi = Math.floor(max);
  return Math.floor(Math.random() * (hi - lo + 1)) + lo;
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, Math.max(0, ms)));
}

async function sleepSeconds(seconds) {
  const until = Date.now() + Math.max(0, seconds) * 1000;
  while (!stopping && Date.now() < until) {
    await sleep(Math.min(1000, until - Date.now()));
  }
}

function printSummary(report, file) {
  console.log('');
  console.log(`Отчёт: ${file}`);
  console.log(`Сессии: ${report.sessions.ready}/${report.settings.nominalUsers}; запросы: ${report.requests.total}; RPS: ${report.requests.rps}`);
  console.log(`Ошибки: ${(report.requests.error_rate * 100).toFixed(3)}%; p95 чтения: ${report.by_kind.read?.latency?.p95_ms ?? 0} мс; p95 записи: ${report.by_kind.write?.latency?.p95_ms ?? 0} мс; p99: ${report.requests.latency.p99_ms} мс`);
  console.log(`Приёмочный результат: ${report.acceptance.passed ? 'ПРОЙДЕН' : 'НЕ ПРОЙДЕН/НЕ ПРИЁМОЧНЫЙ РЕЖИМ'}`);
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
