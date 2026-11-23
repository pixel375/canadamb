// Run this with Node and an installed Playwright Chromium on an isolated local
// database. The page checks are intentionally the same reviewed source kept in
// navigation-marketplace-discovery.js; no live-site login or remote writes.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({
        viewport: { width: 1440, height: 900 },
        colorScheme: 'light',
    });
    const script = path.join(__dirname, 'navigation-marketplace-discovery.js');
    try {
        await page.goto('http://canadamb.localhost:8000/');
        const source = fs.readFileSync(script, 'utf8').trim().replace(/;$/, '');
        const run = vm.runInThisContext('(' + source + ')', {
            filename: script,
            timeout: 3000,
        });
        if (typeof run !== 'function') throw new Error('Browser script did not export a function');
        await run(page);
        // Preserve visual evidence for the upcoming professional desktop/mobile
        // redesign, rather than treating functional green checks as visual sign-off.
        const screenshots = path.join('test-results', 'screenshots');
        fs.mkdirSync(screenshots, { recursive: true });
        for (const [label, host] of [
            ['canadamb', 'http://canadamb.localhost:8000'],
            ['brandonmb', 'http://brandonmb.localhost:8000'],
        ]) {
            for (const scheme of ['light', 'dark']) {
                await page.emulateMedia({ colorScheme: scheme, reducedMotion: 'reduce' });
                for (const width of [390, 1440]) {
                    await page.setViewportSize({ width, height: 900 });
                    for (const [name, url] of [
                        ['home', '/'],
                        ['marketplace', '/marketplace'],
                    ]) {
                        await page.goto(host + url);
                        const overflow = await page.evaluate(() =>
                            document.documentElement.scrollWidth > innerWidth);
                        if (overflow) {
                            throw new Error('Visual screenshot reveals overflow: ' +
                                label + ' ' + scheme + ' ' + width + ' ' + name);
                        }
                        await page.screenshot({
                            path: path.join(screenshots, label + '-' + scheme +
                                '-' + width + '-' + name + '.png'),
                            fullPage: true,
                            animations: 'disabled',
                        });
                    }
                }
            }
        }
        // Check the header as a real signed-in user, rather than relying
        // exclusively on anonymous screenshots or server-rendered feature tests.
        await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://canadamb.localhost:8000/login');
        await page.getByLabel('Email').fill('navigation-browser@example.test');
        await page.getByLabel('Password').fill('password');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL(url => url.hostname === 'canadamb.localhost' && url.pathname !== '/login');

        for (const width of [390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('http://canadamb.localhost:8000/');
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
            if (overflow) throw new Error('Signed-in header has horizontal overflow at ' + width);
            const topMessages = page.locator('.header-actions a[href="/account/messages"]');
            const topNotices = page.locator('.header-actions a[href="/account/notifications"]');
            if (width >= 761) {
                if (!await topMessages.isVisible() || !await topNotices.isVisible()) {
                    throw new Error('Signed-in Messages/Notifications placement missing at ' + width);
                }
                if (!await topMessages.locator('[aria-label="1 unread messages"]').isVisible()) {
                    throw new Error('Unread private message count missing from desktop header at ' + width);
                }
            } else {
                if (!await page.locator('.mobile-tab-bar a[href="/account/messages"] [aria-label="1 unread messages"]').isVisible()) {
                    throw new Error('Unread private message count missing from mobile navigation');
                }
            }
            if (width === 390 || width === 1440) {
                await page.screenshot({
                    path: path.join(screenshots, 'canadamb-signed-in-' + width + '-home.png'),
                    fullPage: true,
                    animations: 'disabled',
                });
            }
        }
        console.log('PASS: two-host navigation, category discovery, 16 anonymous screenshots and signed-in inbox placement');
    } catch (error) {
        fs.mkdirSync('test-results', { recursive: true });
        try {
            await page.screenshot({ path: 'test-results/navigation-failure.png', fullPage: true });
        } catch {
            // The browser may have closed or failed to start.
        }
        console.error('Browser acceptance failed:', error?.stack || error);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error('Unable to initialize isolated Chromium:', error);
    process.exitCode = 1;
});
