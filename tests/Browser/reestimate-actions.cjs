const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixtures = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
for (const file of ['reestimate-failure.png', 'reestimate-report.json']) {
    fs.rmSync(`${out}/${file}`, { force: true });
}
const report = { controls: [], errors: [], failedResponses: [], failedRequests: [] };

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'dark', reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') report.errors.push(message.text()); });
    page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) report.failedResponses.push([response.status(), response.url()]); });
    page.on('requestfailed', request => report.failedRequests.push([request.url(), request.failure()?.errorText]));
    const selector = name => `[data-test="${name}"]`;
    async function login(email) {
        await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
        await page.locator('[name=email]').fill(email);
        await page.locator('[name=password]').fill(fixtures.password);
        await Promise.all([page.waitForURL('**/dashboard'), page.locator(selector('login-button')).click()]);
    }
    async function logout() {
        await page.locator(selector('header-user-menu')).click();
        await Promise.all([page.waitForURL(url => !url.pathname.startsWith('/requests')),
            page.locator(selector('logout-button')).click()]);
    }
    try {
        await login(fixtures.operator);
        await page.goto(`${base}/requests/${fixtures.request}`, { waitUntil: 'domcontentloaded' });
        await page.locator(selector('write-estimate')).click();
        await page.waitForURL(`**/requests/${fixtures.request}/estimates/create`);
        await page.locator('[name=estimated_minutes]').fill('90');
        await page.locator('[name=rationale]').fill('브라우저 재견적 산정 근거');
        await page.locator('[name=included_scope]').fill('브라우저 추가 개발 범위');
        await page.locator('[name=excluded_scope]').fill('브라우저 재견적 제외 범위');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.getByRole('button', { name: '초안 저장 후 미리보기' }).click(),
        ]);
        await page.locator('[name=confirmed]').check();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.getByRole('button', { name: '견적 제출' }).click(),
        ]);
        assert.equal(await page.locator(selector('decide-estimate')).count(), 0);
        report.controls.push('operator creates and submits a replacement estimate');
        await logout();

        await login(fixtures.admin);
        await page.goto(`${base}/requests/${fixtures.request}`, { waitUntil: 'domcontentloaded' });
        await page.locator(selector('decide-estimate')).click();
        await page.getByText('기존 견적의 남은 예약을 해제', { exact: false }).waitFor();
        await page.locator('[name=confirmed]').check();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.getByRole('button', { name: '견적 승인' }).click(),
        ]);
        await page.getByText('견적 승인이 처리되었습니다.', { exact: false }).waitFor();
        report.controls.push('customer admin approves and replaces the reservation');
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.failedResponses, []);
        assert.deepEqual(report.failedRequests, []);
        report.passed = true;
        console.log('REESTIMATE_BROWSER_PASS');
    } catch (error) {
        report.passed = false;
        report.failure = error.stack;
        await page.screenshot({ path: `${out}/reestimate-failure.png`, fullPage: true }).catch(() => {});
        throw error;
    } finally {
        fs.writeFileSync(`${out}/reestimate-report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
