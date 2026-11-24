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
            for (const width of [320, 390, 768, 1024, 1280, 1440]) {
                await page.setViewportSize({ width, height: 900 });
                await page.goto(host);
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Horizontal overflow at ' + host + ' ' + width);
                if (width < 1024) {
                    const hamburger = page.locator('#site-menu-open');
                    check(await hamburger.isVisible(), 'Tablet/mobile hamburger hidden: ' + width);
                    await hamburger.click();
                    check(await page.locator('#site-drawer').evaluate(node => node.open),
                        'Tablet/mobile drawer did not open: ' + width);
                    await page.keyboard.press('Escape');
                    check(!(await page.locator('#site-drawer').evaluate(node => node.open)),
                        'Escape failed to close drawer: ' + width);
                } else {
                    const groups = page.locator('.desktop-nav-group');
                    check(await groups.count() === 2, 'Expected Businesses and Discussions desktop menus');
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
                check(await page.getByText('Real Estate').isVisible(),
                    'Gated Real Estate category is not discoverable');
                check(await page.locator('.market-category-card .market-category-icon svg').count() >= 6,
                    'Marketplace category artwork is not using the shared vector icon set');
                check(await page.getByText('Coming later').isVisible(),
                    'Real Estate is missing its launch status');
                check(await page.locator('.market-category-card[href^="/real-estate"]').count() === 1,
                    'Dedicated Real Estate category is not linked');
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Marketplace horizontal overflow at ' + host + ' ' + width);
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
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                    'Real Estate horizontal overflow at ' + host + ' ' + width);
                if (width === 390 || width === 1440) {
                    await page.screenshot({
                        path: 'test-results/' + (host.includes('brandon') ? 'brandon' : 'province') +
                            '-real-estate-' + width + '.png',
                        fullPage: true, animations: 'disabled',
                    });
                }
                await page.goto(host + '/marketplace');
                await page.locator('a[href="/marketplace?group=vehicles"]').click();
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
                    await page.locator('a[href="/marketplace?group=vehicles&location=brandon"]').click();
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
