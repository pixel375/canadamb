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
        await page.locator('label .required-marker').first().waitFor({ state: 'visible' });
        check(await page.locator('label .required-marker').count() >= 2, 'Required login fields not marked');
        await page.getByRole('button', { name: 'Sign in' }).click();
        check(await page.locator('input[name="email"]').getAttribute('aria-invalid') === 'true', 'Missing email not visibly marked invalid');
        await page.getByLabel('Email').fill('browser-notifications@example.test');
        await page.getByLabel('Password').fill('browser-qa-password-only');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL(url => !url.pathname.startsWith('/login'));
        const rememberCookie = (await page.context().cookies(host)).find(cookie => cookie.name.startsWith('remember_web_'));
        check(rememberCookie && rememberCookie.httpOnly && rememberCookie.sameSite === 'Lax'
            && rememberCookie.expires > Date.now() / 1000 + 29 * 86400
            && rememberCookie.expires < Date.now() / 1000 + 31 * 86400, 'Default persistent login cookie missing or incorrectly scoped');
        await page.context().clearCookies();
        await page.context().addCookies([rememberCookie]);
        await page.goto(host + '/account/profile');
        check(new URL(page.url()).pathname === '/account/profile', 'Remembered login failed after session cookie loss');
        console.log('PASS: persistent login restores a fresh session with a host-only 30-day remember cookie');

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

        await page.setViewportSize({ width: 390, height: 900 });
        await page.goto(host + '/account/community-notifications?view=read');
        check(await page.locator('.discussion-notification-row').count() === 1, 'Read history did not isolate read notification');
        await page.getByText('Manage selected notifications', { exact: true }).click();
        const notificationSelection = page.locator('#notification-selection');
        const selectedNotice = page.locator('input[form="notification-selection"][name="notifications[]"]').first();
        await selectedNotice.check();
        await notificationSelection.locator('select[name="action"]').selectOption('unread');
        const confirmation = notificationSelection.locator('input[type="checkbox"][required]');
        if (await confirmation.count()) await confirmation.check();
        await notificationSelection.locator('button[type="submit"], button:not([type])').click();
        await page.goto(host + '/account/community-notifications?view=unread');
        check(await page.locator('.discussion-notification-row.is-unread').count() === 2, 'Selected read notification did not become unread');
        console.log('PASS: selected read history action through mobile browser');

        // Opening a notification is an explicit POST, not a mutation caused
        // by a prefetch or a GET to a public reply permalink.
        const notice = page.locator('.discussion-notification-row.is-unread').first();
        const noticeTitle = await notice.locator('.notification-open-link').innerText();
        await notice.getByRole('button', { name: 'View reply', exact: true }).click();
        await page.waitForURL(url => url.origin === host && /^\/community\/\d+$/.test(url.pathname) && url.hash.startsWith('#reply-'));
        check(await page.getByRole('heading', { name: noticeTitle, exact: true }).isVisible(), 'Notification did not open its approved discussion on Manitoba');
        const mirroredPath = new URL(page.url()).pathname;
        const local = await browser.newPage();
        const localResponse = await local.goto('http://brandonmb.localhost:8000' + mirroredPath);
        check(localResponse?.ok() && new URL(local.url()).hostname === 'brandonmb.localhost', 'Local discussion redirected away');
        check(await local.getByRole('heading', { name: noticeTitle, exact: true }).isVisible(), 'Mirrored local discussion missing');
        await local.close();
        await page.goto(host + '/account/community-notifications?view=unread');
        check(await page.locator('.discussion-notification-row.is-unread').count() === 1, 'Opening notification did not acknowledge exactly one');
        await page.goto(host + '/account/community-notifications?view=read');
        await page.getByRole('button', { name: 'Mark unread', exact: true }).click();
        await page.goto(host + '/account/community-notifications?view=unread');
        check(await page.locator('.discussion-notification-row.is-unread').count() === 2, 'Read-state restore failed');
        console.log('PASS: notification opens on current portal, mirrored discussion renders on both domains, one notification acknowledged');

        await page.goto(host + '/account/followed-discussions');
        const followingForm = page.locator('form[action="/account/followed-discussions"]');
        await followingForm.locator('input[name="q"]').fill('garden');
        await followingForm.getByRole('button', { name: 'Search followed discussions' }).click();
        await page.waitForURL(url => url.pathname === '/account/followed-discussions' && url.searchParams.get('q') === 'garden');
        check(await page.getByRole('link', { name: 'Community garden neighbours' }).isVisible(), 'Follow search lost matching discussion');
        await followingForm.locator('input[name="q"]').fill('zzmissingfollow');
        await followingForm.getByRole('button', { name: 'Search followed discussions' }).click();
        await page.getByRole('heading', { name: 'No followed discussions match these filters' }).waitFor();
        console.log('PASS: followed discussion search and empty state');

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

        for (const width of [320, 390, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            const directoryResponse = await page.goto(host + '/businesses?location=brandon');
            check(directoryResponse.ok(), 'Business category discovery failed');
            check(await page.locator('.business-category-tile').count() === 8, 'Expected all business categories');
            const service = page.locator('.business-category-tile').filter({ hasText: 'Professional & local services' });
            await service.click();
            const url = new URL(page.url());
            check(url.searchParams.get('category') === 'services' && url.searchParams.get('location') === 'brandon',
                'Business category link lost its community');
            check(await page.locator('.business-category-tile[aria-current="page"]').count() === 1, 'Category selection missing');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Business category overflow at ' + width);
            const updatesResponse = await page.goto(host + '/business-updates?location=brandon&type=promotion&q=test');
            check(updatesResponse.ok(), 'Filtered business activity failed');
            check(await page.locator('select[name="type"]').inputValue() === 'promotion', 'Business update filter lost its type');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Business activity overflow at ' + width);
        }
        console.log('PASS: business category discovery and activity filters at four widths');

        for (const width of [320, 390, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            let response = await page.goto(host + '/community?location=brandon&sort=replies');
            check(response.ok(), 'Scoped discussions failed');
            check(await page.locator('.discussion-filter-chip[aria-current="page"]').count() === 1, 'Invalid selected discussion category');
            check(await page.locator('select[name="sort"]').inputValue() === 'replies', 'Discussion sort lost');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Discussion overflow at ' + width);
            response = await page.goto(host + '/community/new?location=brandon&category=help');
            check(response.ok(), 'Discussion composer failed');
            check(await page.locator('select[name="location_id"] option:checked').innerText() === 'Brandon', 'Composer lost community');
            check(await page.locator('select[name="category"]').inputValue() === 'help', 'Composer lost category');
            await page.locator('textarea[name="body"]').fill('A local discussion test.');
            check((await page.locator('[data-character-feedback]').innerText()).includes('24 / 5000'), 'Character counter did not update');
            response = await page.goto(host + '/account/discussions');
            check(response.ok(), 'Private discussion dashboard failed');
            check(await page.locator('.seller-status-tile').count() === 5, 'Discussion statuses missing');
            response = await page.goto(host + '/account/events');
            check(response.ok(), 'Private event dashboard failed');
            check(await page.locator('.seller-status-tile').count() === 6, 'Event statuses missing');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Event dashboard overflow at ' + width);
        }
        console.log('PASS: discussion and event discovery, scoped composer and private dashboards at four widths');

        for (const width of [320, 390, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            let response = await page.goto(host + '/?location=brandon');
            check(response.ok(), 'Scoped home failed');
            for (const mode of ['news', 'events']) {
                await page.locator('.home-search-mode').filter({ has: page.locator('input[value="' + mode + '"]') }).click();
                const form = page.locator('[data-home-search-section="' + mode + '"]');
                check(await form.isVisible(), 'Homepage search mode not visible: ' + mode);
                check(await form.locator('input[name="location"]').inputValue() === 'brandon', 'Home search lost community');
            }
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Homepage overflow at ' + width);
            response = await page.goto(host + '/news?location=brandon&period=7&category=municipal');
            check(response.ok(), 'News filters failed');
            check(await page.locator('select[name="period"]').inputValue() === '7', 'News period filter lost');
            check(await page.locator('.news-category-panel [aria-current="page"]').count() === 1, 'News category selection missing');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'News overflow at ' + width);
        }
        console.log('PASS: homepage news and event search modes and scoped news filters at four widths');




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
            await input.setInputFiles([file, { ...file, name: 'second.png' }]);
            await page.getByRole('button', { name: 'Make photo 2 the cover' }).click();
            check(await input.evaluate(el => el.files[0].name) === 'second.png', 'Cover photo order did not reach the file input');
            await page.getByRole('button', { name: 'Remove photo 2' }).click();
            check(await input.evaluate(el => el.files.length) === 1, 'Photo removal did not update submitted files');
            check(await page.locator('[data-photo-preview] img').count() === 1, 'Photo removal did not update previews');
            await input.setInputFiles([file, file, file, file, file, file]);
            check(!(await input.evaluate(el => el.validity.valid)), 'Six photos must fail client validation');
            await input.setInputFiles([]);
            check(await input.evaluate(el => el.validity.valid), 'Clearing photo input did not reset validation');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Listing editor overflow');
        }
        console.log('PASS: account dashboard and listing editor photo preview at three widths');

        for (const width of [320, 390, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            let response = await page.goto(host + '/real-estate?location=brandon&transaction=rent&q=garage');
            check(response.ok(), 'Scoped property browser failed');
            check(await page.locator('.property-type-card').count() === 13, 'Property categories missing');
            await page.locator('.property-type-card').filter({ hasText: 'Townhouses' }).click();
            const url = new URL(page.url());
            check(url.searchParams.get('property_type') === 'townhouse' && url.searchParams.get('transaction') === 'rent' &&
                url.searchParams.get('q') === 'garage' && url.searchParams.get('location') === 'brandon', 'Property category lost filters');
            check(await page.locator('.property-type-card[aria-current="page"]').count() === 1, 'Property selected category incorrect');
            await page.getByRole('link', { name: 'Prepare a private property draft' }).click();
            check(await page.locator('.listing-editor-section').count() === 4, 'Private property editor missing sections');
            check(await page.locator('select[name="location_id"] option:checked').innerText() === 'Brandon', 'Property draft lost community');
            check(await page.locator('select[name="transaction"]').inputValue() === 'rent', 'Property draft lost rental selection');
            const input = page.locator('[data-listing-photos]');
            const file = { name: 'property.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLttAAAAABJRU5ErkJggg==', 'base64') };
            await input.setInputFiles(Array(6).fill(file));
            check(await input.evaluate(el => el.validity.valid), 'Property draft should allow six images');
            await input.setInputFiles(Array(7).fill(file));
            check(!(await input.evaluate(el => el.validity.valid)), 'Property draft must reject seven images');
            await input.setInputFiles([]);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Property editor overflow at ' + width);
            response = await page.goto(host + '/account/properties');
            check(response.ok(), 'Private property dashboard failed');
            check(await page.locator('.seller-status-tile').count() === 5, 'Private property statuses missing');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Property dashboard overflow at ' + width);
            await page.getByRole('link', { name: 'Archived history' }).click();
            await page.getByRole('heading', { name: 'Archived property history' }).waitFor();
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Property archive overflow at ' + width);
        }
        console.log('PASS: real estate discovery, private property workspace and upload limits at four widths');


        await page.goto(host + '/account/settings');
        check(await page.locator('body[data-activity-counts="/account/activity-counts"]').count() === 1,
            'Verified account missing activity count endpoint');
        const activitySetting = page.locator('#activity-refresh-setting');
        await activitySetting.uncheck();
        check(await page.evaluate(() => localStorage.getItem('canadamb-activity-refresh')) === 'off',
            'Activity preference was not saved');
        await page.locator('#theme-choice-settings').selectOption('dark');
        check(await page.evaluate(() => document.documentElement.dataset.theme) === 'dark',
            'Settings appearance did not update the page');
        await page.reload();
        check(!(await activitySetting.isChecked()), 'Activity preference did not persist');
        check(await page.locator('#theme-choice-settings').inputValue() === 'dark',
            'Appearance preference did not persist');
        await activitySetting.check();
        await page.locator('#theme-choice-settings').selectOption('system');
        console.log('PASS: account device preferences persist and activity polling is restricted to verified pages');








        console.log('PASS: authenticated notification hub, unread filters and followed conversations at five widths');

        const adminPage = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await adminPage.goto(host + '/login');
        await adminPage.getByLabel('Email').fill('browser-admin@example.test');
        await adminPage.getByLabel('Password').fill('browser-qa-admin-password-only');
        await adminPage.getByRole('button', { name: 'Sign in' }).click();
        await adminPage.waitForURL(url => !url.pathname.startsWith('/login'));
        for (const width of [320, 390, 768, 1024, 1440]) {
            await adminPage.setViewportSize({ width, height: 900 });
            for (const path of ['/admin', '/admin/moderation?status=pending', '/admin/community?status=pending',
                '/admin/jobs?status=pending', '/admin/communities', '/admin/business-review', '/admin/business-ownership', '/admin/news', '/admin/audit', '/admin/readiness', '/admin/commercial', '/admin/local-context', '/admin/publication-policy', '/admin/theme', '/admin/settings']) {
                const response = await adminPage.goto(host + path);
                check(response.ok(), 'Admin page failed: ' + path + ' at ' + width);
                check(await adminPage.locator('.admin-shell').isVisible(), 'Admin layout missing: ' + path);
                check(await adminPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Admin page overflow: ' + path + ' at ' + width);
                check(await adminPage.locator('.admin-nav-group').count() >= 5, 'Grouped admin navigation is missing');
                check(await adminPage.locator('.admin-nav a[aria-current="page"]').count() === 1, 'Admin current section is ambiguous');
                if (path === '/admin/local-context') {
                    check(await adminPage.locator('input[name="weather_api_key"]').getAttribute('type') === 'password', 'Weather key must be hidden');
                    check(await adminPage.locator('input[name="weather_api_key"]').inputValue() === '', 'Weather key must never be redisplayed');
                }
                if (path === '/admin/publication-policy') {
                    for (const key of ['listings', 'discussions', 'replies']) {
                        check(await adminPage.locator('select[name="policy[' + key + ']"]').inputValue() === 'manual', 'Default policy must require review');
                    }
                }
                if (path === '/admin') {
                    check(await adminPage.locator('.admin-queue-card').count() >= 9, 'Dashboard review counts missing');
                    check(await adminPage.locator('.admin-queue-card').filter({ hasText: 'Discussions' }).locator('strong').innerText() === '1',
                        'Pending discussion count incorrect');
                    if (width === 390 || width === 1440) {
                        await adminPage.screenshot({ path: 'test-results/admin-overview-' + width + '.png', fullPage: true });
                    }
                }
            }
        }
        await adminPage.setViewportSize({ width: 390, height: 900 });
        await adminPage.goto(host + '/admin/communities');
        await adminPage.locator('form[action="/admin/communities"] input[name="q"]').fill('Brandon');
        await adminPage.getByRole('button', { name: 'Filter portals' }).click();
        await adminPage.waitForURL(url => url.searchParams.get('q') === 'Brandon');
        check(await adminPage.locator('[data-admin-portal]').count() === 1, 'Community search did not isolate Brandon');
        await adminPage.locator('[data-admin-portal]').getByRole('link', { name: 'Manage portal' }).click();
        check(await adminPage.getByRole('heading', { name: 'Community appearance and utilities' }).isVisible(), 'Local experience settings missing');
        const experienceForm = adminPage.locator('form[action$="/experience"]');
        await experienceForm.locator('select[name="clock_format"]').selectOption('24');
        await experienceForm.getByRole('button', { name: 'Save community experience' }).click();
        await adminPage.waitForLoadState('domcontentloaded');
        check(await experienceForm.locator('select[name="clock_format"]').inputValue() === '24', 'Local clock format did not persist');
        await experienceForm.locator('select[name="clock_format"]').selectOption('inherit');
        await experienceForm.getByRole('button', { name: 'Save community experience' }).click();
        await adminPage.waitForLoadState('domcontentloaded');
        check(await experienceForm.locator('select[name="clock_format"]').inputValue() === 'inherit', 'Local clock inheritance was not restored');
        console.log('PASS: responsive portal directory and local experience save and reset');
        await adminPage.goto(host + '/admin/moderation?status=pending&q=selected+browser+moderation');
        const selectedReview = adminPage.locator('#listing-batch-review');
        await adminPage.getByText('Review selected listings', { exact: true }).click();
        const selectedItems = adminPage.locator('input[form="listing-batch-review"][name="listings[]"]');
        check(await selectedItems.count() === 2, 'Expected two independently reviewable fixture listings');
        await selectedItems.nth(0).check();
        await selectedItems.nth(1).check();
        await selectedReview.locator('select[name="status"]').selectOption('rejected');
        await selectedReview.locator('input[name="reason"]').fill('Disposable browser review fixture');
        await selectedReview.locator('input[name="confirm_review"]').check();
        await selectedReview.getByRole('button', { name: 'Apply selected moderation action' }).click();
        await adminPage.getByText('2 selected listings moderated.', { exact: true }).waitFor();
        await adminPage.goto(host + '/admin/moderation?status=rejected&q=selected+browser+moderation');
        check(await adminPage.locator('article.admin-review-card').count() === 2, 'Selected listing moderation did not persist');
        console.log('PASS: selected Marketplace moderation through an actual mobile form');
        await adminPage.goto(host + '/admin/community?status=pending&q=local+garden');
        check(await adminPage.getByText('Pending local garden discussion for moderation', { exact: true }).count() === 1,
            'Pending review search did not find the seeded discussion');
        check(await adminPage.getByText('Community garden neighbours', { exact: true }).count() === 0,
            'Approved topic leaked into the pending review filter');
        await adminPage.goto(host + '/admin/commercial');
        await adminPage.locator('input[name="prices[home-banner]"]').fill('20.50');
        await adminPage.getByRole('button', { name: 'Save rate-card draft' }).click();
        check(await adminPage.locator('input[name="prices[home-banner]"]').inputValue() === '20.50', 'Proposed rate not persisted');
        await adminPage.locator('select[name="placement"]').selectOption('home-banner');
        await adminPage.locator('input[name="weeks"]').fill('3');
        await adminPage.getByRole('button', { name: 'Preview placement' }).click();
        check((await adminPage.locator('.commercial-estimate').innerText()).includes('CAD $61.50'), 'Campaign estimate incorrect');
        check(await adminPage.locator('.commercial-sponsored-label').count() === 3, 'Sponsored previews missing');
        await adminPage.setViewportSize({ width: 390, height: 900 });
        await adminPage.goto(host + '/admin/settings');
        check(await adminPage.getByRole('heading', { name: 'Configuration revision history' }).isVisible(), 'Configuration history missing');
        check(await adminPage.getByRole('heading', { name: 'Pending global configuration changes' }).isVisible(), 'Shared rate-card draft missing');
        await adminPage.getByText('Discard all pending changes', { exact: true }).click();
        const discardForm = adminPage.locator('form[action="/admin/settings/discard"]');
        check(await discardForm.isVisible(), 'Draft discard form missing');
        check(!(await discardForm.locator('input[name="confirm_discard"]').isChecked()), 'Discard must require explicit confirmation');
        check(await adminPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Draft review overflows mobile');
        await discardForm.locator('input[name="confirm_discard"]').check();
        await discardForm.getByRole('button', { name: 'Discard unpublished settings' }).click();
        await adminPage.getByText('Pending network configuration discarded. Published settings were not changed.', { exact: true }).waitFor();
        check(await discardForm.count() === 0, 'Discarded draft remains actionable');
        check(await adminPage.getByText('Historical', { exact: true }).count() >= 1, 'Discarded revision metadata missing');
        check(await adminPage.getByText('Currently published', { exact: true }).count() === 1, 'Published configuration pointer changed unexpectedly');
        await adminPage.close();
        console.log('PASS: private commercial planning, draft rate persistence and campaign estimate; confirmed mobile configuration discard');
        console.log('PASS: admin overview, moderation and audit layouts at five widths, with filtered pending review');

        await page.setViewportSize({ width: 390, height: 900 });
        await page.goto(host + '/account/settings');
        await page.locator('summary').filter({ hasText: /^Change password$/ }).click();
        const current = page.locator('input[name="current_password"]');
        await current.fill('browser-qa-password-only');
        const toggle = page.locator('[data-password-toggle][aria-controls="password-field-current_password"]');
        await toggle.click();
        check(await current.getAttribute('type') === 'text', 'Password visibility toggle failed');
        await toggle.click();
        check(await current.getAttribute('type') === 'password', 'Password hide toggle failed');
        await page.locator('input[name="password"]').fill('New-browser-qa-42');
        await page.locator('input[name="password_confirmation"]').fill('New-browser-qa-42');
        await page.getByRole('button', { name: 'Change password and sign out' }).click();
        await page.waitForURL(url => url.pathname === '/login');
        check(await page.getByText('Password changed. Sign in again with your new password.').isVisible(), 'Password change confirmation missing');
        await page.getByLabel('Email').fill('browser-notifications@example.test');
        await page.getByLabel('Password').fill('New-browser-qa-42');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL(url => url.pathname !== '/login');
        check((await page.goto(host + '/account/settings')).ok(), 'New password did not restore access');
        const revokedContext = await browser.newContext();
        await revokedContext.addCookies([rememberCookie]);
        const revokedPage = await revokedContext.newPage();
        await revokedPage.goto(host + '/account/profile');
        check(new URL(revokedPage.url()).pathname === '/login', 'Password change failed to revoke the earlier remember cookie');
        await revokedContext.close();
        console.log('PASS: password visibility, password change, remembered-token revocation and fresh sign-in');

        for (const width of [320, 390, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            check((await page.goto(host + '/account/saved?q=oak&sort=price-low')).ok(), 'Saved listings search failed');
            check(await page.locator('.saved-listing-card').count() === 1, 'Saved listing not found');
            check(await page.locator('.saved-navigation [aria-current="page"]').count() === 1, 'Saved navigation state missing');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Saved listings overflow');
            check((await page.goto(host + '/account/saved-jobs')).ok(), 'Saved jobs failed');
            check(await page.locator('select[name="sort"]').isVisible(), 'Job sort missing');
            check((await page.goto(host + '/account/messages?q=BOOKSHELF+OAK')).ok(), 'Conversation search failed');
            check(await page.locator('.message-thread').count() === 1, 'Multiword conversation search failed');
            await page.locator('.message-thread').click();
            check(await page.getByText('The oak bookshelf is still available.', { exact: true }).isVisible(), 'Conversation missing message');
            check(await page.getByText('Search this conversation', { exact: true }).count() === 0, 'Chat history search should be removed');
            check(await page.locator('[data-chat-jump], .site-footer').count() === 0, 'Chat jump or footer remains');
            check(await page.getByRole('link', { name: 'Back to messages' }).isVisible(), 'Compact chat back link missing');
            check(await page.evaluate(() => {
                const history = document.querySelector('[data-chat-history]');
                const composer = document.querySelector('.chat-composer');
                return history.clientHeight > 0 && getComputedStyle(history).overflowY === 'auto'
                    && composer.getBoundingClientRect().bottom <= innerHeight + 2
                    && document.documentElement.scrollHeight <= innerHeight + 2;
            }), 'Chat composer or history does not fit at ' + width);
            await page.locator('textarea[name="body"]').fill('Thanks for confirming.');
            check((await page.locator('[data-character-feedback]').innerText()).includes('22 / 2000'), 'Chat character counter did not update');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Conversation overflow at ' + width);
        }
        await page.locator('.chat-actions summary').click();
        await page.getByRole('button', { name: 'Block member', exact: true }).click();
        check(await page.getByText('New messages are blocked for this conversation.').isVisible(), 'Blocked chat state missing');
        await page.goto(host + '/account/blocked-members');
        await page.locator('.blocked-member-row summary').click();
        await page.getByRole('button', { name: 'Confirm unblock' }).click();
        check(await page.getByText('You have not blocked any members.').isVisible(), 'Unblock did not remove member');
        console.log('PASS: saved listing and job navigation, messaging search, responsive chat and block management');

        await page.setViewportSize({ width: 390, height: 900 });
        await page.goto(host + '/account/listings?q=private+photo+editor');
        const sellerFilters = page.locator('form[action="/account/listings"][method="get"]');
        await sellerFilters.locator('select[name="category"]').selectOption('furniture');
        await sellerFilters.locator('select[name="sort"]').selectOption('price-high');
        await sellerFilters.getByRole('button', { name: 'Search', exact: true }).click();
        await page.waitForURL(url => url.searchParams.get('category') === 'furniture' && url.searchParams.get('sort') === 'price-high');
        check(await page.locator('.seller-listing-card').count() === 1, 'Seller category search did not isolate the owned listing');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Seller inventory filters overflow mobile');
        await page.getByRole('link', { name: 'Edit', exact: true }).click();
        const existing = page.locator('[data-existing-photo]');
        check(await existing.count() === 2, 'Existing photos missing from editor');
        const secondId = await existing.nth(1).getAttribute('data-existing-photo');
        await existing.nth(1).locator('[data-existing-cover]').click();
        check(await existing.first().getAttribute('data-existing-photo') === secondId, 'Existing cover did not move first');
        await existing.nth(1).getByRole('checkbox', { name: 'Keep photo' }).uncheck();
        await page.getByRole('button', { name: 'Submit for review' }).click();
        await page.waitForURL(url => url.pathname === '/account/listings');
        await page.goto(host + '/account/listings?q=private+photo+editor');
        await page.getByRole('link', { name: 'Edit', exact: true }).click();
        check(await existing.count() === 1 && await existing.first().getAttribute('data-existing-photo') === secondId, 'Saved photo removal or cover selection failed');
        console.log('PASS: existing listing photo cover and removal persisted through real form submission');
        for (const width of [320, 390, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const path of ['/safety', '/privacy-overview', '/contact?topic=news']) {
                check((await page.goto(host + path)).ok(), 'Help page unavailable: ' + path);
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Help page overflow: ' + path);
            }
            check(await page.locator('select[name="topic"]').inputValue() === 'news', 'Contact topic not retained');
        }
        console.log('PASS: help, safety, privacy and contact pages at four widths');
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(host + '/account/businesses');
            await page.getByRole('link', { name: 'Team', exact: true }).click();
            check(await page.getByRole('heading', { name: 'Browser QA Team team' }).isVisible(), 'Business team page missing');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Business team overflow');
            await page.getByLabel('Account email').fill('team-qa@example.test');
            await page.getByRole('checkbox', { name: /confirmed this account holder/ }).check();
            await page.getByRole('button', { name: 'Grant access' }).click();
            await page.getByText('Browser QA Teammate', { exact: true }).waitFor();
            const row = page.locator('.business-team-row').filter({ hasText: 'Browser QA Teammate' });
            await row.locator('summary').click();
            await row.locator('select[name="role"]').selectOption('manager');
            await row.getByRole('button', { name: 'Save role' }).click();
            check((await row.innerText()).includes('Manager'), 'Team role change failed');
            await row.locator('summary').click();
            await row.getByRole('button', { name: 'Confirm remove member' }).click();
            check(await row.count() === 0, 'Team removal failed');
            await page.goto(host + '/account/businesses');
            await page.getByRole('link', { name: 'Manage jobs' }).click();
            await page.getByRole('link', { name: 'Reuse posting' }).click();
            check(await page.locator('input[name="title"]').inputValue() === 'Browser QA technician', 'Reusable posting did not retain title');
            check(await page.locator('input[name="closing_on"]').inputValue() === '', 'Reuse must require new closing date');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Reuse posting overflow');
        }
        console.log('PASS: owner team grants, role changes and removals, reusable job forms at two widths');
        await page.goto(host + '/account/businesses');
        await page.getByRole('link', { name: 'Manage jobs' }).click();
        await page.getByText('Extend selected closing dates', { exact: true }).click();
        const deadlineForm = page.locator('#job-batch-extend');
        await page.locator('input[form="job-batch-extend"][name="jobs[]"]').first().check();
        const deadline = await deadlineForm.locator('input[name="new_closing_on"]').getAttribute('max');
        await deadlineForm.locator('input[name="new_closing_on"]').fill(deadline);
        await deadlineForm.locator('input[name="confirm_extend"]').check();
        await deadlineForm.getByRole('button', { name: 'Extend selected deadlines' }).click();
        await page.getByText('1 job posting deadlines extended for review.', { exact: true }).waitFor();
        console.log('PASS: selected job deadline extension');
        await page.setViewportSize({ width: 390, height: 900 });
        await page.goto(host + '/account/businesses');
        await page.getByRole('link', { name: 'Manage profile' }).click();
        await page.getByRole('link', { name: 'Advertising requests' }).click();
        const campaignForm = page.locator('form[method="post"][action$="/advertising"]');
        await campaignForm.locator('select[name="site_id"]').selectOption({ index: 1 });
        await campaignForm.locator('select[name="placement"]').selectOption('home-banner');
        await page.locator('input[name="title"]').fill('Private browser campaign');
        await page.locator('textarea[name="body"]').fill('A private advertisement proposal.');
        const start = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
        await page.locator('input[name="starts_on"]').fill(start);
        const submitted = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/advertising'));
        await page.getByRole('button', { name: 'Submit private request' }).click();
        const submittedResponse = await submitted;
        check(submittedResponse.status() === 302, 'Campaign POST returned ' + submittedResponse.status());

        await page.getByRole('heading', { name: 'Private browser campaign', exact: true }).waitFor({ timeout: 10000 }).catch(async () => {
            throw new Error('Campaign was not persisted: ' + page.url() + ' ' + (await page.locator('body').innerText({ timeout: 3000 })).slice(0, 2500));
        });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Advertising dashboard overflow');
        await page.locator('summary').filter({ hasText: 'Withdraw request' }).click();
        await page.getByRole('button', { name: 'Confirm withdrawal' }).click();
        await page.locator('article.admin-review-card').filter({ hasText: 'Private browser campaign' }).getByText(/Withdrawn/).waitFor();
        await page.goto(host);
        check(await page.getByText('Private browser campaign', { exact: true }).count() === 0, 'Private campaign leaked onto homepage');
        console.log('PASS: private persistent campaign submission and withdrawal, no public delivery');
        for (const width of [320, 390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(host + '/account/data-export');
            check(await page.getByRole('heading', { name: 'Download my data' }).isVisible(), 'Account export unavailable');
            check(await page.getByLabel('Current password').isVisible(), 'Export password confirmation unavailable');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Account export overflow');
        }
        console.log('PASS: private account export interface at three widths');





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
