const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
const report = { pages: [], controls: [], errors: [], failedResponses: [], failedRequests: [] };

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    try {
        for (const role of ['operator', 'admin', 'customer']) {
            const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
            const page = await context.newPage();
            page.setDefaultTimeout(15000);
            page.on('pageerror', error => report.errors.push(error.message));
            page.on('console', message => { if (message.type() === 'error') report.errors.push(message.text()); });
            page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) report.failedResponses.push([response.status(), response.url()]); });
            page.on('requestfailed', request => {
                if (new URL(request.url()).pathname.endsWith('/csv') && request.failure()?.errorText === 'net::ERR_ABORTED') return;
                report.failedRequests.push(request.url());
            });
            await page.goto(`${base}/login`);
            await page.locator('[name=email]').fill(fixture[role]);
            await page.locator('[name=password]').fill(fixture.password);
            await Promise.all([page.waitForURL('**/dashboard'), page.locator('[data-test=login-button]').click()]);
            if (role === 'customer') {
                assert.equal(await page.getByRole('link', { name: '월 사용내역', exact: true }).count(), 0);
                assert.equal(await page.getByRole('link', { name: '월별 내역', exact: true }).count(), 0);
                await page.locator('[data-test=customer-time-overview]').waitFor();
                report.controls.push('ordinary customer retains dashboard summary without detailed usage navigation');
                await context.close();
                continue;
            }
            await page.getByRole('link', { name: '월별 내역', exact: true }).click();
            await page.waitForURL('**/usage?**');
            if (role === 'operator') {
                await page.locator('[name=company_id]').selectOption(String(fixture.company));
                await Promise.all([
                    page.waitForURL(url => url.pathname === '/usage' && url.searchParams.get('company_id') === String(fixture.company)),
                    page.getByRole('button', { name: '조회', exact: true }).click(),
                ]);
                assert.equal(await page.locator('[data-test^=usage-month-row-]').count(), 1);
            } else {
                assert.equal(await page.locator('[name=company_id]').count(), 0);
                assert.equal(await page.getByText(fixture.foreign_company, { exact: true }).count(), 0);
            }
            await page.locator('[name=month]').fill('1000-01');
            await page.getByRole('button', { name: '조회', exact: true }).click();
            await page.locator('[data-test=usage-empty]').waitFor();
            await page.locator('[name=month]').fill(fixture.month);
            await page.getByRole('button', { name: '조회', exact: true }).click();
            await page.locator(`[data-test=usage-month-row-${fixture.month_id}] a`).click();
            await page.waitForURL(`**/usage/${fixture.month_id}`);
            assert.equal(await page.locator('[data-test=usage-total-net_usage]').innerText(), '10분');
            assert.equal(await page.locator('[data-test=usage-total-remaining_reserved]').innerText(), '20분');
            assert.equal(await page.locator('[data-test=usage-total-available]').innerText(), '95분');
            assert.equal(await page.locator('[data-test^=usage-log-]').count(), role === 'operator' ? 3 : 2);
            assert.equal((await page.locator('#desk-main').innerText()).includes('내부 귀책 사유'), role === 'operator');
            await page.setViewportSize({ width: 375, height: 1000 });
            const usageDownloadPromise = page.waitForEvent('download');
            await page.locator('[data-test=usage-csv-download]').click();
            const usageDownload = await usageDownloadPromise;
            assert.equal(await usageDownload.failure(), null);
            assert.equal(usageDownload.suggestedFilename(), `monthly-usage-${fixture.month}-${fixture.month_id}.csv`);
            const usageCsv = fs.readFileSync(await usageDownload.path(), 'utf8');
            assert.ok(usageCsv.startsWith('\uFEFF'));
            assert.ok(usageCsv.includes('사용 취소'));
            assert.equal(usageCsv.includes('내부 귀책 사유'), role === 'operator');
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.getByRole('link', { name: '시간 원장', exact: true }).click();
            await page.locator('[name=type]').selectOption('usage');
            await page.getByRole('button', { name: '조회', exact: true }).click();
            assert.equal(await page.locator('[data-test^=usage-ledger-]').count(), 2);
            assert.equal(await page.locator('[data-test=usage-total-available]').innerText(), '95분');
            const ledgerDownloadPromise = page.waitForEvent('download');
            await page.locator('[data-test=usage-csv-download]').click();
            const ledgerDownload = await ledgerDownloadPromise;
            assert.equal(await ledgerDownload.failure(), null);
            assert.equal(ledgerDownload.suggestedFilename(), `monthly-ledger-${fixture.month}-${fixture.month_id}.csv`);
            const ledgerCsv = fs.readFileSync(await ledgerDownload.path(), 'utf8');
            assert.ok(ledgerCsv.includes('사용 가능 증감 (분)'));
            assert.equal(ledgerCsv.includes('조정 증가'), false);
            assert.equal(ledgerCsv.includes('처리자'), role === 'operator');
            report.controls.push(`${role}: mobile usage and filtered ledger CSV downloads, filename, UTF-8 BOM and internal column privacy`);
            report.controls.push(`${role}: month filter, empty state, contract navigation, cancellation totals and ledger filter`);

            for (const width of [1440, 768, 375]) {
                await page.setViewportSize({ width, height: 1000 });
                for (const theme of ['dark', 'light']) {
                    for (const [name, path] of [
                        ['index', `/usage?month=${fixture.month}`],
                        ['usage', `/usage/${fixture.month_id}`],
                        ['ledger', `/usage/${fixture.month_id}?tab=ledger`],
                    ]) {
                        const response = await page.goto(base + path);
                        assert.equal(response.status(), 200);
                        await page.waitForFunction(() => !!window.Alpine && !!window.Flux);
                        if (await page.evaluate(() => document.documentElement.classList.contains('dark')) !== (theme === 'dark')) {
                            await page.locator('[data-test=desk-theme-toggle]').click();
                        }
                        await page.waitForFunction(value => document.documentElement.classList.contains('dark') === value, theme === 'dark');
                        await page.evaluate(() => document.fonts.ready);
                        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
                        assert.equal(overflow, 0, `${role} ${name} ${width} ${theme}`);
                        if (name === 'index') {
                            assert.equal(await page.locator('[data-test=usage-month-table]').isVisible(), width >= 1280);
                            assert.equal(await page.locator('[data-test=usage-month-mobile]').isVisible(), width < 1280);
                        }
                        if (role === 'admin') {
                            const text = await page.locator('#desk-main').innerText();
                            for (const internal of ['내부 귀책 사유', '내부 조정 사유', '내부 취소 사유', fixture.foreign_company]) assert.equal(text.includes(internal), false);
                        }
                        await page.screenshot({ path: `${out}/monthly-${role}-${name}-${width}-${theme}.png`, fullPage: true });
                        report.pages.push({ role, name, width, theme, overflow });
                    }
                }
            }
            await context.close();
        }
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.failedResponses, []);
        assert.deepEqual(report.failedRequests, []);
        report.passed = true;
        console.log('MONTHLY_USAGE_BROWSER_PASS');
    } finally {
        fs.writeFileSync(`${out}/monthly-usage-report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
