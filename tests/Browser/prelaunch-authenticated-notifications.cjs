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
        await page.goto(host + '/community?q=NEIGHBOURS%20garden');
        check(await page.getByRole('link', { name: 'Community garden neighbours', exact: true }).isVisible(),
            'Reversed, mixed-case discussion keywords did not match');
        await page.goto(host + '/community?q=garden%20zzcanadambnoresults2026zz');
        check(await page.getByRole('link', { name: 'Community garden neighbours', exact: true }).count() === 0,
            'Discussion search should require all words');
        console.log('PASS: discussion keywords are case insensitive, unordered and conjunctive');
        for (const width of [1024, 1200, 1280, 1440, 1920]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(host + '/?location=brandon');
            const primary = page.getByRole('navigation', { name: 'Primary navigation' });
            const businesses = primary.locator('summary').filter({ hasText: 'Businesses' });
            const discussions = primary.locator('summary').filter({ hasText: 'Discussions' });
            const account = page.locator('.header-account-menu > summary');
            const search = page.locator('.masthead > .header-search');
            check(await search.isVisible(), 'Desktop quick search unavailable at ' + width);
            check(await page.locator('.header-post').isVisible(), 'Post listing action unavailable at ' + width);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Desktop header overflows at ' + width);
            await businesses.click();
            const directory = primary.getByRole('link', { name: 'Directory', exact: true });
            check(await directory.isVisible(), 'Directory submenu missing at ' + width);
            check(await directory.evaluate(el => {
                const r = el.getBoundingClientRect();
                const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
                return r.left >= 0 && r.right <= innerWidth && el.contains(hit);
            }), 'Directory submenu clipped or covered at ' + width);
            await discussions.click();
            check(!(await businesses.locator('..').evaluate(el => el.open)),
                'Business dropdown remained open when switching groups at ' + width);
            check(await primary.getByRole('link', { name: 'Events calendar', exact: true }).isVisible(),
                'Events dropdown unavailable at ' + width);
            await page.keyboard.press('Escape');
            check(!(await discussions.locator('..').evaluate(el => el.open)),
                'Escape did not dismiss discussions menu at ' + width);
            check(await discussions.evaluate(el => document.activeElement === el),
                'Escape did not restore summary focus at ' + width);
            await account.click();
            check(await page.locator('.header-account-dropdown').isVisible(),
                'Account menu unavailable at ' + width);
            const profile = page.locator('.header-account-dropdown').getByRole('link', { name: 'My profile' });
            check(await profile.evaluate(el => {
                const r = el.getBoundingClientRect();
                return r.left >= 0 && r.right <= innerWidth &&
                    el.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
            }), 'Account menu clipped or covered by navigation at ' + width);
            await page.keyboard.press('Escape');
            check(!(await account.locator('..').evaluate(el => el.open)), 'Account menu did not close');
            if (width === 1024 || width === 1440) {
                await page.screenshot({ path: 'test-results/desktop-header-' + width + '.png', animations: 'disabled' });
            }
        }
        console.log('PASS: desktop header and unclipped exclusive dropdowns at five widths');
        for (const width of [390, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const route of ['/marketplace?view=all', '/businesses', '/jobs', '/news', '/events', '/real-estate']) {
                const response = await page.goto(host + route);
                check(response && response.ok(), 'Browse workspace request failed: ' + route);
                const sidebar = page.locator('.browse-workspace > details.browse-sidebar');
                const results = page.locator('.browse-workspace > .browse-results-column');
                check(await sidebar.isVisible() && await results.isVisible(), 'Browse workspace missing: ' + route);
                if (width >= 1024) {
                    await sidebar.locator('form').waitFor({ state: 'visible' });
                    const bounds = await page.evaluate(() => {
                        const sidebar = document.querySelector('.browse-sidebar').getBoundingClientRect();
                        const results = document.querySelector('.browse-results-column').getBoundingClientRect();
                        return { sidebarRight: sidebar.right, sidebarTop: sidebar.top,
                            resultsLeft: results.left, resultsTop: results.top, resultsWidth: results.width };
                    });
                    check(bounds.sidebarRight < bounds.resultsLeft &&
                        Math.abs(bounds.sidebarTop - bounds.resultsTop) < 5 && bounds.resultsWidth >= 600,
                        'Desktop browse filters are not beside a usable results column: ' + route);
                } else {
                    check(!(await sidebar.evaluate(el => el.open)), 'Unfiltered mobile sidebar should start collapsed: ' + route);
                    await sidebar.locator('summary').click();
                    check(await sidebar.locator('form').isVisible(), 'Mobile filter disclosure failed: ' + route);
                }
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Browse workspace overflow: ' + route + ' at ' + width);
            }
        }
        await page.goto(host + '/marketplace');
        check(await page.locator('.browse-workspace').count() === 0,
            'Marketplace category discovery should remain full width');
        console.log('PASS: desktop filter sidebars and mobile disclosure behavior across six browse pages');

        for (const width of [320, 390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            const profileResponse = await page.goto(host + '/account/profile');
            check(profileResponse?.ok(), 'Account profile request failed');
            const dashboard = page.locator('.account-shortcuts');
            check(await dashboard.getByRole('link', { name: /My listings/ }).isVisible(), 'Dashboard missing listings');
            check(await dashboard.getByRole('link', { name: /Saved jobs/ }).isVisible(), 'Dashboard missing saved jobs');
            check(await dashboard.getByRole('link', { name: /Notifications/ }).isVisible(), 'Dashboard missing notifications');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Account dashboard overflow');
            const editorResponse = await page.goto(host + '/marketplace/new?location=brandon');
            check(editorResponse?.ok(), 'Listing editor request failed');
            check(await page.locator('.listing-editor-section').count() === 4, 'Listing editor missing sections');
            check(await page.locator('select[name="location_id"] option:checked').innerText() === 'Brandon', 'New listing lost browsing community');
            const input = page.locator('[data-listing-photos]');
            const file = { name: 'sample.png', mimeType: 'image/png',
                buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLttAAAAABJRU5ErkJggg==', 'base64') };
            await input.setInputFiles([file]);
            check(await page.locator('[data-photo-preview] img').count() === 1, 'Local photo preview missing');
            check(await page.locator('[data-photo-preview]').isVisible(), 'Local photo preview hidden');
            await input.setInputFiles([file, file, file, file, file, file]);
            check(!(await input.evaluate(el => el.validity.valid)), 'Six photos must fail client validation');
            await input.setInputFiles([]);
            check(await input.evaluate(el => el.validity.valid), 'Clearing photo input did not reset validation');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Listing editor overflow');
        }
        console.log('PASS: account dashboard and listing editor photo preview at three widths');







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
