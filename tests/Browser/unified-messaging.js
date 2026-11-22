async page => {
    const port = new URL(page.url()).port || '8000';
    const main = 'http://canadamb.localhost:' + port;
    const city = 'http://brandonmb.localhost:' + port;
    const check = (value, message) => { if (!value) throw new Error(message); };
    async function login(tab, email, destination) {
        await tab.waitForURL(url => url.origin === main && url.pathname === '/login');
        await tab.getByLabel('Email', { exact: true }).fill(email);
        await tab.getByLabel('Password', { exact: true }).fill('password');
        await Promise.all([
            tab.waitForURL(destination),
            tab.getByRole('button', { name: 'Sign in', exact: true }).click(),
        ]);
    }
    async function send(tab, label, text) {
        await tab.getByRole('textbox', { name: label, exact: true }).fill(text);
        await Promise.all([
            tab.waitForURL(url => /^\/account\/messages\/\d+$/.test(url.pathname) && url.origin === city),
            tab.getByRole('button', { name: 'Send message', exact: true }).click(),
        ]);
        return new URL(tab.url()).pathname;
    }
    function staticPdf() {
        const objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>',
            '<< /Length 3 >>\nstream\nq Q\nendstream',
        ];
        let pdf = '%PDF-1.4\n%âãÏÓ\n';
        const offsets = [];
        for (const [index, object] of objects.entries()) {
            offsets.push(pdf.length);
            pdf += (index + 1) + ' 0 obj\n' + object + '\nendobj\n';
        }
        return Buffer.from(pdf + 'xref\n0 5\n0000000000 65535 f \n'
            + offsets.map(offset => String(offset).padStart(10, '0') + ' 00000 n \n').join('')
            + 'trailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n' + pdf.length + '\n%%EOF\n', 'latin1');
    }
    async function fits(tab, width, scheme) {
        await tab.setViewportSize({ width, height: 840 });
        await tab.emulateMedia({ colorScheme: scheme });
        check(await tab.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Layout overflowed at ' + width);
    }
    async function fetchPrivate(tab, path) {
        return tab.evaluate(async url => {
            const response = await fetch(url);
            return {
                ok: response.ok,
                status: response.status,
                type: response.headers.get('content-type'),
                disposition: response.headers.get('content-disposition'),
                cache: response.headers.get('cache-control'),
                bytes: response.ok ? Array.from(new Uint8Array(await response.arrayBuffer())) : [],
            };
        }, path);
    }

    await page.context().clearCookies();
    await fits(page, 320, 'light');
    await page.goto(city + '/marketplace/1');
    await page.getByRole('link', { name: 'Sign in to message' }).click();
    await login(page, 'buyer-messages@example.test', url => url.origin === city && url.pathname === '/marketplace/1');
    const png = await page.evaluate(() => {
        const canvas = document.createElement('canvas');
        canvas.width = 120;
        canvas.height = 80;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#2759a5';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        return canvas.toDataURL().split(',')[1];
    });
    await page.locator('input[name="attachments[]"]').setInputFiles([
        { name: 'desk.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') },
        { name: 'document.pdf', mimeType: 'application/pdf', buffer: staticPdf() },
    ]);
    const marketplace = await send(page, 'Message the seller', 'Browser private message about this desk.');
    const photo = await page.getByRole('link', { name: /Open photo/ }).getAttribute('href');
    const pdf = await page.getByRole('link', { name: 'Download document.pdf' }).getAttribute('href');
    const imageResponse = await fetchPrivate(page, photo);
    check(imageResponse.ok && imageResponse.type === 'image/webp', 'Authorized photo was not normalized WebP');
    const download = await fetchPrivate(page, pdf);
    check(download.ok && download.disposition.startsWith('attachment;')
        && download.cache.includes('no-store')
        && Buffer.from(download.bytes).equals(staticPdf()), 'PDF download did not retain bytes or private headers');

    await page.goto(city + '/businesses/browser-store');
    await page.getByRole('link', { name: /Message business in/ }).click();
    await page.getByRole('heading', { name: 'Message Browser Store' }).waitFor();
    const business = await send(page, 'Message', 'Browser private question for this branch.');
    await page.goto(city + '/jobs/1');
    await page.getByRole('link', { name: 'Message employer' }).click();
    await page.getByRole('heading', { name: 'Message Browser Sales Assistant' }).waitFor();
    const job = await send(page, 'Message', 'Browser private hiring question.');
    check(new Set([marketplace, business, job]).size === 3, 'Messaging contexts did not get separate threads');
    await page.goto(city + '/account/messages?type=job&q=Sales');
    await page.getByRole('link', { name: 'Browser Sales Assistant' }).waitFor();
    check(await page.getByRole('link', { name: 'Browser Desk' }).count() === 0, 'Job filter included Marketplace');
    for (const [width, scheme] of [[320, 'light'], [390, 'dark'], [768, 'light'], [1280, 'dark']]) {
        await fits(page, width, scheme);
        await page.goto(city + business);
        await fits(page, width, scheme);
    }

    const browser = page.context().browser();
    const employer = await browser.newPage();
    const seller = await browser.newPage();
    const admin = await browser.newPage();
    try {
        await employer.goto(city + '/account/messages');
        await login(employer, 'employer-messages@example.test', city + '/account/messages');
        await employer.getByText('2 unread across this portal').waitFor();
        await employer.goto(city + business);
        await employer.getByText('Browser private question for this branch.', { exact: true }).waitFor();
        await employer.getByRole('textbox', { name: 'Reply', exact: true }).fill('Browser private reply from the representative.');
        await employer.getByRole('button', { name: 'Send reply' }).click();
        await employer.getByText('Browser private reply from the representative.', { exact: true }).waitFor();
        await employer.goto(city + '/account/notifications?status=unread');
        await employer.getByText('New job message', { exact: true }).waitFor();
        check(await employer.getByText('New business message', { exact: true }).count() === 0, 'Reading chat did not clear its notification');
        await employer.goto(city + job);
        await employer.getByText('Browser private hiring question.', { exact: true }).waitFor();

        await seller.goto(city + marketplace);
        await login(seller, 'seller-messages@example.test', city + marketplace);
        await seller.getByRole('link', { name: 'Download document.pdf' }).waitFor();
        await seller.getByRole('button', { name: 'Block member' }).click();
        await seller.getByText(/attachment access are blocked/).waitFor();
        check((await fetchPrivate(page, pdf)).status === 404, 'A blocked participant could still download the PDF');

        await admin.goto(main + '/admin');
        await login(admin, 'admin-messages@example.test', main + '/admin');
        check((await fetchPrivate(admin, marketplace)).status === 404
            && (await fetchPrivate(admin, photo)).status === 404, 'Network administration bypassed private messaging permissions');
    } finally {
        await Promise.all([employer.close(), seller.close(), admin.close()]);
    }
    return 'Three messaging contexts, SSO, private photo/PDF, unread counts, blocking, role boundaries and narrow/wide layouts passed.';
}
