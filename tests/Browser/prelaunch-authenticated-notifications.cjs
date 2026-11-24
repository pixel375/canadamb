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
            check(await page.locator('.activity-panel .item-row').filter({ hasText: 'Community garden neighbours' }).count() > 0,
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

        // Cross-section search uses real GET navigation and preserves the
        // location despite Jobs using a community name rather than a slug.
        for (const width of [320, 390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(host + '/marketplace?q=community%20garden&location=brandon');
            const nav = page.getByRole('navigation', { name: 'Search other sections' });
            check(await nav.isVisible(), 'Search navigation missing at ' + width);
            check(await nav.locator('[aria-current="page"]').innerText() === 'Marketplace',
                'Marketplace search tab not active');
            const jobs = nav.getByRole('link', { name: 'Jobs', exact: true });
            const jobUrl = new URL(await jobs.getAttribute('href'), host);
            check(jobUrl.searchParams.get('q') === 'community garden' &&
                jobUrl.searchParams.get('location') === 'Brandon',
                'Search keyword or community lost when switching to Jobs');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Search results navigation overflows at ' + width);
            await jobs.click();
            await page.waitForURL(url => url.pathname === '/jobs');
            check(await nav.locator('[aria-current="page"]').innerText() === 'Jobs',
                'Jobs search tab not active');
            const discussionUrl = new URL(await nav.getByRole('link', { name: 'Discussions', exact: true }).getAttribute('href'), host);
            check(discussionUrl.searchParams.get('q') === 'community garden' &&
                discussionUrl.searchParams.get('location') === 'brandon',
                'Jobs-to-discussions search lost the original community slug');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Jobs search results navigation overflows at ' + width);
        }
        await page.goto(host + '/marketplace');
        check(await page.getByRole('navigation', { name: 'Search other sections' }).count() === 0,
            'Search navigation should not appear on category discovery');
        console.log('PASS: cross-section search query and community retention at five widths');
        const missing = 'zzcanadambnoresults2026zz';
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const [section, location, emptyTitle] of [
                ['marketplace', 'brandon', 'No listings match your search'],
                ['businesses', 'brandon', 'No matching businesses.'],
                ['community', 'brandon', 'No discussions match these filters'],
                ['jobs', 'Brandon', 'No active jobs match these filters.'],
            ]) {
                await page.goto(host + '/' + section + '?' + new URLSearchParams({ q: missing, location }));
                check(await page.getByRole('heading', { name: emptyTitle, exact: true }).isVisible(),
                    'Missing useful empty search state: ' + section + ' at ' + width);
                const clear = new URL(await page.getByRole('link', { name: 'Clear search filters', exact: true }).getAttribute('href'), host);
                const wide = new URL(await page.getByRole('link', { name: 'Search all Manitoba', exact: true }).getAttribute('href'), host);
                check(clear.searchParams.get('location') === location && !clear.searchParams.has('q'),
                    'Reset lost community or retained query: ' + section);
                check(!wide.searchParams.has('location') && wide.searchParams.get('q') === missing,
                    'Broader search lost query or retained location: ' + section);
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Empty search page overflows: ' + section + ' at ' + width);
            }
        }
        console.log('PASS: four scoped empty-result recovery paths on mobile and desktop');
        await page.setViewportSize({ width: 1440, height: 900 });
        for (const [section, location] of [
            ['', 'brandon'], ['marketplace', 'brandon'], ['businesses', 'brandon'],
            ['community', 'brandon'], ['jobs', 'Brandon'], ['news', 'brandon'],
            ['events', 'brandon'], ['business-updates', 'brandon'], ['real-estate', 'brandon'],
        ]) {
            await page.goto(host + '/' + section + '?' + new URLSearchParams({ location }));
            const nav = page.getByRole('navigation', { name: 'Primary navigation' });
            const home = new URL(await nav.getByRole('link', { name: 'Home', exact: true }).getAttribute('href'), host);
            const market = new URL(await nav.getByRole('link', { name: 'Marketplace', exact: true }).getAttribute('href'), host);
            check(home.searchParams.get('location') === 'brandon' &&
                market.searchParams.get('location') === 'brandon',
                'Primary navigation lost browsing location on ' + section);
            const mobile = page.locator('.mobile-tab-bar a').first();
            check(new URL(await mobile.getAttribute('href'), host).searchParams.get('location') === 'brandon',
                'Mobile navigation lost community on ' + section);
        }
        await page.goto(host + '/');
        check(!(new URL(await page.getByRole('navigation', { name: 'Primary navigation' }).getByRole('link', { name: 'Marketplace', exact: true }).getAttribute('href'), host)).searchParams.has('location'),
            'All Manitoba browsing unexpectedly retained a location');
        console.log('PASS: explicit community scope survives nine public browse sections and clears at provincial home');



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
