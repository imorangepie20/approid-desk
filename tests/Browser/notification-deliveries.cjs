const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
const report = { pages: [], errors: [] };

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
        page.on('pageerror', error => report.errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') report.errors.push(message.text()); });
        page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) report.errors.push(`${response.status()} ${response.url()}`); });
        await page.goto(`${base}/login`);
        await page.locator('[name=email]').fill(fixture.operator);
        await page.locator('[name=password]').fill(fixture.password);
        await Promise.all([page.waitForURL('**/dashboard'), page.locator('[data-test=login-button]').click()]);

        for (const width of [1440, 768, 375]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const theme of ['dark', 'light']) {
                await page.goto(`${base}/notification-deliveries?status=failed`);
                await page.waitForFunction(() => !!window.Alpine && !!window.Flux);
                if (await page.evaluate(() => document.documentElement.classList.contains('dark')) !== (theme === 'dark')) {
                    await page.locator('[data-test=desk-theme-toggle]').click();
                }
                await page.waitForFunction(value => document.documentElement.classList.contains('dark') === value, theme === 'dark');
                await page.evaluate(() => document.fonts.ready);
                await page.locator(`[data-test="notification-delivery-${fixture.delivery}"]`).filter({ visible: true }).waitFor();
                assert.ok((await page.locator('body').innerText()).includes('메일 전송 오류'));
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
                assert.equal(overflow, 0, `notification deliveries ${width} ${theme}`);
                await page.screenshot({ path: `${out}/notification-deliveries-${width}-${theme}.png`, fullPage: true });
                report.pages.push({ width, theme, overflow });
            }
        }

        await page.getByRole('button', { name: '재시도', exact: true }).click();
        await page.getByRole('status').filter({ hasText: '알림 재시도를 큐에 등록했습니다.' }).waitFor();
        await page.locator('[data-test=notification-delivery-empty]').waitFor();
        await page.goto(`${base}/notification-deliveries?status=sent&channel=mail`);
        await page.locator(`[data-test="notification-delivery-${fixture.delivery}"]`).filter({ visible: true }).waitFor();
        assert.ok((await page.locator('body').innerText()).includes('발송 완료'));
        assert.deepEqual(report.errors, []);
        report.passed = true;
        console.log('NOTIFICATION_DELIVERY_BROWSER_PASS');
    } finally {
        fs.writeFileSync(`${out}/notification-deliveries-report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
