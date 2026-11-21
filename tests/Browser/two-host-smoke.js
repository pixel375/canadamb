async page => {
    const mainland = 'http://canadamb.localhost:8000';
    const brandon = 'http://brandonmb.localhost:8000';
    const check = (value, message) => { if (!value) throw new Error(message); };
    await page.context().clearCookies();

    await page.setViewportSize({ width: 1440, height: 1100 });
    await page.goto(mainland);
    check(await page.getByRole('heading', { name: 'Your Manitoba. Your community.' }).isVisible(), 'CanadaMB hero missing');
    check(await page.getByRole('link', { name: 'Communities', exact: true }).isVisible(), 'Provincial communities navigation missing');
    check(await page.locator('img.hero-image').evaluate(img => img.complete && img.naturalWidth > 0), 'Manitoba image failed to load');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(brandon);
    check(await page.getByRole('heading', { name: 'Your Brandon. Your community.' }).isVisible(), 'BrandonMB hero missing');
    check(await page.getByRole('link', { name: 'Communities', exact: true }).count() === 0, 'Provincial menu leaked into Brandon navigation');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Mobile homepage overflows');
    check(await page.locator('.site-accent').evaluate(el => getComputedStyle(el).backgroundColor) === 'rgb(34, 160, 107)', 'Brandon accent did not render');
    const navigation = page.getByRole('navigation', { name: 'Main navigation' });
    check(await navigation.getByRole('link', { name: 'Community', exact: true }).isVisible(), 'Community navigation is missing');
    check(await navigation.getByRole('link', { name: 'Jobs', exact: true }).isVisible(), 'Jobs navigation is missing');
    await page.goto(brandon + '/events?month=2027-03');
    check(await page.getByRole('region', { name: 'Monthly event calendar' }).isVisible(), 'Brandon event calendar did not render');
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Mobile calendar overflows');
    await page.goto(brandon);

    await page.getByLabel('Appearance').selectOption('dark');
    await page.reload();
    check(await page.locator('html').getAttribute('data-theme') === 'dark', 'Theme preference did not persist');
    await page.getByLabel('Appearance').selectOption('system');

    await page.goto(brandon + '/marketplace/new');
    await page.waitForURL(url => url.origin === mainland && url.pathname === '/login' && /^[a-zA-Z0-9]{40}$/.test(url.searchParams.get('continuation') || ''));
    await page.getByLabel('Email').fill('browser-qa@example.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(brandon + '/marketplace/new');
    check(await page.getByLabel('Location').getAttribute('readonly') !== null, 'Brandon location is editable');
    check(await page.getByLabel('Location').inputValue() === 'Brandon', 'Brandon location was not preassigned');

    const suffix = Date.now();
    const cityListing = 'Browser QA Brandon table ' + suffix;
    const ruralListing = 'Browser QA Elkhorn table ' + suffix;
    async function fillListing(title) {
        await page.getByLabel('Title', { exact: true }).fill(title);
        await page.getByLabel('Description', { exact: true }).fill('Disposable two-host smoke test listing.');
        await page.getByRole('combobox', { name: 'Category', exact: true }).selectOption('furniture');
        await page.getByRole('combobox', { name: 'Condition', exact: true }).selectOption('good');
        await page.getByLabel('Price (CAD)').fill('22.25');
    }
    await fillListing(cityListing);
    await page.getByRole('button', { name: 'Submit for review' }).click();
    await page.waitForURL(brandon + '/account/listings');

    await page.goto(mainland + '/marketplace/new');
    await fillListing(ruralListing);
    await page.getByRole('combobox', { name: 'Location', exact: true }).selectOption({ label: 'Elkhorn' });
    await page.getByRole('button', { name: 'Submit for review' }).click();
    await page.waitForURL(mainland + '/account/listings');

    await page.goto(mainland + '/admin/moderation');
    for (const title of [cityListing, ruralListing]) {
        const review = page.locator('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
        await review.getByRole('button', { name: 'Approve' }).click();
        await page.waitForURL(mainland + '/admin/moderation');
    }
    await page.goto(mainland + '/marketplace');
    check(await page.getByRole('link', { name: cityListing }).isVisible(), 'Provincial feed is missing Brandon content');
    check(await page.getByRole('link', { name: ruralListing }).isVisible(), 'Provincial feed is missing rural content');
    await page.getByRole('link', { name: cityListing }).click();
    await page.waitForURL(url => url.origin === brandon && /^\/marketplace\/\d+$/.test(url.pathname));
    await page.goto(brandon + '/marketplace');
    check(await page.getByRole('link', { name: cityListing }).isVisible(), 'Brandon feed is missing its approved item');
    check(await page.getByRole('link', { name: ruralListing }).count() === 0, 'Rural content leaked into Brandon');
    for (const title of [cityListing, ruralListing]) {
        await page.goto(mainland + '/account/listings');
        const owned = page.locator('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
        await owned.getByRole('button', { name: 'Remove' }).click();
        await page.waitForURL(mainland + '/account/listings');
    }

    const cookies = (await page.context().cookies()).filter(cookie => cookie.name.endsWith('-session'));
    const networkCookie = cookies.find(cookie => cookie.domain === 'canadamb.localhost');
    const brandonCookie = cookies.find(cookie => cookie.domain === 'brandonmb.localhost');
    check(networkCookie?.httpOnly && brandonCookie?.httpOnly && networkCookie.value !== brandonCookie.value, 'Separate host-only sessions missing');

    await page.setViewportSize({ width: 1440, height: 1100 });
    await page.goto(mainland + '/admin/theme');
    check(await page.getByRole('heading', { name: 'Theme & appearance' }).isVisible(), 'Central admin unavailable after SSO');
    await page.getByLabel('Theme preset').selectOption('purple');
    check(await page.locator('.theme-sample').first().locator('span').evaluate(el => getComputedStyle(el).backgroundColor) === 'rgb(124, 58, 237)', 'Theme preview did not update');
    await page.getByRole('button', { name: 'Reset to defaults' }).click();
    await page.goto(brandon + '/account/listings');
    await page.getByRole('button', { name: 'Sign out everywhere' }).click();
    await page.waitForURL(brandon + '/');
    await page.goto(mainland + '/admin');
    await page.waitForURL(mainland + '/login');
}
