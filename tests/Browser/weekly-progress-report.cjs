const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
const report = { pages: [], errors: [], failedResponses: [], failedRequests: [] };

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    page.setDefaultTimeout(20000);
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') report.errors.push(message.text()); });
    page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) report.failedResponses.push([response.status(), response.url()]); });
    page.on('requestfailed', request => report.failedRequests.push([request.url(), request.failure()?.errorText]));
    const selector = name => `[data-test="${name}"]`;
    try {
        await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
        await page.locator('[name=email]').fill(fixture.admin);
        await page.locator('[name=password]').fill(fixture.password);
        await Promise.all([page.waitForURL('**/dashboard'), page.locator(selector('login-button')).click()]);

        for (const width of [1440, 375]) {
            await page.setViewportSize({ width, height: 900 });
            for (const theme of ['light', 'dark']) {
                await page.goto(`${base}/dashboard`);
                await page.waitForFunction(() => !!window.Alpine && !!window.Flux);
                if (await page.evaluate(() => document.documentElement.classList.contains('dark')) !== (theme === 'dark')) {
                    await page.locator(selector('desk-theme-toggle')).click();
                }
                await page.locator(selector('desk-notifications')).click();
                await page.getByText(`${fixture.company} 주간 진행 보고 2026.09.28-10.04`, { exact: true }).waitFor();
                await page.getByText('2026-09-28 ~ 2026-10-04 · 열린 요청 1건 · 완료 0건', { exact: true }).waitFor();
                const overflow = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - innerWidth));
                assert.equal(overflow, 0, `weekly report ${width} ${theme}`);
                await page.screenshot({ path: `${out}/weekly-progress-${width}-${theme}.png` });
                report.pages.push({ width, theme, overflow });
            }
        }

        await Promise.all([
            page.waitForResponse(response => response.url().includes('/notifications/') && response.request().method() === 'POST'),
            page.locator(selector('month-transition-notification')).click(),
        ]);
        await page.waitForURL('**/dashboard');
        assert.equal(await page.locator(selector('desk-notifications')).getAttribute('aria-label'), '읽지 않은 알림 0개');
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.failedResponses, []);
        assert.deepEqual(report.failedRequests, []);
        report.passed = true;
        console.log('WEEKLY_PROGRESS_REPORT_BROWSER_PASS');
    } catch (error) {
        report.passed = false;
        report.failure = error.stack;
        await page.screenshot({ path: `${out}/weekly-progress-failure.png`, fullPage: true }).catch(() => {});
        throw error;
    } finally {
        fs.writeFileSync(`${out}/weekly-progress-report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
