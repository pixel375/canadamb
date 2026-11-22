async page => {
    const main = new URL(page.url()).origin;
    const port = new URL(main).port;
    const city = 'http://brandonmb.localhost:' + port;
    const check = (value, message) => { if (!value) throw new Error(message); };
    async function login(tab, email, next) {
        await tab.goto(main + next);
        await tab.waitForURL(main + '/login');
        await tab.getByLabel('Email', { exact: true }).fill(email);
        await tab.getByLabel('Password', { exact: true }).fill('password');
        await tab.getByRole('button', { name: 'Sign in', exact: true }).click();
        await tab.waitForURL(main + next);
    }
    async function fits(tab, width, scheme) {
        await tab.setViewportSize({ width, height: 850 });
        await tab.emulateMedia({ colorScheme: scheme });
        check(await tab.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Overflow at ' + width + 'px');
    }

    await page.context().clearCookies();
    await fits(page, 320, 'dark');
    await login(page, 'moderation-member@example.test', '/account/profile');
    await page.goto(main + '/community/4');
    await page.getByText('Report discussion', { exact: true }).click();
    await page.getByLabel('Why are you reporting this discussion?').fill('Please check the details in this trail notice.');
    await page.getByRole('button', { name: 'Submit report' }).click();
    await page.getByText('Your report was submitted for review.').waitFor();
    await fits(page, 320, 'dark');
    check((await page.goto(main + '/admin/moderation')).status() === 403, 'Member accessed the admin queue');

    const adminContext = await page.context().browser().newContext();
    try {
        const admin = await adminContext.newPage();
        await fits(admin, 320, 'dark');
        await login(admin, 'moderation-reviewer@example.test', '/admin/moderation');
        await admin.getByRole('heading', { name: 'Moderation', exact: true }).waitFor();
        await fits(admin, 320, 'dark');
        const nav = admin.getByText('Administration sections', { exact: true });
        await nav.click();
        await nav.click();
        await admin.getByLabel('Title or name').fill('Park opening');
        await admin.getByRole('button', { name: 'Apply filters' }).click();
        check(await admin.getByRole('link', { name: 'First trail proposal' }).count() === 0, 'Filter included the wrong submission');
        await admin.getByRole('link', { name: 'Park opening request' }).click();
        await admin.getByLabel('Internal reviewer notes').fill('Review performed in disposable browser QA.');
        await admin.getByRole('button', { name: 'Approve', exact: true }).click();
        await admin.getByText('Moderation decision saved.').waitFor();
        await fits(admin, 390, 'light');

        await admin.goto(main + '/admin/moderation?type=thread');
        await admin.getByRole('checkbox', { name: /First trail proposal/ }).check();
        await admin.getByRole('checkbox', { name: /Second trail proposal/ }).check();
        await admin.getByRole('button', { name: 'Preview selected submissions' }).click();
        await admin.getByRole('heading', { name: 'Bulk review preview' }).waitFor();
        await fits(admin, 768, 'dark');
        await admin.getByRole('button', { name: 'Approve selected' }).click();
        await admin.getByText('2 decision saved / 0 skipped').waitFor();

        await admin.goto(main + '/admin/moderation/reports');
        await admin.getByRole('link', { name: 'Public trail notice' }).waitFor();
        await admin.getByRole('link', { name: 'Conversation report', exact: true }).click();
        await admin.getByRole('button', { name: 'View reported evidence' }).click();
        await admin.getByText('This is the reported message.').waitFor();
        check(await admin.getByText('This unrelated private message stays hidden.').count() === 0, 'Unreported message was visible');
        await fits(admin, 1280, 'light');
        check((await admin.goto(city + '/admin/moderation')).status() === 404, 'Brandon opened central administration');
        console.log('Moderation browser QA passed at 320, 390, 768, and 1280 CSS pixels.');
    } finally {
        await adminContext.close();
    }
}
