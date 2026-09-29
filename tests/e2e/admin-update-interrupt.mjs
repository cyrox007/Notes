import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = String(process.env[name] || '').trim();
  if (!value) throw new Error(`Не задана обязательная переменная ${name}`);
  return value;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePathRaw = requiredEnv('E2E_BASE_PATH');
const username = requiredEnv('E2E_USER');
const password = requiredEnv('E2E_PASSWORD');
const interruptPhase = requiredEnv('E2E_INTERRUPT_PHASE');
const expectedTarget = requiredEnv('E2E_TARGET_VERSION');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const allowedPhases = new Set(['migrations', 'postcheck']);
if (!allowedPhases.has(interruptPhase)) {
  throw new Error(`Неподдерживаемая фаза прерывания: ${interruptPhase}`);
}

async function login(page) {
  const response = await page.goto(`${baseUrl}/auth/login/`, {
    waitUntil: 'domcontentloaded',
  });
  if (!response || response.status() !== 200) {
    throw new Error(`Страница входа вернула HTTP ${response?.status()}`);
  }

  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), {
      timeout: 15000,
    }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);
}

async function postJson(page, path, headers = {}) {
  return await page.evaluate(async ({ path, headers }) => {
    const csrf = String(window.wspace?.security?.getCSRFToken?.() || '');
    const response = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
        ...headers,
      },
    });

    const text = await response.text();
    let payload = null;
    try {
      payload = JSON.parse(text);
    } catch (_) {
      // Текст попадёт в диагностическую ошибку ниже.
    }

    return {
      ok: response.ok,
      status: response.status,
      text,
      payload,
    };
  }, { path, headers });
}

const browser = await chromium.launch({ headless: true });
let transactionId = '';

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error));

  await login(page);

  const start = await postJson(page, '/admin/updates/web-start-latest');
  if (!start.ok || !start.payload?.success || !start.payload?.result) {
    throw new Error(
      `Не удалось начать updater-транзакцию: HTTP=${start.status}; body=${start.text}`
    );
  }

  let result = start.payload.result;
  transactionId = String(result.transaction_id || '');
  const token = String(result.continuation_token || '');
  const targetVersion = String(result.target_version || '');

  if (!transactionId || !token) {
    throw new Error('Updater не вернул transaction_id/continuation_token');
  }
  if (targetVersion !== expectedTarget) {
    throw new Error(
      `Updater выбрал неожиданную версию: ${targetVersion}; ожидалась ${expectedTarget}`
    );
  }

  let reached = false;
  for (let stepNumber = 0; stepNumber < 8; stepNumber += 1) {
    const step = await postJson(page, '/admin/updates/web-step', {
      'X-Workspace-Update-Transaction': transactionId,
      'X-Workspace-Update-Token': token,
    });

    if (!step.ok || !step.payload?.success || !step.payload?.result) {
      throw new Error(
        `Шаг updater завершился ошибкой до точки прерывания: HTTP=${step.status}; body=${step.text}`
      );
    }

    result = step.payload.result;
    const phase = String(result.phase || '');
    const status = String(result.status || '');

    if (phase === interruptPhase && status === 'in_progress') {
      reached = true;
      break;
    }

    if (status !== 'in_progress') {
      throw new Error(
        `Updater завершился до точки прерывания ${interruptPhase}: status=${status}; phase=${phase}`
      );
    }
  }

  if (!reached) {
    throw new Error(`Updater не достиг фазы прерывания ${interruptPhase}`);
  }

  if (pageErrors.length) throw pageErrors[0];

  // Закрываем весь браузерный контекст и намеренно не вызываем следующий
  // updater step. На диске остаётся настоящая незавершённая транзакция после
  // destructive boundary — тот же класс ситуации, что при обрыве клиента.
  await context.close();

  const recoveryResponse = await fetch(`${baseUrl}/`, {
    redirect: 'follow',
    signal: AbortSignal.timeout(120000),
  });
  const recoveryBody = await recoveryResponse.text();

  if (recoveryResponse.status !== 200) {
    throw new Error(
      `Первый HTTP-запрос после прерывания вернул ${recoveryResponse.status}: `
      + recoveryBody.slice(0, 1200)
    );
  }

  if (!recoveryBody.includes('Вход в Workspace')) {
    throw new Error(
      'Первый HTTP-запрос после прерывания не вернулся в штатный экран входа: '
      + recoveryBody.slice(0, 1200)
    );
  }

  console.log(`INTERRUPTED_TRANSACTION=${transactionId}`);
  console.log(`INTERRUPTED_PHASE=${interruptPhase}`);
  console.log('Автоматический boot recovery после реального прерывания web-updater: OK');
} finally {
  await browser.close();
}
