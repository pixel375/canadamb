async page => {
    const main = new URL(page.url()).origin;
    const city = 'http://brandonmb.localhost:' + new URL(main).port;
    const check = (pass, message) => { if (!pass) throw new Error(message); };
    const title = 'Brandon heritage workshop';
    const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Winnipeg', year: 'numeric', month: '2-digit', day: '2-digit' })
        .format(new Date(Date.now() + 2 * 86400000));
    const month = day.slice(0, 7);
    async function login(tab, address) {
        await tab.waitForURL(url => url.origin === main && url.pathname === '/login');
        await tab.getByRole('textbox', { name: 'Email', exact: true }).fill(address);
        await tab.getByLabel('Password', { exact: true }).fill('password');
        await tab.getByRole('button', { name: 'Sign in', exact: true }).click();
    }
    async function fit(tab, width, colorScheme) {
        await tab.setViewportSize({ width, height: 850 });
        await tab.emulateMedia({ colorScheme });
        check(await tab.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Overflow at ' + width + 'px');
    }

    await page.context().clearCookies();
    await fit(page, 320, 'dark');
    await page.goto(city + '/discussions/1');
    check(await page.getByRole('link', { name: 'Request event listing' }).count() === 0, 'Guest received an event request control');
    await page.goto(city + '/account/discussions');
    await login(page, 'event-author@example.test');
    await page.waitForURL(city + '/account/discussions');
    check((await page.goto(city + '/discussions/2/event')).status() === 404, 'Wrong-city source permitted a request');
    await page.goto(city + '/discussions/1');
    const options = page.locator('summary[aria-label="Discussion options"]');
    await options.focus();
    await page.keyboard.press('Enter');
    await page.getByRole('link', { name: 'Request event listing' }).click();
    await page.getByRole('heading', { name: 'Request event listing' }).waitFor();
    await fit(page, 320, 'dark');
    await page.locator('input[name="starts_at"]').fill(day + 'T19:00');
    await page.locator('input[name="ends_at"]').fill(day + 'T20:30');
    await page.locator('select[name="category"]').selectOption('community');
    await page.getByRole('textbox', { name: 'Organizer', exact: true }).fill('Workshop volunteers');
    await page.getByRole('textbox', { name: 'Venue', exact: true }).fill('Reading room');
    await page.getByRole('button', { name: 'Submit event request' }).click();
    await page.getByText('Event submitted for review.').waitFor();
    await fit(page, 390, 'light');

    const reviewerContext = await page.context().browser().newContext();
    try {
        const reviewer = await reviewerContext.newPage();
        await reviewer.goto(main + '/admin/moderation?type=event');
        await login(reviewer, 'event-reviewer@example.test');
        await reviewer.getByRole('link', { name: title }).click();
        await fit(reviewer, 1280, 'light');
        await reviewer.getByRole('link', { name: 'Review source discussion' }).waitFor();
        await reviewer.getByRole('button', { name: 'Approve', exact: true }).click();
        await reviewer.getByText('Moderation decision saved.').waitFor();

        await page.goto(city + '/events?month=' + month);
        await fit(page, 768, 'dark');
        const details = page.locator('.agenda-day').filter({ has: page.locator('a[href="/discussions/1"]') });
        const summary = details.locator('summary');
        await summary.getByText('2 events').waitFor();
        await summary.focus();
        await page.keyboard.press('Enter');
        check(!(await details.evaluate(node => node.open)), 'Day did not close by keyboard');
        await page.keyboard.press('Enter');
        await page.getByRole('link', { name: title }).click();
        check(page.url() === city + '/discussions/1', 'Linked event did not open its source');
        await page.getByRole('heading', { name: 'Event details' }).waitFor();
        await page.getByText('Reading room', { exact: false }).waitFor();
        await page.getByRole('heading', { name: /Replies/ }).waitFor();
        await page.goto(main + '/events?month=' + month);
        await page.getByRole('link', { name: title }).click();
        check(page.url() === main + '/discussions/1', 'Provincial reader was sent to a city host');
        check(await page.locator('script[type="application/ld+json"]').count() === 0, 'Noncanonical event emitted structured data');
        await page.goto(city + '/events?month=' + month);
        await page.getByRole('link', { name: 'Legacy archive tour' }).click();
        check(page.url() === city + '/events/1', 'Legacy event lost its standalone page');
        await page.getByText('The standalone archive tour keeps its original page.').waitFor();
        await page.goto(city + '/account/events');
        const listing = page.locator('article').filter({ has: page.getByRole('heading', { name: title }) });
        await listing.getByRole('textbox', { name: 'Private cancellation note' }).fill('Do not disclose this note.');
        await listing.getByRole('button', { name: 'Cancel event' }).click();
        await page.getByText('Event cancelled.').waitFor();
        await page.goto(city + '/discussions/1');
        await page.getByText('Cancelled', { exact: true }).waitFor();
        check(await page.getByText('Do not disclose this note.').count() === 0, 'Private note appeared on discussion');
        console.log('Pass 07 browser checks passed.');
    } finally {
        await reviewerContext.close();
    }
}
