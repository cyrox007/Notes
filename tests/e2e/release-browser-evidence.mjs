import fs from 'node:fs';
import { chromium, firefox, webkit } from 'playwright';

const origin = (process.env.E2E_ORIGIN || 'http://127.0.0.1:18100').replace(/\/$/, '');
const basePathRaw = process.env.E2E_BASE_PATH || '/workspace';
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + (basePath === '/' ? '' : basePath);
const username = process.env.E2E_USER || 'release-evidence-user';
const password = process.env.E2E_PASSWORD || 'ReleaseEvidencePass123!';
const cookieOut = process.env.E2E_COOKIE_OUT || '/tmp/release-evidence-cookie.txt';
const resultOut = process.env.E2E_BROWSER_RESULT_OUT || '/tmp/release-browser-evidence.json';

const scenarios = [
  { name: 'chromium-desktop', launcher: chromium, context: { viewport: { width: 1366, height: 768 } } },
  { name: 'firefox-desktop', launcher: firefox, context: { viewport: { width: 1366, height: 768 } } },
  { name: 'webkit-desktop', launcher: webkit, context: { viewport: { width: 1366, height: 768 } } },
  {
    name: 'chromium-mobile-390',
    launcher: chromium,
    mobile: true,
    context: { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 },
  },
  {
    name: 'webkit-mobile-412',
    launcher: webkit,
    mobile: true,
    context: { viewport: { width: 412, height: 915 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 },
  },
];

const modules = [
  { path: '/notes/', selector: '.notes' },
  { path: '/tasks/', selector: '.tasks' },
  { path: '/files/', selector: '.file-manager' },
  { path: '/profile/', selector: '.profile' },
];

async function assertDocumentFits(page, label) {
  const layout = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scroll: document.documentElement.scrollWidth,
  }));
  if (layout.scroll > layout.viewport + 2) {
    throw new Error(label + ' overflows document horizontally: ' + layout.scroll + ' > ' + layout.viewport);
  }
}

async function login(page, scenarioName) {
  const response = await page.goto(baseUrl + '/auth/login/', { waitUntil: 'load' });
  if (!response || response.status() !== 200) {
    throw new Error(scenarioName + ': login page returned ' + (response ? response.status() : 'no response'));
  }

  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL(url => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);
  await page.locator('#main-content').waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForLoadState('load');
}

async function assertMobileShell(page, scenarioName) {
  const control = page.locator('.navbar__menu-button[data-sidebar-toggle]');
  const sidebar = page.locator('#workspaceSidebar');
  await control.waitFor({ state: 'visible', timeout: 10000 });
  await sidebar.waitFor({ state: 'attached', timeout: 10000 });

  await page.waitForFunction(() => (
    document.querySelector('.navbar__menu-button[data-sidebar-toggle]')?.getAttribute('aria-expanded') === 'false'
  ));
  await control.click();
  await page.waitForFunction(() => (
    document.getElementById('workspaceSidebar')?.classList.contains('sidebar--open')
    && document.querySelector('.navbar__menu-button[data-sidebar-toggle]')?.getAttribute('aria-expanded') === 'true'
  ));

  const backdropVisible = await page.locator('.sidebar-backdrop').evaluate(el => el.classList.contains('is-visible'));
  if (!backdropVisible) {
    throw new Error(scenarioName + ': mobile sidebar backdrop did not open');
  }

  await page.keyboard.press('Escape');
  await page.waitForFunction(() => (
    !document.getElementById('workspaceSidebar')?.classList.contains('sidebar--open')
    && document.querySelector('.navbar__menu-button[data-sidebar-toggle]')?.getAttribute('aria-expanded') === 'false'
  ));
}

const results = [];
const sessionCookies = [];

for (const scenario of scenarios) {
  const started = performance.now();
  const browser = await scenario.launcher.launch({ headless: true });

  try {
    const context = await browser.newContext(scenario.context);
    const page = await context.newPage();
    const pageErrors = [];
    const failedRequests = [];
    const escapedRequests = [];
    page.on('pageerror', error => pageErrors.push(String(error?.stack || error)));
    page.on('requestfailed', request => {
      failedRequests.push(request.url() + ': ' + (request.failure()?.errorText || 'request failed'));
    });
    page.on('request', request => {
      const url = new URL(request.url());
      if (
        url.origin === origin
        && url.pathname !== basePath
        && !url.pathname.startsWith(basePath + '/')
      ) {
        escapedRequests.push(url.pathname);
      }
    });

    await login(page, scenario.name);
    await assertDocumentFits(page, scenario.name + ' home');

    if (scenario.mobile) {
      await assertMobileShell(page, scenario.name);
    }

    for (const module of modules) {
      const response = await page.goto(baseUrl + module.path, { waitUntil: 'load' });
      if (!response || response.status() !== 200) {
        throw new Error(scenario.name + ': ' + module.path + ' returned ' + (response ? response.status() : 'no response'));
      }
      await page.locator(module.selector).waitFor({ state: 'visible', timeout: 15000 });
      await page.locator('#main-content').waitFor({ state: 'visible', timeout: 15000 });
      await assertDocumentFits(page, scenario.name + ' ' + module.path);
    }

    if (pageErrors.length > 0) {
      throw new Error(scenario.name + ': page error: ' + pageErrors[0]);
    }
    if (failedRequests.length > 0) {
      throw new Error(scenario.name + ': request failed: ' + failedRequests[0]);
    }
    if (escapedRequests.length > 0) {
      throw new Error(
        scenario.name + ': request escaped BASE_PATH: ' + [...new Set(escapedRequests)].join(', ')
      );
    }

    const cookies = await context.cookies(baseUrl + '/');
    const cookieHeader = cookies.map(cookie => cookie.name + '=' + cookie.value).join('; ');
    if (!cookieHeader.includes('PHPSESSID=')) {
      throw new Error(scenario.name + ': authenticated browser context did not produce PHPSESSID');
    }
    sessionCookies.push(cookieHeader);

    results.push({
      scenario: scenario.name,
      mobile: Boolean(scenario.mobile),
      viewport: scenario.context.viewport,
      duration_ms: Math.round(performance.now() - started),
      modules: modules.map(module => module.path),
      status: 'ok',
    });

    await context.close();
  } finally {
    await browser.close();
  }
}

const uniqueSessions = [...new Set(sessionCookies)];
if (uniqueSessions.length !== scenarios.length) {
  throw new Error(
    'Expected ' + scenarios.length + ' independent authenticated sessions, got ' + uniqueSessions.length
  );
}
fs.writeFileSync(cookieOut, uniqueSessions.join('\n') + '\n', { mode: 0o600 });

fs.writeFileSync(
  resultOut,
  JSON.stringify({
    status: 'ok',
    origin,
    base_path: basePath,
    authenticated_sessions: uniqueSessions.length,
    scenarios: results,
  }, null, 2) + '\n',
);

console.log('Cross-browser/mobile evidence: ' + results.length + '/' + scenarios.length + ' scenarios OK');
