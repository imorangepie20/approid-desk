const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/approid-ui-audit/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixtures = JSON.parse(fs.readFileSync(0, 'utf8'));
const base = 'http://127.0.0.1:8787';
const out = 'storage/app/ui-audit';
fs.mkdirSync(out, { recursive: true });
for (const file of fs.readdirSync(out)) {
    if (/^(?:\d+-.*\.png|collapsed\.png|mobile-menu\.png|request-action-(?:desktop|mobile)\.png|failure\.png|report\.json)$/.test(file)) {
        fs.rmSync(`${out}/${file}`, { force: true });
    }
}
const report = { pages: [], errors: [], failedResponses: [], failedRequests: [], controls: [] };

(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'dark', reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('console', msg => { if (msg.type() === 'error') report.errors.push(msg.text()); });
    page.on('response', response => { if (response.url().startsWith(base) && response.status() >= 400) report.failedResponses.push([response.status(), response.url()]); });
    page.on('requestfailed', request => report.failedRequests.push([request.url(), request.failure()?.errorText]));
    const selector = name => `[data-test="${name}"]`;
    async function login(role) {
        await page.goto(`${base}/login`);
        await page.locator('[name=email]').fill(fixtures[role]);
        await page.locator('[name=password]').fill(fixtures.password);
        await Promise.all([page.waitForURL('**/dashboard'), page.locator(selector('login-button')).click()]);
        await page.locator(selector('desk-header')).waitFor();
    }
    async function audit(path, width, theme) {
        await page.setViewportSize({ width, height: 1000 });
        const response = await page.goto(base + path);
        assert.equal(response.status(), 200, path);
        await page.locator(selector('desk-menu-toggle')).waitFor();
        await page.waitForFunction(() => !!window.Alpine && !!window.Flux);
        if (await page.evaluate(() => document.documentElement.classList.contains('dark')) !== (theme === 'dark')) {
            await page.locator(selector('desk-theme-toggle')).click();
        }
        await page.waitForFunction(value => document.documentElement.classList.contains('dark') === (value === 'dark'), theme);
        await page.evaluate(() => document.fonts.ready);
        const dimensions = await page.evaluate(() => {
            const header = document.querySelector('[data-test=desk-header]').getBoundingClientRect();
            const sidebar = document.querySelector('[data-test=desk-sidebar]').getBoundingClientRect();
            return { headerHeight: header.height, headerX: header.x, sidebarWidth: sidebar.width,
                overflow: document.documentElement.scrollWidth - innerWidth, mainPadding: getComputedStyle(document.querySelector('#desk-main')).padding,
                background: getComputedStyle(document.body).backgroundColor, headerBackground: getComputedStyle(document.querySelector('[data-test=desk-header]')).backgroundColor };
        });
        assert.equal(dimensions.headerHeight, 64, path);
        assert.equal(dimensions.headerX, width >= 1024 ? 256 : 0, path);
        assert.equal(dimensions.overflow, 0, `${path}: overflow at ${width}`);
        assert.equal(dimensions.background, theme === 'dark' ? 'rgb(14, 23, 38)' : 'rgb(240, 242, 245)');
        await page.screenshot({ path: `${out}/${report.pages.length}-${width}-${theme}.png`, fullPage: true });
        report.pages.push({ path, width, theme, ...dimensions });
    }
    try {
        await login('operator');
        const companyTimeOverview = page.locator(selector('company-time-overview'));
        const companyTimeRow = page.locator(selector(`company-time-row-${fixtures.company}`));
        const companyTimeCard = page.locator(selector(`company-time-card-${fixtures.company}`));
        await companyTimeOverview.waitFor();
        assert.equal(await page.locator(selector('company-time-total-provided')).innerText(), '1,200분');
        assert.equal(await page.locator(selector('company-time-total-available')).innerText(), '1,200분');
        assert(await companyTimeRow.isVisible());
        assert.equal(await companyTimeCard.isVisible(), false);
        await page.setViewportSize({ width: 375, height: 812 });
        assert.equal(await companyTimeRow.isVisible(), false);
        assert(await companyTimeCard.isVisible());
        await page.setViewportSize({ width: 1440, height: 1000 });
        report.controls.push('operator company time overview totals and responsive table/card switch');
        for (const [status, id] of [['awaiting_approval', fixtures.request], ['awaiting_review', fixtures.review_request]]) {
            const panel = page.locator(selector(`pending-${status}`));
            await panel.getByText('전체 1건', { exact: false }).waitFor();
            await panel.locator(`a[href="${base}/requests/${id}"]`).click();
            await page.waitForURL(`${base}/requests/${id}`);
            await page.goto(`${base}/dashboard`);
            await page.locator(selector(`pending-${status}`)).getByRole('link', { name: `${status === 'awaiting_approval' ? '승인 대기' : '검수 대기'} 전체 보기` }).click();
            await page.waitForURL(`${base}/requests?status=${status}`);
            await page.goto(`${base}/dashboard`);
        }
        report.controls.push('operator pending panels link to scoped details and status-filtered lists');
        const paths = ['/dashboard', '/companies', `/companies/${fixtures.company}`, '/projects', `/projects/${fixtures.project}`,
            '/requests', '/requests/create', `/requests/${fixtures.request}`, `/requests/${fixtures.draft_request}/estimates/create`,
            `/requests/${fixtures.request}/estimates/${fixtures.estimate}/preview`, '/settings/profile', '/settings/appearance'];
        for (const width of [1440, 768, 375]) {
            for (const theme of ['dark', 'light']) {
                for (const path of paths) await audit(path, width, theme);
            }
        }
        await audit('/dashboard', 1440, 'dark');
        // Start keyboard traversal at a fresh document, not the theme button used by audit().
        await page.goto(`${base}/dashboard`);
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.getAttribute('href')), '#desk-main');
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'desk-main');
        report.controls.push('keyboard skip link focuses main content');
        await page.locator(selector('desk-menu-toggle')).click();
        await page.waitForFunction(() => document.querySelector('#desk-sidebar').getBoundingClientRect().width === 80);
        await page.reload();
        await page.waitForFunction(() => document.querySelector('#desk-sidebar').getBoundingClientRect().width === 80);
        await page.screenshot({ path: `${out}/collapsed.png` });
        await page.locator(selector('desk-menu-toggle')).click();
        await page.locator(selector('desk-theme-toggle')).click();
        await page.waitForFunction(() => !document.documentElement.classList.contains('dark'));
        await page.reload();
        await page.waitForFunction(() => !document.documentElement.classList.contains('dark'));
        report.controls.push('collapse 256/80 and theme persist after reload');
        await page.locator('#desk-search').fill('browser-no-matches');
        await page.locator('#desk-search').press('Enter');
        await page.waitForURL('**/requests?search=browser-no-matches');
        report.controls.push('header search submits to real request list');
        await page.locator(selector('desk-notifications')).click();
        await page.getByText('새 알림이 없습니다.').waitFor();
        await page.getByText('요청 목록 보기', { exact: true }).click();
        await page.waitForURL(`${base}/requests`);
        await page.locator(selector('header-user-menu')).click();
        await page.getByText('프로필 및 설정', { exact: true }).click();
        await page.waitForURL('**/settings/profile');
        await page.locator(selector('desk-header')).waitFor();
        await page.locator(selector('desk-theme-toggle')).click();
        report.controls.push('notifications and profile menu; controls work after Livewire navigation');
        await page.setViewportSize({ width: 375, height: 812 });
        await page.locator(selector('desk-menu-toggle')).click();
        await page.waitForFunction(() => document.querySelector('#desk-sidebar').getBoundingClientRect().x === 0);
        await page.screenshot({ path: `${out}/mobile-menu.png` });
        await page.keyboard.press('Tab');
        assert(await page.evaluate(() => document.querySelector('#desk-sidebar').contains(document.activeElement)));
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => document.querySelector('#desk-sidebar').getBoundingClientRect().right <= 0);
        await page.locator(selector('desk-menu-toggle')).click();
        await page.locator('#desk-sidebar').getByRole('link', { name: '요청', exact: true }).click();
        await page.waitForURL('**/requests');
        await page.waitForFunction(() => document.querySelector('#desk-sidebar').getBoundingClientRect().right <= 0);
        report.controls.push('mobile drawer focus, Escape and close after navigation');
        await page.locator(selector('header-user-menu')).click();
        await page.locator(selector('logout-button')).click();
        await page.waitForURL(url => !url.pathname.startsWith('/requests'));
        report.controls.push('real logout');
        await login('admin');
        assert.equal(await page.locator('#desk-sidebar').getByText('고객사 관리', { exact: true }).count(), 0);
        assert.equal(await page.locator('#desk-sidebar').getByText('자사 사용자', { exact: true }).count(), 1);
        await page.locator(selector('customer-time-overview')).waitFor();
        assert.equal(await page.locator(selector('customer-time-total-provided')).innerText(), '1,200분');
        assert.equal(await page.locator(selector('customer-time-total-available')).innerText(), '1,200분');
        const customerTimeRow = page.locator(selector(`customer-time-row-${fixtures.contract}`));
        const customerTimeCard = page.locator(selector(`customer-time-card-${fixtures.contract}`));
        assert.equal(await customerTimeRow.isVisible(), false);
        assert(await customerTimeCard.isVisible());
        await page.setViewportSize({ width: 1440, height: 1000 });
        assert(await customerTimeRow.isVisible());
        assert.equal(await customerTimeCard.isVisible(), false);
        report.controls.push('customer admin own-company time totals and responsive table/card switch');
        for (const width of [1440, 768, 375]) for (const theme of ['dark', 'light']) {
            await audit('/dashboard', width, theme);
            await audit(`/requests/${fixtures.request}/estimates/${fixtures.estimate}/decision`, width, theme);
        }
        const revision = page.locator(`form[action$="/revision"]`);
        await revision.locator('[name=reason]').fill('브라우저에서 범위 수정 요청');
        await Promise.all([page.waitForURL(`${base}/requests/${fixtures.request}`), revision.getByRole('button', { name: '수정 요청 보내기' }).click()]);
        assert.equal(await page.locator(selector('decide-estimate')).count(), 0);
        assert.equal(await page.locator(selector('write-estimate')).count(), 0);
        report.controls.push('customer revision hides stale approval actions');
        await page.locator(selector('header-user-menu')).click();
        await page.locator(selector('logout-button')).click();
        await page.waitForURL(url => !url.pathname.includes('/decision'));
        await login('customer');
        assert.equal(await page.locator('#desk-sidebar').getByText('자사 사용자', { exact: true }).count(), 0);
        await page.locator(selector('customer-time-overview')).waitFor();
        assert.equal(await page.locator(selector('customer-time-total-provided')).innerText(), '1,200분');
        assert(await page.locator(selector(`customer-time-card-${fixtures.contract}`)).isVisible());
        await audit(`/requests/${fixtures.request}`, 375, 'dark');
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        assert(await page.evaluate(() => window.scrollY > 0));
        assert.equal(await page.locator(selector('desk-header')).evaluate(el => el.getBoundingClientRect().top), 0);
        report.controls.push('header remains visible when scrolling');
        report.controls.push('customer admin and ordinary user menu and time scopes');
        await page.locator(selector('header-user-menu')).click();
        await page.locator(selector('logout-button')).click();
        await page.waitForURL(url => !url.pathname.startsWith('/requests'));
        await login('operator');
        await page.goto(`${base}/requests/${fixtures.draft_request}`);
        assert.equal(await page.locator(selector('write-estimate')).count(), 1);
        const hold = page.locator(selector('transition-on_hold'));
        await hold.locator('summary').click();
        await hold.locator('[name=reason]').fill('브라우저에서 보류 확인');
        await hold.locator('[name=confirmed]').check();
        await page.screenshot({ path: `${out}/request-action-mobile.png` });
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), hold.getByRole('button', { name: '변경 확정' }).click()]);
        assert.equal(await page.locator(selector('write-estimate')).count(), 0);
        const resume = page.locator(selector('transition-received'));
        await resume.locator('summary').click();
        await resume.locator('[name=reason]').fill('브라우저에서 접수 복귀 확인');
        await resume.locator('[name=confirmed]').check();
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), resume.getByRole('button', { name: '변경 확정' }).click()]);
        assert.equal(await page.locator(selector('write-estimate')).count(), 1);
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.screenshot({ path: `${out}/request-action-desktop.png`, fullPage: true });
        report.controls.push('operator confirmed hold/resume changes actions on mobile and desktop');
        await page.goto(`${base}/dashboard`);
        await page.locator(selector('empty-awaiting_approval')).waitFor();
        assert.equal(await page.locator(selector(`pending-request-${fixtures.review_request}`)).count(), 1);
        report.controls.push('dashboard removes revised estimate from approval queue');
        assert.deepEqual(report.errors, []);
        assert.deepEqual(report.failedResponses, []);
        assert.deepEqual(report.failedRequests, []);
        report.passed = true;
        console.log(`HUD_BROWSER_PASS ${report.pages.length} page/viewport/theme checks`);
    } catch (error) {
        report.passed = false;
        report.failure = error.stack;
        await page.screenshot({ path: `${out}/failure.png`, fullPage: true }).catch(() => {});
        console.error(error.stack, JSON.stringify(report.errors));
        process.exitCode = 1;
    } finally {
        fs.writeFileSync(`${out}/report.json`, JSON.stringify(report, null, 2));
        await browser.close();
    }
})();
