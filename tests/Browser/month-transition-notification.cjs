const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixtures = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
for (const file of ['month-transition-failure.png', 'month-transition-report.json']) {
    fs.rmSync(`${out}/${file}`, { force: true });
}
const report = { controls: [], errors: [], failedResponses: [], failedRequests: [] };

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'dark', reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') report.errors.push(message.text()); });
    page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) report.failedResponses.push([response.status(), response.url()]); });
    page.on('requestfailed', request => report.failedRequests.push([request.url(), request.failure()?.errorText]));
    const selector = name => `[data-test="${name}"]`;
    try {
        await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
        await page.locator('[name=email]').fill(fixtures.operator);
        await page.locator('[name=password]').fill(fixtures.password);
        await Promise.all([page.waitForURL('**/dashboard'), page.locator(selector('login-button')).click()]);
        await page.locator(selector('desk-notifications')).click();
        await page.getByText(fixtures.title, { exact: true }).waitFor();
        await page.getByText('남은 예약 60분', { exact: false }).waitFor();
        await page.screenshot({ path: `${out}/month-transition-notification.png` });
        await Promise.all([
            page.waitForURL(`**/requests/${fixtures.request}`),
            page.locator(selector('month-transition-notification')).click(),
        ]);
        assert.equal(await page.locator(selector('desk-notifications')).getAttribute('aria-label'), '읽지 않은 알림 0개');
        report.controls.push('month transition notification is visible, opens the request, and becomes read');
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.failedResponses, []);
        assert.deepEqual(report.failedRequests, []);
        report.passed = true;
        console.log('MONTH_TRANSITION_BROWSER_PASS');
    } catch (error) {
        report.passed = false;
        report.failure = error.stack;
        await page.screenshot({ path: `${out}/month-transition-failure.png`, fullPage: true }).catch(() => {});
        throw error;
    } finally {
        fs.writeFileSync(`${out}/month-transition-report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
