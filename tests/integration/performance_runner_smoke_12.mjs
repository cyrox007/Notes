#!/usr/bin/env node

// Автономный тест генератора нагрузки: только локальный сервер и три учётные записи.
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'notes-capacity-'));
const credentialsFile = path.join(temporary, 'credentials.ndjson');
const reportFile = path.join(temporary, 'report.json');
const users = Array.from({ length: 3 }, (_, i) => ({
  username: 'perf_12_' + (i + 1),
  password: 'synthetic-password',
}));
fs.writeFileSync(credentialsFile, users.map(item => JSON.stringify(item)).join('\n') + '\n', { mode: 0o600 });

const sessions = new Map();
const authenticated = new Set();
const errors = [];
let loginCount = 0;
let authorizedCalls = 0;

const server = http.createServer(async (req, res) => {
  const cookie = req.headers.cookie || '';
  const sessionId = /(?:^|;\s*)sid=([^;]+)/.exec(cookie)?.[1] || '';
  const pathname = new URL(req.url || '/', 'http://127.0.0.1').pathname;

  if (pathname === '/auth/login/' || pathname === '/auth/login') {
    if (req.method === 'GET') {
      const id = 'synthetic-' + String(sessions.size + 1);
      const csrf = 'token-' + id;
      sessions.set(id, { csrf, authorized: false });
      res.writeHead(200, {
        'Content-Type': 'text/html',
        'Set-Cookie': 'sid=' + id + '; HttpOnly; SameSite=Lax',
      });
      res.end('<form><input name="csrf_token" value="' + csrf + '"></form>');
      return;
    }
    const data = new URLSearchParams(await readBody(req));
    const state = sessions.get(sessionId);
    if (!state || data.get('csrf_token') !== state.csrf || !users.some(
      user => user.username === data.get('login') && user.password === data.get('password')
    )) {
      errors.push('Вход без проверенного CSRF или неправильной учётной записи');
      res.writeHead(403).end();
      return;
    }
    state.authorized = true;
    authenticated.add(sessionId);
    loginCount++;
    res.writeHead(302, { Location: '/' }).end();
    return;
  }

  const state = sessions.get(sessionId);
  if (!state?.authorized) {
    errors.push('Рабочий запрос без собственной авторизованной сессии');
    res.writeHead(302, { Location: '/auth/login/' }).end();
    return;
  }
  authorizedCalls++;
  if (req.method !== 'GET' && req.headers['x-csrf-token'] !== state.csrf) {
    errors.push('Изменяющая команда без CSRF');
    res.writeHead(403).end();
    return;
  }
  if (pathname === '/') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    res.end('<input name="csrf_token" value="' + state.csrf + '">');
    return;
  }
  if (req.method === 'POST' && pathname === '/api/calendar/events/') {
    res.writeHead(201, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ event: { uid: 'event-' + Date.now() } }));
    return;
  }
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ status: 'ok', items: [], report: { total: 0 } }));
});

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', chunk => chunks.push(chunk));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function run(options, overrides = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, ['tools/performance/load-runner.mjs', ...options], {
      cwd: root,
      env: { ...process.env, ...overrides },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let transcript = '';
    child.stdout.on('data', chunk => { transcript += chunk.toString(); });
    child.stderr.on('data', chunk => { transcript += chunk.toString(); });
    child.on('error', reject);
    child.on('close', code => resolve({ code, transcript }));
  });
}

try {
  await new Promise((resolve, reject) => server.listen(0, '127.0.0.1', error => {
    if (error) reject(error);
    else resolve();
  }));
  const target = 'http://127.0.0.1:' + server.address().port;
  const options = [
    '--mode=smoke',
    '--profile=tools/performance/profile-1.2.json',
    '--base-url=' + target,
    '--credentials=' + credentialsFile,
    '--output=' + reportFile,
    '--users=3',
    '--ramp=1',
    '--steady=5',
  ];
  const smoke = await run(options, { PERF_MODE: 'smoke' });
  assert.equal(smoke.code, 2, 'Пробный тест не должен выдать релизную приёмку: ' + smoke.transcript);
  const report = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
  assert.equal(report.sessions.ready, 3);
  assert.equal(report.sessions.failed, 0);
  assert.equal(report.sessions.active_peak, 3);
  assert.equal(report.acceptance.passed, false);
  assert.ok(report.requests.total > 0);
  assert.equal(report.requests.errors, 0, 'Ошибки маршрутов: ' + smoke.transcript);
  assert.equal(authenticated.size, 3);
  assert.equal(loginCount, 3);
  assert.ok(authorizedCalls >= 3);
  assert.deepEqual(errors, []);

  const denied = await run([
    '--mode=full',
    '--profile=tools/performance/profile-1.2.json',
    '--base-url=' + target,
    '--credentials=' + credentialsFile,
    '--output=' + path.join(temporary, 'denied.json'),
  ], { PERF_MODE: 'full', PERF_FIXTURE_CONFIRM: '', PERF_ISOLATED_STAND: '' });
  assert.notEqual(denied.code, 0);
  assert.match(denied.transcript, /изолированном стенде/);
  console.log('Пробный HTTP-прогон, три независимых сеанса, CSRF и защита полной приёмки проверены.');
} finally {
  await new Promise(resolve => server.close(resolve));
  fs.rmSync(temporary, { recursive: true, force: true });
}
