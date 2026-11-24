const { chromium } = require('playwright');
const fs = require('node:fs');

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const host = 'http://canadamb.localhost:8000';
    const check = (condition, message) => { if (!condition) throw new Error(message); };
    try {
        fs.mkdirSync('test-results', { recursive: true });
        // Normal central sign-in against a disposable local SQLite account.
        // No test authentication endpoints are shipped to production.
        await page.goto(host + '/login');
        await page.getByLabel('Email').fill('browser-notifications@example.test');
        await page.getByLabel('Password').fill('browser-qa-password-only');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL(url => !url.pathname.startsWith('/login'));

        for (const width of [320, 390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(host + '/account/notifications');
            check(await page.getByRole('heading', { name: 'Notifications', exact: true }).isVisible(),
                'Signed-in notifications hub missing: ' + width);
            check(await page.getByText('1 unread reply notification').isVisible(),
                'Unread notification count incorrect: ' + width);
            check(await page.getByText('Community garden neighbours').isVisible(),
                'Recent approved discussion reply missing: ' + width);
            check(!(await page.getByText('Private unpublished reply that browser QA must never show.').count()),
                'Private pending content leaked to notification hub');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Authenticated notifications hub overflows at ' + width);
            if (width === 390 || width === 1440) {
                await page.screenshot({
                    path: 'test-results/auth-notifications-hub-' + width + '.png',
                    fullPage: true, animations: 'disabled'
                });
            }

            await page.goto(host + '/account/community-notifications');
            check(await page.getByRole('heading', { name: 'Discussion notifications' }).isVisible(),
                'Full discussion notifications page missing at ' + width);
            check(await page.locator('.discussion-notification-row').count() === 2,
                'Pending replies visible or approved replies missing at ' + width);
            check(await page.locator('.discussion-notification-row.is-unread').count() === 1,
                'Read/unread notification styling incorrect at ' + width);
            check(await page.locator('form[action="/account/community-notifications/read-all"]').count() === 1,
                'Bulk read control missing at ' + width);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Full discussion notifications overflow at ' + width);
            if (width === 390 || width === 1440) {
                await page.screenshot({
                    path: 'test-results/auth-discussion-notifications-' + width + '.png',
                    fullPage: true, animations: 'disabled'
                });
            }

            await page.goto(host + '/account/community-notifications?view=unread');
            check(await page.locator('.discussion-notification-row').count() === 1,
                'Unread-only filter did not remove read notifications at ' + width);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Unread notifications overflow at ' + width);

            await page.goto(host + '/account/followed-discussions');
            check(await page.getByRole('heading', { name: 'Following discussions' }).isVisible(),
                'Following discussions page missing at ' + width);
            check(await page.getByRole('link', { name: 'Community garden neighbours' }).isVisible(),
                'Followed approved thread missing at ' + width);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Following discussions overflow at ' + width);
            if (width === 390 || width === 1440) {
                await page.screenshot({
                    path: 'test-results/auth-followed-discussions-' + width + '.png',
                    fullPage: true, animations: 'disabled'
                });
            }
        }

        console.log('PASS: authenticated notification hub, unread filters and followed conversations at five widths');
    } catch (error) {
        try {
            await page.screenshot({ path: 'test-results/auth-qa-failure.png', fullPage: true });
        } catch {}
        console.error(error?.stack || error);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
