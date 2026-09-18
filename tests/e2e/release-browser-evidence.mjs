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
  const response = await page.goto(baseUrl + '/auth/login/', { waitUntil: 'domcontentloaded' });
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
}

async function assertMobileShell(page, scenarioName) {
  const control = page.locator('#sidebarControl');
  const sidebar = page.locator('#workspaceSidebar');
  await control.waitFor({ state: 'visible', timeout: 10000 });
  await sidebar.waitFor({ state: 'attached', timeout: 10000 });

  await page.waitForFunction(() => document.getElementById('sidebarControl')?.getAttribute('aria-expanded') === 'false');
  await control.click();
  await page.waitForFunction(() => (
    document.getElementById('workspaceSidebar')?.classList.contains('sidebar--open')
    && document.getElementById('sidebarControl')?.getAttribute('aria-expanded') === 'true'
  ));

  const backdropVisible = await page.locator('.sidebar-backdrop').evaluate(el => el.classList.contains('is-visible'));
  if (!backdropVisible) {
    throw new Error(scenarioName + ': mobile sidebar backdrop did not open');
  }

  await page.keyboard.press('Escape');
  await page.waitForFunction(() => (
    !document.getElementById('workspaceSidebar')?.classList.contains('sidebar--open')
    && document.getElementById('sidebarControl')?.getAttribute('aria-expanded') === 'false'
  ));
}

const results = [];
let exportedCookie = false;

for (const scenario of scenarios) {
  const started = performance.now();
  const browser = await scenario.launcher.launch({ headless: true });

  try {
    const context = await browser.newContext(scenario.context);
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(String(error?.stack || error)));

    await login(page, scenario.name);
    await assertDocumentFits(page, scenario.name + ' home');

    if (scenario.mobile) {
      await assertMobileShell(page, scenario.name);
    }

    for (const module of modules) {
      const response = await page.goto(baseUrl + module.path, { waitUntil: 'domcontentloaded' });
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

    if (!exportedCookie) {
      const cookies = await context.cookies(origin);
      const cookieHeader = cookies.map(cookie => cookie.name + '=' + cookie.value).join('; ');
      if (!cookieHeader.includes('PHPSESSID=')) {
        throw new Error('Authenticated browser context did not produce PHPSESSID');
      }
      fs.writeFileSync(cookieOut, cookieHeader, { mode: 0o600 });
      exportedCookie = true;
    }

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

if (!exportedCookie || !fs.existsSync(cookieOut)) {
  throw new Error('Authenticated cookie evidence was not exported');
}

fs.writeFileSync(
  resultOut,
  JSON.stringify({ status: 'ok', origin, base_path: basePath, scenarios: results }, null, 2) + '\n',
);

console.log('Cross-browser/mobile evidence: ' + results.length + '/' + scenarios.length + ' scenarios OK');
