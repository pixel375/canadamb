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
            }
        }
        console.log('PASS: unmerged prelaunch desktop navigation hotfix on two hosts and six widths');
    } catch (error) {
        try { await page.screenshot({ path: 'test-results/prelaunch-nav-failure.png', fullPage: true }); } catch {}
        console.error(error?.stack || error);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
