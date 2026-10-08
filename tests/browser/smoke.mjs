import { chromium } from 'playwright';

const baseUrl = (process.env.APP_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const username = process.env.CI_ADMIN_USERNAME || 'ci_admin';
const password = process.env.CI_ADMIN_PASSWORD || 'CiInstallerPassword123!';

const screens = [
  { path: '/', label: 'Dashboard' },
  { path: '/settings', label: 'Shop Settings' },
  { path: '/parties', label: 'Parties' },
  { path: '/cylinder-groups', label: 'Cylinder Groups' },
  { path: '/cylinders', label: 'Cylinders' },
  { path: '/rates', label: 'Rates' },
  { path: '/opening-stock', label: 'Opening Stock' },
  { path: '/pos', label: 'Point of Sale' },
  { path: '/sales', label: 'Sales History' },
  { path: '/receipts', label: 'Receipts' },
  { path: '/purchases', label: 'Purchases' },
  { path: '/payments', label: 'Payments' },
  { path: '/cheques', label: 'Cheques' },
  { path: '/expenses', label: 'Expenses' },
  { path: '/users', label: 'Users & Roles' },
  { path: '/reports', label: 'Reports' },
  { path: '/audit', label: 'Audit Log' },
  { path: '/counter', label: 'Cash Counter' },
];

const applicationErrorPattern = /(Application error:|Something went wrong\.|Fatal error|Uncaught (?:Error|Exception)|Internal Server Error)/i;

async function fail(message) {
  throw new Error(message);
}

async function assertNoApplicationError(page, label) {
  const bodyText = await page.locator('body').innerText();
  if (applicationErrorPattern.test(bodyText)) {
    await fail(`${label}: visible application error detected`);
  }
}

async function testDropdowns(page, label) {
  const selects = page.locator('select:visible:not([multiple])');
  const count = await selects.count();

  for (let i = 0; i < count; i += 1) {
    const select = selects.nth(i);
    if (await select.isDisabled()) continue;

    const options = select.locator('option');
    const optionCount = await options.count();
    if (optionCount < 2) continue;

    const before = await select.inputValue();
    const values = await options.evaluateAll((nodes) =>
      nodes.map((node) => node.value).filter((value) => value !== '')
    );
    const alternate = values.find((value) => value !== before);

    if (alternate === undefined) continue;

    await select.selectOption(alternate);
    await page.waitForTimeout(250);

    const after = await select.inputValue();
    if (after !== alternate) {
      await fail(`${label}: dropdown #${i + 1} did not change selection`);
    }

    await assertNoApplicationError(page, label);

    if (page.url().includes('/pos') && select.getAttribute) {
      const id = await select.getAttribute('id');
      if (id === 'transactionType') {
        await select.selectOption({ index: 0 });
        await page.waitForTimeout(250);
        const restored = await select.inputValue();
        if (!restored) {
          await fail(`${label}: Transaction Type dropdown could not restore a valid selection`);
        }
      }
    }
  }
}

async function testTabs(page, label) {
  const tabs = page.locator('[data-lpg-tab-target]:visible, [data-bs-toggle="tab"]:visible');
  const count = await tabs.count();

  for (let i = 0; i < count; i += 1) {
    const tab = tabs.nth(i);
    const target = await tab.getAttribute('data-lpg-tab-target') || await tab.getAttribute('data-bs-target') || await tab.getAttribute('href');
    if (!target || !target.startsWith('#')) continue;

    await tab.click();

    await tab.waitFor({ state: 'visible' });
    await page.waitForFunction(
      ({ tabId, targetSelector }) => {
        const tab = document.getElementById(tabId);
        const pane = document.querySelector(targetSelector);
        return Boolean(tab && pane && tab.classList.contains('active') && pane.classList.contains('active'));
      },
      { tabId: await tab.getAttribute('id'), targetSelector: target }
    );

    if (!(await tab.evaluate((node) => node.classList.contains('active')))) {
      await fail(`${label}: tab #${i + 1} did not become active`);
    }

    const pane = page.locator(target);
    if (await pane.count() === 0) {
      await fail(`${label}: tab #${i + 1} points to missing content ${target}`);
    }

    if (!(await pane.evaluate((node) => node.classList.contains('active')))) {
      await fail(`${label}: tab #${i + 1} content ${target} did not become active`);
    }

    await assertNoApplicationError(page, label);
  }
}

async function main() {
  let browser;
  try {
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
  });
    const page = await context.newPage();
    page.setDefaultTimeout(5000);
    page.setDefaultNavigationTimeout(10000);

  const pageErrors = [];
  const failedRequests = [];

  page.on('pageerror', (error) => {
    pageErrors.push(error.message);
    console.error(`[browser pageerror] ${error.message}`);
  });

  page.on('response', (response) => {
    if (response.status() >= 500 && response.url().startsWith(baseUrl)) {
      failedRequests.push(`${response.status()} ${response.url()}`);
    }
  });

  const loginResponse = await page.goto(`${baseUrl}/login`, {
    waitUntil: 'domcontentloaded',
    timeout: 10000,
  });
  await page.waitForTimeout(250);
  if (!loginResponse || loginResponse.status() !== 200) {
    await fail(`Login screen returned HTTP ${loginResponse?.status() ?? 'no response'}`);
  }

  await page.locator('#username').fill(username);
  await page.locator('#password').fill(password);
  await page.locator('form[action$="/login"] button[type="submit"]').click();
  await page.waitForLoadState('networkidle');

  if (page.url().includes('/login')) {
    await fail('CI administrator login failed');
  }

  if (page.url().includes('/password/change')) {
    await fail('CI administrator still requires a password change');
  }

  await assertNoApplicationError(page, 'Post-login');

  for (const screen of screens) {
    console.log(`CHECK  ${screen.label}`);
    pageErrors.splice(0);
    failedRequests.splice(0);
    const response = await page.goto(`${baseUrl}${screen.path}`, {
      waitUntil: 'domcontentloaded',
      timeout: 10000,
    });
    await page.waitForTimeout(300);

    if (!response || response.status() >= 400) {
      await fail(`${screen.label}: HTTP ${response?.status() ?? 'no response'}`);
    }

    await assertNoApplicationError(page, screen.label);
    if (pageErrors.length > 0) {
      await fail(`${screen.label}: JavaScript error(s) detected: ${pageErrors.join(' | ')}`);
    }
    if (failedRequests.length > 0) {
      await fail(`${screen.label}: server error response(s): ${failedRequests.join(' | ')}`);
    }
    await testDropdowns(page, screen.label);
    await testTabs(page, screen.label);

    if (screen.path === '/pos') {
      const transactionType = page.locator('#transactionType');
      const options = await transactionType.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => node.value).filter(Boolean)
      );
      if (options.length === 0) {
        await fail('POS: Transaction Type dropdown is empty');
      }

      for (const value of options) {
        await transactionType.selectOption(value);
        await page.waitForTimeout(250);
        if ((await transactionType.inputValue()) !== value) {
          await fail(`POS: Transaction Type could not select ${value}`);
        }
        await assertNoApplicationError(page, 'POS transaction type');
      }
    }

    console.log(`PASS  ${screen.label}`);
  }

    console.log(`Browser smoke test passed: ${screens.length} screens checked.`);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
}

main().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
