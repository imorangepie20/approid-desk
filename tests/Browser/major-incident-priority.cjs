const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
const report = { pages: [], errors: [], failedResponses: [], failedRequests: [] };

async function login(page, email) {
    await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('[name=email]').fill(email);
    await page.locator('[name=password]').fill(fixture.password);
    await Promise.all([
        page.waitForURL('**/dashboard'),
        page.locator('[data-test="login-button"]').click(),
    ]);
}

async function setTheme(page, theme) {
    await page.waitForFunction(() => !!window.Alpine && !!window.Flux);
    const dark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    if (dark !== (theme === 'dark')) {
        await page.locator('[data-test="desk-theme-toggle"]').click();
    }
}

async function assertNoOverflow(page, label) {
    const overflow = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - innerWidth));
    assert.equal(overflow, 0, label);
    return overflow;
}

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    page.setDefaultTimeout(20000);
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') report.errors.push(message.text()); });
    page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) report.failedResponses.push([response.status(), response.url()]); });
    page.on('requestfailed', request => report.failedRequests.push([request.url(), request.failure()?.errorText]));

    try {
        await login(page, fixture.operator);

        for (const width of [1440, 375]) {
            await page.setViewportSize({ width, height: 1000 });
            for (const theme of ['light', 'dark']) {
                await page.goto(`${base}/dashboard`);
                await setTheme(page, theme);
                const incidentPanel = page.locator('[data-test="major-incidents"]');
                await incidentPanel.getByText('주요 업무 장애', { exact: true }).waitFor();
                assert.equal((await incidentPanel.locator('[data-test="major-incident-count"]').innerText()).trim(), '2건');
                const panelText = await incidentPanel.innerText();
                assert.ok(panelText.indexOf(fixture.oldest.title) < panelText.indexOf(fixture.newer.title), 'oldest incident must appear first');
                assert.ok(!panelText.includes(fixture.regular.title));
                assert.ok(!panelText.includes(fixture.completed.title));
                assert.ok(panelText.includes(fixture.oldest.target));
                assert.ok(panelText.includes(fixture.newer.target));
                assert.ok(panelText.includes('내부 목표 경과'));
                assert.ok(panelText.includes('목표 응답 대기'));
                assert.ok(panelText.includes('계약 보장 아님'));
                assert.ok(!panelText.includes('최초 응답 기한'));
                const dashboardOverflow = await assertNoOverflow(page, `dashboard ${width} ${theme}`);
                await page.screenshot({ path: `${out}/major-incident-dashboard-${width}-${theme}.png`, fullPage: true });
                report.pages.push({ page: 'dashboard', width, theme, overflow: dashboardOverflow });

                await page.goto(`${base}/requests`);
                await setTheme(page, theme);
                const activeList = page.locator(width >= 768 ? '[data-test="request-table"] tbody' : '[data-test="request-card-list"]');
                const defaultLinks = await activeList.locator('a[href*="/requests/"]').evaluateAll(links => links.map(link => link.getAttribute('href')));
                const oldestIndex = defaultLinks.findIndex(link => link.endsWith(`/requests/${fixture.oldest.id}`));
                const regularIndex = defaultLinks.findIndex(link => link.endsWith(`/requests/${fixture.regular.id}`));
                assert.ok(oldestIndex >= 0 && regularIndex >= 0 && oldestIndex < regularIndex, 'major incident must lead request list');
                await page.locator('[name="major_incident"]').check();
                await Promise.all([
                    page.waitForURL(url => url.searchParams.get('major_incident') === '1'),
                    page.getByRole('button', { name: '조회' }).click(),
                ]);
                assert.equal(await page.locator('[name="major_incident"]').isChecked(), true);
                const filteredList = page.locator(width >= 768 ? '[data-test="request-table"] tbody' : '[data-test="request-card-list"]');
                const filteredLinks = await filteredList.locator('a[href*="/requests/"]').evaluateAll(links => links.map(link => link.getAttribute('href')));
                assert.ok(filteredLinks.some(link => link.endsWith(`/requests/${fixture.oldest.id}`)));
                assert.ok(filteredLinks.some(link => link.endsWith(`/requests/${fixture.newer.id}`)));
                assert.ok(!filteredLinks.some(link => link.endsWith(`/requests/${fixture.regular.id}`)));
                assert.ok(!filteredLinks.some(link => link.endsWith(`/requests/${fixture.completed.id}`)));
                const filteredText = await filteredList.innerText();
                assert.ok(filteredText.includes(fixture.oldest.target));
                assert.ok(filteredText.includes(fixture.newer.target));
                assert.ok(filteredText.includes('내부 목표 경과'));
                assert.ok(filteredText.includes('목표 응답 대기'));
                assert.ok(filteredText.includes('계약 보장 아님'));
                assert.ok(!filteredText.includes('최초 응답 기한'));
                const listOverflow = await assertNoOverflow(page, `request list ${width} ${theme}`);
                await page.screenshot({ path: `${out}/major-incident-list-${width}-${theme}.png`, fullPage: true });
                report.pages.push({ page: 'list', width, theme, overflow: listOverflow });

                await page.goto(`${base}/requests/${fixture.oldest.id}`);
                await setTheme(page, theme);
                await page.locator('[data-test="major-incident-notice"]').waitFor();
                await page.getByText('주요 업무 장애로 우선 대응 중입니다.', { exact: true }).waitFor();
                const target = page.locator('[data-test="major-incident-response-target"]');
                await target.getByText(fixture.oldest.target, { exact: true }).waitFor();
                await target.getByText('내부 목표 경과', { exact: true }).waitFor();
                await page.getByText('60분은 운영 우선순위를 위한 내부 목표이며 계약상 응답시간 보장이 아닙니다.', { exact: true }).waitFor();
                const history = page.locator('[data-test="major-incident-history"]');
                await history.getByText('고객 영향 범위 확인', { exact: true }).waitFor();
                await history.locator('[data-test="major-incident-event-list"]').getByText('고객 협의', { exact: true }).waitFor();
                await page.locator('[data-test="major-incident-event-form"]').waitFor();
                const rollbacks = page.locator('[data-test="major-incident-rollbacks"]');
                await rollbacks.getByText('이전 API 이미지', { exact: true }).waitFor();
                await rollbacks.getByText('안정 버전 복구 확인', { exact: true }).waitFor();
                await rollbacks.getByText('성공', { exact: true }).waitFor();
                await page.locator('[data-test="major-incident-rollback-start-form"]').waitFor();
                const detailOverflow = await assertNoOverflow(page, `request detail ${width} ${theme}`);
                await page.screenshot({ path: `${out}/major-incident-detail-${width}-${theme}.png`, fullPage: true });
                report.pages.push({ page: 'detail', width, theme, overflow: detailOverflow });
            }
        }

        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(`${base}/requests/${fixture.oldest.id}`);
        const eventForm = page.locator('[data-test="major-incident-event-form"]');
        await eventForm.locator('[name="event_type"]').selectOption('first_response');
        await eventForm.locator('[name="summary"]').fill('브라우저 최초 응답 기록');
        await eventForm.locator('[name="details"]').fill('현황과 다음 안내 시각을 고객에게 전달했습니다.');
        await Promise.all([
            page.waitForURL(url => url.hash === '#major-incident-history'),
            eventForm.getByRole('button', { name: '이력 등록' }).click(),
        ]);
        await page.getByText('브라우저 최초 응답 기록', { exact: true }).waitFor();
        await page.getByText('내부 목표 이후 응답', { exact: true }).first().waitFor();
        await assertNoOverflow(page, 'recorded first response');

        const rollbackStartForm = page.locator('[data-test="major-incident-rollback-start-form"]');
        await rollbackStartForm.locator('[name="rollback_target"]').fill('브라우저 API 롤백');
        await rollbackStartForm.locator('[name="rollback_plan"]').fill('직전 안정 이미지로 전환합니다.');
        await rollbackStartForm.locator('[name="rollback_verification_plan"]').fill('오류율과 결제 경로를 확인합니다.');
        await rollbackStartForm.locator('[name="rollback_start_confirmed"]').check();
        await Promise.all([
            page.waitForURL(url => url.hash === '#major-incident-rollbacks'),
            rollbackStartForm.getByRole('button', { name: '롤백 시작 기록' }).click(),
        ]);
        await page.getByText('브라우저 API 롤백', { exact: true }).waitFor();
        await page.getByText('실행 중', { exact: true }).waitFor();
        assert.equal(await page.locator('[data-test="major-incident-rollback-start-form"]').count(), 0);

        const rollbackCompleteForm = page.locator('[data-test="major-incident-rollback-complete-form"]');
        await rollbackCompleteForm.locator('[name="rollback_outcome"]').selectOption('succeeded');
        await rollbackCompleteForm.locator('[name="rollback_result_summary"]').fill('브라우저 복구 확인');
        await rollbackCompleteForm.locator('[name="rollback_result_details"]').fill('오류율과 결제 경로가 정상입니다.');
        await rollbackCompleteForm.locator('[name="rollback_result_confirmed"]').check();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            rollbackCompleteForm.getByRole('button', { name: '결과 확정' }).click(),
        ]);
        await page.getByText('브라우저 복구 확인', { exact: true }).waitFor();
        await assertNoOverflow(page, 'completed rollback');

        await page.context().clearCookies();
        await login(page, fixture.customer);
        await page.locator('[data-test="major-incidents"]').getByText(fixture.oldest.title, { exact: true }).waitFor();
        assert.ok(!(await page.locator('[data-test="major-incidents"]').innerText()).includes(fixture.completed.title));
        await page.goto(`${base}/requests/${fixture.oldest.id}`);
        await page.getByText('브라우저 최초 응답 기록', { exact: true }).waitFor();
        await page.getByText('고객 영향 범위 확인', { exact: true }).waitFor();
        await page.getByText('브라우저 API 롤백', { exact: true }).waitFor();
        await page.getByText('브라우저 복구 확인', { exact: true }).waitFor();
        assert.equal(await page.locator('[data-test="major-incident-event-form"]').count(), 0);
        assert.equal(await page.locator('[data-test="major-incident-rollback-start-form"]').count(), 0);
        assert.equal(await page.locator('[data-test="major-incident-rollback-complete-form"]').count(), 0);
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.failedResponses, []);
        assert.deepEqual(report.failedRequests, []);
        report.passed = true;
        console.log('MAJOR_INCIDENT_PRIORITY_BROWSER_PASS');
    } catch (error) {
        report.passed = false;
        report.failure = error.stack;
        await page.screenshot({ path: `${out}/major-incident-failure.png`, fullPage: true }).catch(() => {});
        throw error;
    } finally {
        fs.writeFileSync(`${out}/major-incident-priority.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
