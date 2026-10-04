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
        await page.goto(`${base}/usage/${fixture.month}`);
        await page.getByRole('link', { name: '월 마감·조정', exact: true }).click();
        await page.locator('[name=confirmed]').check();
        await page.getByRole('button', { name: '월 마감 확정', exact: true }).click();
        await page.getByRole('status').filter({ hasText: '월 마감을 확인했습니다.' }).waitFor();
        await page.getByRole('link', { name: '원장 조회', exact: true }).click();
        await page.getByRole('link', { name: '시간 조정', exact: true }).click();
        await page.setViewportSize({ width: 375, height: 1000 });
        await page.locator('[name=type]').selectOption('adjust_decrease');
        await page.locator('[name=minutes]').fill('101');
        await page.locator('[name=reason]').fill('브라우저 조정 검증');
        const key = await page.locator('[name=idempotency_key]').inputValue();
        await page.locator('[name=confirmed]').check();
        await page.getByRole('button', { name: '조정 승인', exact: true }).click();
        await page.locator('ul[role=alert]').waitFor();
        assert.equal(await page.locator('[name=idempotency_key]').inputValue(), key);
        assert.equal(await page.locator('[name=reason]').inputValue(), '브라우저 조정 검증');
        await page.locator('[name=minutes]').fill('30');
        await page.locator('[name=confirmed]').check();
        await page.getByRole('button', { name: '조정 승인', exact: true }).click();
        await page.getByRole('status').filter({ hasText: '시간 조정을 확인했습니다.' }).waitFor();
        assert.equal(await page.locator('[data-test=manage-available]').innerText(), '70분');
        assert.ok((await page.locator('[data-test=closure-totals]').innerText()).includes('100분'));
        for (const width of [1440, 768, 375]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const theme of ['dark', 'light']) {
                for (const [name, path] of [['manage', `/usage/${fixture.month}/manage`], ['adjust', `/usage/${fixture.month}/adjustments/${fixture.entry}/create`]]) {
                    await page.goto(base + path);
                    await page.waitForFunction(() => !!window.Alpine && !!window.Flux);
                    if (await page.evaluate(() => document.documentElement.classList.contains('dark')) !== (theme === 'dark')) {
                        await page.locator('[data-test=desk-theme-toggle]').click();
                    }
                    await page.waitForFunction(value => document.documentElement.classList.contains('dark') === value, theme === 'dark');
                    await page.evaluate(() => document.fonts.ready);
                    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
                    assert.equal(overflow, 0, `${name} ${width} ${theme}`);
                    await page.screenshot({ path: `${out}/month-management-${name}-${width}-${theme}.png`, fullPage: true });
                    report.pages.push({ name, width, theme, overflow });
                }
            }
        }
        assert.deepEqual(report.errors, []);
        report.passed = true;
        console.log('MONTH_MANAGEMENT_BROWSER_PASS');
    } finally {
        fs.writeFileSync(`${out}/month-management-report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
