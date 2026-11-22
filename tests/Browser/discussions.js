async page => {
    const main = new URL(page.url()).origin;
    const city = 'http://brandonmb.localhost:' + new URL(main).port;
    const check = (condition, message) => { if (!condition) throw new Error(message); };
    const subject = 'Brandon library reading group';
    async function login(tab, email) {
        await tab.waitForURL(url => url.origin === main && url.pathname === '/login');
        await tab.getByLabel('Email', { exact: true }).fill(email);
        await tab.getByLabel('Password', { exact: true }).fill('password');
        await tab.getByRole('button', { name: 'Sign in', exact: true }).click();
    }
    async function viewport(tab, width, scheme) {
        await tab.setViewportSize({ width, height: 850 });
        await tab.emulateMedia({ colorScheme: scheme });
        check(await tab.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Horizontal overflow at ' + width);
    }

    await page.context().clearCookies();
    await viewport(page, 320, 'dark');
    await page.goto(main + '/discussions/new');
    await login(page, 'discussions-author@example.test');
    await page.getByLabel('Title', { exact: true }).fill(subject);
    await page.locator('select[name="category"]').selectOption('general');
    await page.locator('select[name="location_id"]').selectOption({ label: 'Brandon' });
    await page.getByRole('textbox', { name: 'Post', exact: true }).fill('Reading group members are invited to share their recommendations.');
    await page.getByRole('button', { name: 'Submit discussion' }).click();
    await page.getByRole('heading', { name: 'Manage discussion' }).waitFor();
    await page.getByText('Pending review', { exact: false }).first().waitFor();
    await viewport(page, 320, 'dark');
    const preview = new URL(page.url()).pathname;
    const id = preview.match(/\/(\d+)\/preview$/)?.[1];
    check(id, 'Author did not land on the private preview');
    const thread = '/discussions/' + id;
    const moderator = await page.context().browser().newContext();
    const reader = await page.context().browser().newContext();
    try {
        const reviewer = await moderator.newPage();
        await viewport(reviewer, 1280, 'light');
        await reviewer.goto(main + '/admin/moderation/items/thread/' + id);
        await login(reviewer, 'discussions-reviewer@example.test');
        await reviewer.getByRole('button', { name: 'Approve', exact: true }).click();
        await reviewer.getByText('Moderation decision saved.').waitFor();

        await page.goto(main + '/community/' + id);
        check(page.url() === main + thread, 'Legacy provincial address changed browser host');
        check(await page.locator('link[rel="canonical"]').getAttribute('href') === city + thread, 'City canonical missing');
        await page.getByRole('button', { name: 'Follow discussion' }).click();
        await page.getByRole('button', { name: 'Stop following' }).waitFor();

        const visitor = await reader.newPage();
        await viewport(visitor, 390, 'light');
        check((await visitor.goto(city + '/discussions/1')).status() === 404, 'Province-wide thread appeared in Brandon');
        await visitor.goto(city + '/community/' + id);
        await visitor.locator('a[href="' + thread + '/participate"]').click();
        await login(visitor, 'discussions-reader@example.test');
        await visitor.waitForURL(city + thread + '#discussion-reply');
        await viewport(visitor, 390, 'light');
        await visitor.getByRole('textbox', { name: 'Write a reply' }).fill('I can bring extra copies for the reading group.');
        await visitor.getByRole('button', { name: 'Submit reply' }).click();
        await visitor.getByText('Reply submitted for review.').waitFor();
        check(await visitor.getByText('I can bring extra copies for the reading group.', { exact: true }).count() === 0, 'Pending reply was public');
        await visitor.getByText('Report discussion', { exact: true }).click();
        await visitor.getByLabel('Why are you reporting this discussion?').fill('Please check the opening time in this discussion.');
        await visitor.getByRole('button', { name: 'Submit report' }).click();
        await visitor.getByText('Your report was submitted for review.').waitFor();

        await reviewer.goto(main + '/admin/moderation?type=reply');
        await reviewer.getByRole('link', { name: subject }).click();
        await reviewer.getByRole('button', { name: 'Approve', exact: true }).click();
        await reviewer.getByText('Moderation decision saved.').waitFor();
        await visitor.reload();
        await visitor.getByText('I can bring extra copies for the reading group.', { exact: true }).waitFor();
        const permalink = await visitor.getByRole('link', { name: 'Link to reply' }).getAttribute('href');
        await visitor.getByRole('link', { name: 'Link to reply' }).click();
        const reply = permalink.split('/').pop();
        check(visitor.url() === city + thread + '?page=1#reply-' + reply, 'Reply permalink changed viewer host or missed its anchor');
        await visitor.getByRole('button', { name: 'Quote reply' }).focus();
        await visitor.keyboard.press('Enter');
        check((await visitor.getByRole('textbox', { name: 'Write a reply' }).inputValue()).includes('extra copies'), 'Keyboard quote did not reach the composer');
        await viewport(visitor, 768, 'dark');

        await page.goto(main + '/account/notifications?category=discussions');
        await page.getByText('New reply in a discussion you follow').waitFor();
        await page.goto(main + preview);
        await page.getByRole('link', { name: 'Edit discussion' }).click();
        await page.getByRole('textbox', { name: 'Post', exact: true }).fill('The group details have changed and require a fresh moderator review.');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await page.getByText('Your changes were submitted for review.').waitFor();
        check((await visitor.goto(city + thread)).status() === 404, 'Changed pending thread was public');
        await reviewer.goto(main + '/admin/moderation/items/thread/' + id);
        await reviewer.getByLabel(/Reason shown to the submitter/).fill('Please include the library schedule.');
        await reviewer.getByLabel('Internal reviewer notes').fill('Private test decision detail.');
        await reviewer.getByRole('button', { name: 'Reject', exact: true }).click();
        await reviewer.getByText('Moderation decision saved.').waitFor();
        await page.reload();
        await page.getByText(/Please include the library schedule/).waitFor();
        check(await page.getByText('Private test decision detail.').count() === 0, 'Private note was exposed to the author');
        await viewport(page, 1280, 'light');
        console.log('Discussions passed local Chromium checks at 320, 390, 768 and 1280 CSS pixels.');
    } finally {
        await Promise.all([moderator.close(), reader.close()]);
    }
}
