const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) errors.push(`${response.status()} ${response.url()}`); });
    page.on('requestfailed', request => errors.push(request.url()));
    const fits = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    try {
        await page.goto(`${base}/login`);
        await page.locator('[name=email]').fill(fixture.operator);
        await page.locator('[name=password]').fill(fixture.password);
        await Promise.all([page.waitForURL('**/dashboard'), page.locator('[data-test=login-button]').click()]);
        await page.goto(`${base}/requests/${fixture.request}`);
        await page.getByRole('link', { name: '작업시간', exact: true }).click();
        for (const billable of [true, false]) {
            await page.getByRole('link', { name: '작업시간 입력', exact: true }).click();
            await page.locator('[name=minutes]').fill('30');
            await page.locator('[name=description]').fill(billable ? '브라우저 차감 작업' : '브라우저 비차감 작업');
            await page.locator('[name=is_billable]').selectOption(billable ? '1' : '0');
            if (!billable) await page.locator('[name=non_billable_reason]').fill('내부 수정');
            await fits();
            await page.screenshot({ path: `${out}/work-log-form-${billable ? 'desktop' : 'mobile'}.png`, fullPage: true });
            await page.getByRole('button', { name: '초안 저장', exact: true }).click();
            if (billable) {
                await page.getByRole('link', { name: '수정', exact: true }).click();
                await page.locator('[name=minutes]').fill('35');
                await page.getByRole('button', { name: '초안 저장', exact: true }).click();
            }
            await page.locator('[name=confirmed]').check();
            await page.getByRole('button', { name: billable ? '차감 확정' : '비차감 확정', exact: true }).click();
            await page.getByRole('status').filter({ hasText: '작업시간 확정을 확인했습니다.' }).waitFor();
            assert.equal(await page.locator('[name=confirmed]').count(), 0);
            await fits();
            await page.screenshot({ path: `${out}/work-log-list-${billable ? 'desktop' : 'mobile'}.png`, fullPage: true });
            await page.setViewportSize({ width: 375, height: 812 });
        }
        assert.deepEqual(errors, []);
        console.log('WORK_LOG_BROWSER_PASS');
    } finally {
        fs.writeFileSync(`${out}/work-log-report.json`, JSON.stringify({ errors }, null, 2));
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
