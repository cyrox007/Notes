import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

function wavFixture(seconds = 2, sampleRate = 8000) {
  const samples = seconds * sampleRate;
  const dataSize = samples * 2;
  const buffer = Buffer.alloc(44 + dataSize);
  buffer.write('RIFF', 0);
  buffer.writeUInt32LE(36 + dataSize, 4);
  buffer.write('WAVE', 8);
  buffer.write('fmt ', 12);
  buffer.writeUInt32LE(16, 16);
  buffer.writeUInt16LE(1, 20);
  buffer.writeUInt16LE(1, 22);
  buffer.writeUInt32LE(sampleRate, 24);
  buffer.writeUInt32LE(sampleRate * 2, 28);
  buffer.writeUInt16LE(2, 32);
  buffer.writeUInt16LE(16, 34);
  buffer.write('data', 36);
  buffer.writeUInt32LE(dataSize, 40);
  return buffer;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePathRaw = requiredEnv('E2E_BASE_PATH');
const username = requiredEnv('E2E_USER');
const password = requiredEnv('E2E_PASSWORD');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const escapedRequests = [];
const httpErrors = [];

page.on('pageerror', (error) => pageErrors.push(error));
page.on('request', (request) => {
  const url = new URL(request.url());
  if (url.origin === origin && url.pathname !== basePath && !url.pathname.startsWith(basePath + '/')) escapedRequests.push(url.pathname);
});
page.on('response', (response) => {
  const url = new URL(response.url());
  if (url.origin === origin && response.status() >= 400) httpErrors.push(`${response.status()} ${url.pathname}`);
});

try {
  await page.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);

  const stamp = Date.now();
  const noteName = `Voice contract ${stamp}`;
  await page.goto(`${baseUrl}/notes/`, { waitUntil: 'domcontentloaded' });
  await page.locator('.notes__create input[name="notename"]').fill(noteName);
  await Promise.all([
    page.waitForURL((url) => /\/notes\/[\w-]+\/edit\/?$/.test(url.pathname), { timeout: 15000 }),
    page.locator('.notes__create button[type="submit"]').click(),
  ]);

  const editor = page.locator('[data-note-editor-013]');
  await editor.waitFor({ state: 'visible', timeout: 10000 });
  const uploadPath = await editor.getAttribute('data-upload-url');
  if (!uploadPath || !uploadPath.startsWith(`${basePath}/notes/upload/`)) throw new Error(`Voice upload URL escaped BASE_PATH: ${uploadPath}`);

  const voiceButton = page.getByRole('button', { name: 'Голосовая заметка', exact: true });
  await voiceButton.waitFor({ state: 'visible', timeout: 5000 });
  await voiceButton.click();
  await page.locator('#voiceRecorder').waitFor({ state: 'visible', timeout: 5000 });
  await page.getByRole('button', { name: /Начать запись/ }).waitFor({ state: 'visible' });
  await page.getByRole('button', { name: 'Сохранить голосовую', exact: true }).waitFor({ state: 'visible' });

  const upload = await context.request.post(origin + uploadPath, {
    multipart: {
      attachment: {
        name: `voice-${stamp}.wav`,
        mimeType: 'audio/wav',
        buffer: wavFixture(2),
      },
      is_voice: 'true',
      duration: '2',
    },
  });
  if (upload.status() !== 200) throw new Error(`Voice upload returned HTTP ${upload.status()}: ${await upload.text()}`);
  const payload = await upload.json();
  if (payload?.success !== true || payload?.attachment?.file_type !== 'voice' || Number(payload?.attachment?.duration) !== 2) {
    throw new Error(`Unexpected voice upload payload: ${JSON.stringify(payload)}`);
  }

  await page.reload({ waitUntil: 'domcontentloaded' });
  const voiceItem = page.locator('.attachment-item--voice').filter({ hasText: 'Голосовая заметка' }).first();
  await voiceItem.waitFor({ state: 'visible', timeout: 10000 });
  await voiceItem.getByText('2 сек', { exact: false }).waitFor({ state: 'visible', timeout: 5000 });

  const source = voiceItem.locator('audio source');
  const href = await source.getAttribute('src');
  if (!href || !href.startsWith(`${basePath}/notes/attachment/`)) throw new Error(`Voice playback URL escaped BASE_PATH: ${href}`);
  const playback = await context.request.get(origin + href);
  const body = await playback.body();
  const contentType = playback.headers()['content-type'] || '';
  if (playback.status() !== 200 || body.length < 100 || !contentType.startsWith('audio/')) {
    throw new Error(`Voice playback invalid: HTTP ${playback.status()}, ${contentType}, ${body.length} bytes`);
  }

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  if (httpErrors.length) throw new Error(`Unexpected HTTP errors: ${httpErrors.join(', ')}`);

  console.log('Notes voice UI/upload/playback contract: OK');
} finally {
  await context.close();
  await browser.close();
}
