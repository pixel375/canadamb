async page => {
    const port = new URL(page.url()).port || '8000';
    const main = 'http://canadamb.localhost:' + port;
    const city = 'http://brandonmb.localhost:' + port;
    const check = (value, message) => { if (!value) throw new Error(message); };
    const size = width => page.setViewportSize({ width, height: 850 });
    const fits = () => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
    const photos = page.locator('[data-photo-entry]');
    const picker = page.locator('[data-photo-picker]');
    const button = name => page.getByRole('button', { name, exact: true });
    async function signIn(target, email, path) {
        await target.waitForURL(url => url.origin === main && url.pathname === '/login');
        await target.getByLabel('Email', { exact: true }).fill(email);
        await target.getByLabel('Password', { exact: true }).fill('password');
        await Promise.all([
            target.waitForURL(url => url.origin === city && url.pathname === path),
            target.getByRole('button', { name: 'Sign in', exact: true }).click(),
        ]);
    }
    async function upload(name, width, height, color) {
        const data = await page.evaluate(({ width, height, color }) => {
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d');
            context.fillStyle = color;
            context.fillRect(0, 0, width, height);
            return canvas.toDataURL('image/png').split(',')[1];
        }, { width, height, color });
        return { name, mimeType: 'image/png', buffer: Buffer.from(data, 'base64') };
    }
    async function choose(trigger, file) {
        // Exercise the control's picker mode and provide files through Chromium's upload API.
        await trigger.evaluate(el => {
            document.querySelector('[data-photo-picker]').addEventListener('click', event => event.preventDefault(), { once: true });
            el.click();
        });
        await picker.setInputFiles(file);
    }
    const listingRow = title => page.locator('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
    async function applyStatus(row, status) {
        await Promise.all([
            page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/status')),
            row.locator('button[name="status"][value="' + status + '"]').click(),
        ]);
        await page.waitForURL(city + '/account/listings');
    }

    await page.context().clearCookies();
    await size(320);
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(city + '/marketplace/new');
    await signIn(page, 'browser-qa@example.test', '/marketplace/new');
    check(await page.getByLabel('Location', { exact: true }).inputValue() === 'Brandon', 'Brandon location changed');
    const title = 'Marketplace browser photo set ' + Date.now();
    await page.getByLabel('Title', { exact: true }).fill(title);
    await page.getByLabel('Description', { exact: true }).fill('Disposable browser listing with tall and wide photos.');
    await page.locator('select[name="category"]').selectOption('furniture');
    await page.locator('select[name="condition"]').selectOption('good');
    await page.getByLabel('Price (CAD)').fill('22.25');
    await picker.setInputFiles([
        await upload('wide.png', 900, 360, '#b04020'),
        await upload('tall.png', 360, 900, '#156921'),
        await upload('square.png', 480, 480, '#193da9'),
    ]);
    check(await photos.count() === 3 && await photos.last().isVisible() && await fits(), 'Upload editor lost visible previews or overflowed at 320px');
    await button('Submit for review').click();
    await page.waitForURL(city + '/account/listings');
    const edit = await listingRow(title).getByRole('link', { name: 'Edit', exact: true }).getAttribute('href');
    const listing = edit.split('/')[3];

    await page.goto(city + edit);
    const existing = await photos.evaluateAll(items => items.map(item => item.dataset.photoEntry));
    check(existing.length === 3 && existing.every(ref => ref.startsWith('existing:')), 'Edit did not retain the uploaded photos');
    await button('Save changes').click();
    await page.waitForURL(city + '/account/listings');
    await page.goto(city + edit);
    check((await photos.evaluateAll(items => items.map(item => item.dataset.photoEntry))).join() === existing.join(), 'Unchanged edit removed photos');
    await photos.nth(2).locator('[data-photo-earlier]').click();
    await photos.nth(2).locator('[data-photo-remove]').click();
    await choose(photos.first().locator('[data-photo-replace-one]'), await upload('replacement.png', 1000, 520, '#6b2db4'));
    await choose(page.locator('[data-photo-add]'), await upload('additional.png', 420, 600, '#683915'));
    check(await photos.count() === 3, 'Replace and add did not keep the intended total');
    check(await photos.nth(1).getAttribute('data-photo-entry') === existing[2], 'Expected retained photo is out of order');
    await button('Save changes').click();
    await page.waitForURL(city + '/account/listings');
    await page.goto(city + edit);
    check(await photos.nth(1).getAttribute('data-photo-entry') === existing[2], 'Retained ID or order was not saved');

    await size(1280);
    await page.goto(main + '/admin/moderation');
    await listingRow(title).getByRole('button', { name: 'Approve', exact: true }).click();
    await page.waitForURL(main + '/admin/moderation');
    const detail = '/marketplace/' + listing;
    for (const width of [320, 360, 390, 768, 1280]) {
        await size(width);
        await page.emulateMedia({ colorScheme: width === 360 || width === 1280 ? 'dark' : 'light' });
        await page.goto(main + detail);
        check(page.url() === main + detail, 'Provincial browsing changed domains');
        check(await page.locator('link[rel="canonical"]').getAttribute('href') === city + detail, 'Brandon canonical is missing');
        check(await fits(), 'Published detail overflowed at ' + width);
    }
    await size(390);
    const gallery = page.locator('[data-gallery-dialog]');
    const preview = gallery.locator('[data-gallery-preview]');
    const first = page.locator('[data-gallery-item]').first();
    await first.click();
    check(await gallery.isVisible() && (await preview.getAttribute('src')).includes('/photo/'), 'Lightbox did not use the larger photo');
    const firstSrc = await preview.getAttribute('src');
    await page.keyboard.press('ArrowRight');
    const secondSrc = await preview.getAttribute('src');
    check(secondSrc !== firstSrc, 'Right arrow did not advance the gallery');
    await preview.evaluate(img => {
        const start = new Touch({ identifier: 1, target: img, clientX: 260, clientY: 110 });
        const end = new Touch({ identifier: 1, target: img, clientX: 90, clientY: 113 });
        img.dispatchEvent(new TouchEvent('touchstart', { bubbles: true, touches: [start], changedTouches: [start] }));
        img.dispatchEvent(new TouchEvent('touchend', { bubbles: true, touches: [], changedTouches: [end] }));
    });
    check(await preview.getAttribute('src') !== secondSrc, 'Horizontal swipe did not advance the gallery');
    await page.keyboard.press('Escape');
    await gallery.waitFor({ state: 'hidden' });
    await page.waitForFunction(() => document.activeElement === document.querySelector('[data-gallery-item]'));

    const buyer = await page.context().browser().newPage();
    try {
        await buyer.goto(city + detail);
        const contact = buyer.getByRole('link', { name: 'Sign in to message', exact: true });
        await contact.click();
        await signIn(buyer, 'browser-buyer@example.test', detail);
        check(await buyer.getByRole('button', { name: 'Send message', exact: true }).isVisible(), 'Central sign-in did not return the buyer to seller contact');
    } finally {
        await buyer.close();
    }

    await page.goto(city + '/marketplace?q=' + encodeURIComponent(title) + '&category=furniture&condition=good&min_price=20&max_price=23&sort=price_asc');
    await page.getByRole('link', { name: title, exact: true }).waitFor();
    await page.goto(main + '/marketplace?location=elkhorn');
    await page.getByRole('link', { name: 'Marketplace browser fixture rural', exact: true }).waitFor();
    await page.goto(city + '/marketplace?location=elkhorn');
    check(await page.getByRole('link', { name: 'Marketplace browser fixture rural', exact: true }).count() === 0, 'Rural listing leaked to Brandon');
    await page.goto(city + '/account/listings');
    await applyStatus(listingRow(title), 'sold');
    check(/sold/i.test(await listingRow(title).textContent()), 'Seller listing did not become sold');
    await applyStatus(listingRow(title), 'pending');
    check(/pending/i.test(await listingRow(title).textContent()), 'Relisting did not return the sold listing to review');
    const expired = listingRow('Marketplace browser fixture expired');
    check(/expired/i.test(await expired.textContent()), 'Expired listing is absent from owner management');
    await applyStatus(expired, 'pending');
    check(/pending/i.test(await expired.textContent()), 'Expired listing did not return to review');

    return 'Marketplace photo creation, unchanged edit, individual replacement, order, gallery keyboard/swipe, SSO, filters, and sold/expired relisting passed at local test widths.';
}
