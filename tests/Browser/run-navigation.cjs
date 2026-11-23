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
        console.log('PASS: category discovery and responsive navigation on both hosts');
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
