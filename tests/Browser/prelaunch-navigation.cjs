const { chromium } = require('playwright');
const fs = require('node:fs');

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const check = (ok, message) => { if (!ok) throw new Error(message); };
    const hosts = ['http://canadamb.localhost:8000', 'http://brandonmb.localhost:8000'];
    try {
        fs.mkdirSync('test-results', { recursive: true });
        for (const host of hosts) {
            await page.goto(host);
            await page.waitForFunction(async () => {
                if (!navigator.serviceWorker) return false;
                const registration = await navigator.serviceWorker.getRegistration('/');
                return registration?.active?.state === 'activated';
            }, null, { timeout: 15000 });
            const manifest = await page.evaluate(async () => {
                const link = document.querySelector('link[rel="manifest"]');
                return (await fetch(link.href)).json();
            });
            check(manifest.display === 'standalone' && manifest.scope === '/' && manifest.id === '/', 'Invalid standalone manifest: ' + host);
            check(manifest.icons.some(icon => icon.sizes === '512x512'), 'Missing large launcher icon');
            check(await page.locator('#pwa-install').isVisible(), 'App installation guidance missing');
            if (await page.locator('#pwa-install-button').innerText() === 'App installation help') {
                await page.locator('#pwa-install-button').click();
                check(await page.locator('#pwa-install-help').isVisible(), 'Installation help did not open');
            }
            console.log('PASS: per-domain standalone manifest and active network-only service worker: ' + host);

            for (const width of [320, 390, 768, 1024, 1280, 1440]) {
                await page.setViewportSize({ width, height: 900 });
                await page.goto(host);
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Horizontal overflow at ' + host + ' ' + width);
                // The homepage search switches between genuine server-side GET routes.
                // Each mode has an independent form; switching requires no JavaScript.
                const homeSearch = page.locator('.home-unified-search');
                check(await homeSearch.isVisible(), 'Homepage local search missing: ' + host + ' ' + width);
                check(await homeSearch.locator('input[name="home-search-mode"]').count() === 6,
                    'Enabled search modes are missing: ' + host);
                check(await homeSearch.locator('form.home-search-form:visible').count() === 1,
                    'Only the selected search form should be visible');
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Homepage search overflows viewport at ' + width);
                if (width === 390 || width === 1440) {
                    for (const [mode, action] of [
                        ['marketplace', '/marketplace'], ['businesses', '/businesses'],
                        ['community', '/community'], ['jobs', '/jobs'], ['news', '/news'], ['events', '/events']
                    ]) {
                        await homeSearch.locator('label.home-search-mode').filter({ has: page.locator('input[value="' + mode + '"]') }).click();
                        const active = homeSearch.locator('form.home-search-form:visible');
                        check(await active.count() === 1 && await active.getAttribute('action') === action,
                            'Search mode did not activate real ' + mode + ' GET form at ' + width);
                        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                            'Search mode change overflows at ' + width + ': ' + mode);
                    }
                    await homeSearch.locator('label.home-search-mode').filter({
                        has: page.locator('input[value="marketplace"]')
                    }).click();
                }
                check(await page.getByRole('heading', { name: /Around (Manitoba|Brandon)/ }).isVisible(),
                    'Homepage community activity heading missing at ' + host + ' ' + width);
                check(await page.locator('.home-community-card').count() === 3,
                    'All enabled homepage community modules must show helpful cards even with no published posts');
                check(await page.locator('.home-community-card-footer a').count() === 3,
                    'Home community cards must have working discovery links');
                if (width <= 390) {
                    const cards = await page.locator('.home-community-card').evaluateAll(nodes =>
                        nodes.map(node => ({ width: node.getBoundingClientRect().width, left: node.getBoundingClientRect().left, right: node.getBoundingClientRect().right })));
                    check(cards.every(rect => rect.left >= 0 && rect.right <= width + 1 && rect.width >= width - 52),
                        'Mobile community cards are cramped/overflowing: ' + JSON.stringify(cards));
                }
                if (width < 1024) {
                    const hamburger = page.locator('#site-menu-open');
                    check(await hamburger.isVisible(), 'Tablet/mobile hamburger hidden: ' + width);
                    await hamburger.click();
                    check(await page.locator('#site-drawer').evaluate(node => node.open),
                        'Tablet/mobile drawer did not open: ' + width);
                    check(await page.locator('.site-drawer-search input[name="q"]').isVisible(),
                        'Drawer has no Marketplace quick search at ' + width);
                    check(await page.locator('.site-drawer-post').isVisible(),
                        'Drawer has no clear Post a listing action at ' + width);
                    const drawerLayout = await page.evaluate(() => {
                        const dialog = document.getElementById('site-drawer');
                        const nav = dialog.querySelector('.site-drawer-nav');
                        return { dialogWidth: dialog.getBoundingClientRect().width,
                            viewport: innerWidth, overflow: getComputedStyle(nav).overflowY,
                            navHeight: nav.getBoundingClientRect().height };
                    });
                    check(drawerLayout.dialogWidth <= drawerLayout.viewport &&
                        drawerLayout.overflow === 'auto' && drawerLayout.navHeight > 0,
                        'Drawer is clipped or no longer scrolls as one menu: ' + JSON.stringify(drawerLayout));
                    await page.keyboard.press('Escape');
                    check(!(await page.locator('#site-drawer').evaluate(node => node.open)),
                        'Escape failed to close drawer: ' + width);
                } else {
                    const groups = page.locator('.desktop-nav-group');
                    check(await groups.count() === 2, 'Expected Businesses and Discussions desktop menus');
                    check(await page.locator('.navigation a.desktop-real-estate').isVisible(),
                        'Real Estate missing in primary desktop navigation');
                    const top = await page.evaluate(() => {
                        const actions = document.querySelector('.masthead .header-actions').getBoundingClientRect();
                        const brand = document.querySelector('.masthead .brand').getBoundingClientRect();
                        const search = document.querySelector('.header-search').getBoundingClientRect();
                        return { brandRight: brand.right, actionsLeft: actions.left, searchWidth: search.width,
                            actionsRight: actions.right, viewport: innerWidth };
                    });
                    check(top.brandRight <= top.actionsLeft && top.actionsRight <= top.viewport + 1,
                        'Masthead action controls overlap or extend past desktop viewport: ' + JSON.stringify(top));
                    if (width >= 1280) check(top.searchWidth >= 225,
                        'Desktop search is still too narrow: ' + JSON.stringify(top));
                    await groups.first().locator('summary').click();
                    check(await groups.first().evaluate(node => node.open), 'Business dropdown failed to open');
                    const layout = await page.evaluate(() => {
                        const nav = document.querySelector('.navigation nav');
                        const popup = document.querySelector('.desktop-nav-group[open] .desktop-nav-submenu');
                        const rect = popup.getBoundingClientRect();
                        return {
                            overflow: getComputedStyle(nav).overflowX,
                            viewport: innerWidth,
                            left: rect.left,
                            right: rect.right,
                            clip: rect.bottom > nav.getBoundingClientRect().bottom,
                        };
                    });
                    check(layout.overflow === 'visible', 'Desktop submenu clipped in scrolling nav');
                    check(layout.clip && layout.left >= 0 && layout.right <= layout.viewport,
                        'Desktop dropdown clipped/offscreen at ' + width);
                    await groups.nth(1).locator('summary').click();
                    check(!(await groups.first().evaluate(node => node.open)),
                        'Opening Discussions did not close Businesses');
                    await page.keyboard.press('Escape');
                    check(!(await groups.nth(1).evaluate(node => node.open)),
                        'Escape did not dismiss desktop dropdown');
                }
                if (width === 390 || width === 1440) {
                    await page.screenshot({
                        path: 'test-results/' + (host.includes('brandon') ? 'brandon' : 'province') + '-' + width + '.png',
                        fullPage: true,
                        animations: 'disabled',
                    });
                }
                await page.goto(host + '/marketplace');
                check(await page.getByRole('heading', { name: 'What are you looking for?' }).isVisible(),
                    'Category-first Marketplace missing at ' + host + ' ' + width);
                check(await page.locator('.market-category-card').count() >= 6,
                    'Main Marketplace categories are missing');
                check(await page.locator('.market-category-card').getByText('Real Estate', { exact: true }).isVisible(),
                    'Gated Real Estate category is not discoverable');
                check(await page.locator('.market-category-card .market-category-icon svg').count() >= 6,
                    'Marketplace category artwork is not using the shared vector icon set');
                check(await page.locator('.market-category-parent').count() >= 6,
                    'Main category cards must expose direct subcategory navigation');
                check(await page.locator('.market-category-children a').count() >= 16,
                    'Marketplace subcategory quick links are missing');
                check(await page.locator('.market-category-children a[href^="/marketplace?category=cars"]').isVisible(),
                    'Cars subcategory is not directly accessible from category chooser');
                check(await page.getByText('Coming later').isVisible(),
                    'Real Estate is missing its launch status');
                check(await page.locator('.market-category-card[href^="/real-estate"]').count() === 1,
                    'Dedicated Real Estate category is not linked');
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Marketplace horizontal overflow at ' + host + ' ' + width);
                if (width <= 390) {
                    const categoryColumns = await page.locator('.market-category-parent').first().evaluate(node => ({
                        card: node.getBoundingClientRect().width,
                        viewport: innerWidth,
                        links: [...node.querySelectorAll('.market-category-children a')].length,
                    }));
                    check(categoryColumns.card >= width - 40 && categoryColumns.links >= 4,
                        'Mobile category hierarchy is cramped or incomplete: ' + JSON.stringify(categoryColumns));
                }
                if (width === 390 || width === 1440) {
                    await page.screenshot({
                        path: 'test-results/' + (host.includes('brandon') ? 'brandon' : 'province') +
                            '-marketplace-' + width + '.png',
                        fullPage: true,
                        animations: 'disabled',
                    });
                }
                await page.goto(host + '/real-estate');
                check(await page.getByRole('heading', { name: 'Real Estate' }).isVisible(),
                    'Dedicated Real Estate section is missing at ' + host + ' ' + width);
                check(await page.getByText('Property listings are being prepared').isVisible(),
                    'Real Estate public-availability status missing');
                check(await page.locator('form[action="/real-estate"] select[name="transaction"]').count() === 1,
                    'Real Estate sale/rent filters missing');
                const estateOverflow = await page.evaluate(() => ({
                    width: innerWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    offenders: [...document.querySelectorAll('body *')]
                        .filter(el => el.getBoundingClientRect().right > innerWidth + 1)
                        .slice(0, 5)
                        .map(el => ({ tag: el.tagName, class: String(el.className).slice(0, 110) }))
                }));
                check(estateOverflow.scrollWidth <= estateOverflow.width,
                    'Real Estate horizontal overflow at ' + host + ' ' + width + ': ' + JSON.stringify(estateOverflow.offenders));
                if (width === 390 || width === 1440) {
                    await page.screenshot({
                        path: 'test-results/' + (host.includes('brandon') ? 'brandon' : 'province') +
                            '-real-estate-' + width + '.png',
                        fullPage: true, animations: 'disabled',
                    });
                }
                await page.goto(host + '/marketplace');
                await page.locator('.market-parent-copy a[href="/marketplace?group=vehicles"]').click();
                check(await page.getByRole('heading', { name: 'Vehicles & Motors' }).isVisible(),
                    'Main category does not open subcategories');
                await page.locator('a[href="/marketplace?category=cars"]').click();
                check(new URL(page.url()).searchParams.get('category') === 'cars',
                    'Subcategory did not preserve legacy listing URL');
                // Discussion hub and its vertical, expandable events calendar
                // must remain usable at the same six responsive widths.
                await page.goto(host + '/community');
                check(await page.getByRole('heading', { name: 'Discussions', exact: true }).isVisible(),
                    'Discussions hub missing at ' + host + ' ' + width);
                check(await page.locator('.discussion-hub').isVisible(),
                    'Responsive discussion layout is missing');
                check(await page.locator('.discussion-events').isVisible(),
                    'Events timeline is not integrated into Discussions');
                check(await page.locator('.discussion-empty-actions a[href="/community/new"]').count() > 0,
                    'Empty discussions have no useful first-post action');
                check(await page.locator('.discussion-category-nav a').count() >= 6,
                    'Discussion topics are missing their browse categories');
                check(await page.locator('a[href="/community/new?type=event"]').count() > 0,
                    'No event-request entry point from Discussions');
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Discussions overflow at ' + host + ' ' + width);
                if (width === 390 || width === 1440) {
                    await page.screenshot({
                        path: 'test-results/' + (host.includes('brandon') ? 'brandon' : 'province') +
                            '-discussions-' + width + '.png',
                        fullPage: true,
                        animations: 'disabled',
                    });
                }
                if (host.includes('canadamb') && width === 390) {
                    await page.goto(host + '/marketplace?location=brandon');
                    check(await page.getByRole('heading', { name: 'What are you looking for?' }).isVisible(),
                        'Location-only visit did not open the category chooser');
                    await page.locator('.market-parent-copy a[href="/marketplace?group=vehicles&location=brandon"]').click();
                    await page.locator('a[href="/marketplace?category=cars&location=brandon"]').click();
                    check(new URL(page.url()).searchParams.get('location') === 'brandon',
                        'Location was lost while selecting a subcategory');
                }
            }
        }
        console.log('PASS: desktop menus, Marketplace, Real Estate and Discussions/Events on both hosts at six widths');
    } catch (error) {
        try { await page.screenshot({ path: 'test-results/prelaunch-nav-failure.png', fullPage: true }); } catch {}
        console.error(error?.stack || error);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
