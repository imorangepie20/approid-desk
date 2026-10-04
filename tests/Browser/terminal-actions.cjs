const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixtures = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
for (const file of ['terminal-failure.png', 'terminal-report.json']) {
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
    async function transition(request, status, reason = '') {
        const target = `${base}/requests/${request}`;
        await page.goto(target, { waitUntil: 'domcontentloaded' });
        const action = page.locator(selector(`transition-${status}`));
        await action.locator('summary').click();
        if (reason) await action.locator('[name=reason]').fill(reason);
        await action.locator('[name=confirmed]').check();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            action.getByRole('button', { name: '변경 확정' }).click(),
        ]);
    }
    try {
        await login(fixtures.operator);
        await transition(fixtures.cancel_request, 'cancelled', '브라우저에서 승인 요청 취소');
        await transition(fixtures.complete_request, 'in_progress');
        await transition(fixtures.complete_request, 'awaiting_review');
        report.controls.push('operator cancellation and review submission');
        await page.locator(selector('header-user-menu')).click();
        await Promise.all([page.waitForURL(url => !url.pathname.startsWith('/requests')),
            page.locator(selector('logout-button')).click()]);
        await login(fixtures.admin);
        await transition(fixtures.complete_request, 'completed');
        report.controls.push('customer admin completion');
        await page.locator(selector('header-user-menu')).click();
        await Promise.all([page.waitForURL(url => !url.pathname.startsWith('/requests')),
            page.locator(selector('logout-button')).click()]);
        await login(fixtures.operator);
        await page.goto(`${base}/requests/${fixtures.complete_request}`, { waitUntil: 'domcontentloaded' });
        await page.getByText('무상 재작업 시작', { exact: true }).waitFor();
        await page.screenshot({ path: `${out}/free-rework-action.png`, fullPage: true });
        await transition(fixtures.complete_request, 'in_progress', '브라우저 무상 재작업 귀책 사유');
        report.controls.push('operator explicitly resumes completed request as free rework');
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.failedResponses, []);
        assert.deepEqual(report.failedRequests, []);
        report.passed = true;
        console.log('TERMINAL_BROWSER_PASS');
    } catch (error) {
        report.passed = false;
        report.failure = error.stack;
        await page.screenshot({ path: `${out}/terminal-failure.png`, fullPage: true }).catch(() => {});
        throw error;
    } finally {
        fs.writeFileSync(`${out}/terminal-report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
