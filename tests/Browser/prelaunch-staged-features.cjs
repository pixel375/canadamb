const { chromium } = require('playwright');
const fs = require('node:fs');
const host = 'http://canadamb.localhost:8001';
const check = (value, message) => { if (!value) throw new Error(message); };
const password = 'New-browser-qa-42';

(async () => {
    fs.mkdirSync('test-results', { recursive: true });
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const admin = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const visit = async (p, path) => {
        const response = await p.goto(host + path);
        check(response?.ok(), path + ': HTTP ' + response?.status());
    };
    const login = async (p, email, secret) => {
        await visit(p, '/login');
        await p.getByLabel('Email').fill(email);
        await p.locator('input[name="password"]').fill(secret);
        await p.getByRole('button', { name: 'Sign in', exact: true }).click();
        await p.waitForURL(url => !url.pathname.startsWith('/login'));
    };
    const submit = async (p, button, path) => {
        await Promise.all([p.waitForURL(url => url.pathname === path, { waitUntil: 'load' }), button.click()]);
        await p.waitForLoadState('load');
    };
    try {
        await login(page, 'browser-notifications@example.test', password);
        await visit(page, '/jobs?q=technician');
        await page.getByText('Save this job search', { exact: true }).click();
        await page.locator('form[action="/account/job-searches"] input[name="name"]').fill('Mobile QA search');
        await submit(page, page.getByRole('button', { name: 'Save search', exact: true }), '/account/job-searches');
        const saved = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Mobile QA search' }) });
        await saved.getByText('Search and alert preferences', { exact: true }).click();
        await saved.locator('select[name="alerts"]').selectOption('0');
        await saved.getByRole('button', { name: 'Save preferences' }).click();
        await page.getByText('Alerts paused', { exact: true }).waitFor();
        await saved.getByRole('link', { name: 'Edit filters', exact: true }).click();
        await page.locator('form[action^="/account/job-searches/"] input[name="q"]').fill('mechanic');
        await submit(page, page.getByRole('button', { name: 'Save filters', exact: true }), '/account/job-searches');
        await saved.getByRole('link', { name: 'Open original search' }).click();
        await page.waitForURL(url => url.pathname === '/jobs' && url.searchParams.get('q') === 'mechanic');
        console.log('PASS: save job search, pause alerts and revise filters');

        await visit(page, '/marketplace?q=oak');
        await page.getByText('Save this Marketplace search', { exact: true }).click();
        await page.locator('form[action="/account/marketplace-searches"] input[name="name"]').fill('Mobile Marketplace search');
        await submit(page, page.getByRole('button', { name: 'Save search', exact: true }), '/account/marketplace-searches');
        const marketSearch = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Mobile Marketplace search' }) });
        await marketSearch.getByText('Rename search', { exact: true }).click();
        await marketSearch.locator('input[name="name"]').fill('Oak furniture search');
        await marketSearch.getByRole('button', { name: 'Save name' }).click();
        await page.getByRole('heading', { name: 'Oak furniture search' }).waitFor();
        await page.getByRole('link', { name: 'Run search', exact: true }).click();
        await page.waitForURL(url => url.pathname === '/marketplace' && url.searchParams.get('q') === 'oak');
        await visit(page, '/account/marketplace-searches');
        await page.locator('article').filter({ has: page.getByRole('heading', { name: 'Oak furniture search' }) }).getByRole('link', { name: 'Edit filters' }).click();
        await page.locator('form[action^="/account/marketplace-searches/"] input[name="max_price"]').fill('75');
        await submit(page, page.getByRole('button', { name: 'Save filters', exact: true }), '/account/marketplace-searches');
        await page.getByRole('link', { name: 'Run search', exact: true }).click();
        await page.waitForURL(url => url.pathname === '/marketplace' && Number(url.searchParams.get('max_price')) === 75);
        console.log('PASS: saved Marketplace search, rename, revise and run');

        await visit(page, '/account/notifications');
        await page.locator('select[name="discussion_replies"]').selectOption('0');
        await page.locator('select[name="job_summary"]').selectOption('0');
        await page.getByRole('button', { name: 'Save notification preferences' }).click();
        await page.getByText('Job alert summaries are hidden by your preferences.').waitFor();
        check(await page.locator('select[name="discussion_replies"]').inputValue() === '0', 'Preference was not retained');
        console.log('PASS: account-wide notification settings');

        await visit(page, '/account/privacy-requests');
        const intake = page.locator('form[method="post"][action="/account/privacy-requests"]');
        await intake.locator('select[name="kind"]').selectOption('data-review');
        await intake.locator('input[name="confirm_request"]').check();
        await intake.locator('input[name="current_password"]').fill(password);
        await intake.getByRole('button', { name: 'Submit private request' }).click();
        await page.getByRole('heading', { name: 'Data review', exact: true }).waitFor();
        await login(admin, 'browser-admin@example.test', 'browser-qa-admin-password-only');
        await visit(admin, '/admin/privacy-requests');
        await admin.getByRole('heading', { name: 'Data review', exact: true }).waitFor();
        const review = admin.locator('article').filter({ has: admin.getByRole('heading', { name: 'Data review', exact: true }) });
        await review.locator('textarea[name="note"]').fill('Disposable QA review progress only.');
        await review.locator('input[name="current_password"]').fill('browser-qa-admin-password-only');
        await review.getByRole('button', { name: 'Record review progress' }).click();
        await visit(page, '/account/privacy-requests');
        const request = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Data review', exact: true }) });
        await request.getByText('Withdraw request', { exact: true }).click();
        await request.locator('input[name="current_password"]').fill(password);
        await request.getByRole('button', { name: 'Confirm withdrawal' }).click();
        await page.getByText(/Reference #.*Withdrawn/).waitFor();
        console.log('PASS: private request intake, admin review and member withdrawal');

        await visit(page, '/account/businesses');
        const business = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Browser QA Team' }) });
        await business.getByRole('link', { name: 'Manage profile' }).click();
        await page.locator('select[name="business_inquiries"]').selectOption('1');
        await page.locator('select[name="job_inquiries"]').selectOption('0');
        await page.getByRole('button', { name: 'Save inquiry preferences' }).click();
        check(await page.locator('select[name="business_inquiries"]').inputValue() === '1', 'Business opt-in not persisted');
        await page.getByRole('link', { name: 'Manage branches and contact verification' }).click();
        const branch = page.locator('form').filter({ has: page.getByRole('button', { name: 'Request new branch' }) });
        await branch.locator('select[name="location_id"]').selectOption({ label: 'Brandon' });
        await branch.locator('input[name="address"]').fill('991 QA Branch Avenue');
        await branch.getByRole('button', { name: 'Request new branch' }).click();
        await page.getByText('991 QA Branch Avenue · Pending', { exact: true }).waitFor();
        await visit(page, '/account/businesses');
        await business.getByRole('link', { name: 'Team', exact: true }).click();
        await page.getByRole('button', { name: 'Send access request' }).waitFor();
        check(await page.getByRole('button', { name: 'Grant access' }).count() === 0, 'Legacy direct grant shown');
        console.log('PASS: business inquiry opt-in, branch review request and staged invitation form');

        await visit(page, '/account/messages?q=oak');
        await page.locator('.message-thread').click();
        const conversation = new URL(page.url()).pathname;
        check(await page.locator('input[name="photo"]').count() === 1, 'Photo input missing');
        check(!(await page.locator('input[name="photo"]').isVisible()), 'Photo picker should begin collapsed');
        await page.locator('.chat-attachment-picker summary').click();
        await page.locator('input[name="photo"]').waitFor();
        await page.locator('.chat-attachment-picker summary').click();
        for (const width of [320, 390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.locator('.chat-actions summary').click();
            for (const name of ['Archive and mark read', 'Block member']) {
                const button = page.getByRole('button', { name, exact: true });
                check(await button.evaluate(el => {
                    const r = el.getBoundingClientRect();
                    return el.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
                }), name + ' obstructed at ' + width);
            }
            await page.locator('.chat-actions summary').click();
        }
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('.chat-actions summary').click();
        await page.getByRole('button', { name: 'Archive and mark read' }).click();
        await visit(page, '/account/messages?view=archived');
        await page.locator('.message-thread').filter({ hasText: 'oak bookshelf' }).click();
        await page.locator('.chat-actions summary').click();
        await page.getByRole('button', { name: 'Restore conversation' }).click();
        await visit(page, '/account/messages?q=oak');
        const conversationId = conversation.split('/').pop();
        const selectedConversation = page.locator('input[form="conversation-batch"][value="' + conversationId + '"]');
        await page.getByText('Archive selected conversations', { exact: true }).click();
        await selectedConversation.check();
        await page.locator('#conversation-batch input[name="confirm_selection"]').check();
        await submit(page, page.locator('#conversation-batch').getByRole('button', { name: 'Archive selected', exact: true }), '/account/messages');
        await page.waitForURL(url => url.searchParams.get('view') === 'archived');
        await page.locator('.message-thread').filter({ hasText: 'oak bookshelf' }).waitFor();
        await page.getByText('Restore selected conversations', { exact: true }).click();
        await selectedConversation.check();
        await page.locator('#conversation-batch input[name="confirm_selection"]').check();
        await submit(page, page.locator('#conversation-batch').getByRole('button', { name: 'Restore selected', exact: true }), '/account/messages');
        await visit(page, conversation);
        check(await page.locator('input[name="photo"]').count() === 1, 'Photo input missing');
        check(!(await page.locator('input[name="photo"]').isVisible()), 'Photo picker should begin collapsed');
        await page.locator('.chat-attachment-picker summary').click();
        await page.locator('input[name="photo"]').waitFor();
        await page.locator('.chat-attachment-picker summary').click();
        console.log('PASS: individual and selected conversation archive/restoration and optional photo control');

        await visit(page, '/events/new');
        check(new URL(page.url()).pathname === '/community/new', 'Legacy event link did not use the discussion composer');
        await page.locator('input[name="title"]').fill('Recurring browser QA event');
        await page.locator('select[name="category"]').selectOption({ index: 1 });
        await page.locator('select[name="location_id"]').selectOption({ label: 'Brandon' });
        await page.locator('input[name="venue"]').fill('QA community hall');
        await page.locator('input[name="organizer"]').fill('QA organizers');
        const date = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
        await page.locator('input[name="starts_at"]').fill(date + 'T10:00');
        await page.locator('input[name="ends_at"]').fill(date + 'T11:00');
        await page.locator('select[name="repeat"]').selectOption('weekly');
        await page.locator('select[name="occurrences"]').selectOption('2');
        await page.locator('textarea[name="body"]').fill('A disposable recurring event for browser verification.');
        const submitted = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/events');
        await page.getByRole('button', { name: 'Submit for review' }).click();
        const eventResponse = await submitted;
        check(eventResponse.status() === 302, 'Event submission HTTP ' + eventResponse.status());
        await page.waitForLoadState('load');
        check(new URL(page.url()).pathname === '/account/events', 'Event submission did not redirect to account events: ' + (await page.locator('body').innerText()).slice(0, 2500));
        check(await page.getByRole('heading', { name: 'Recurring browser QA event', exact: true }).count() === 2, 'Expected two event submissions');
        const events = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Recurring browser QA event', exact: true }) });
        await events.first().getByRole('link', { name: 'Reuse event details' }).click();
        check(await page.locator('input[name="title"]').inputValue() === 'Recurring browser QA event', 'Reused title missing');
        check(await page.locator('input[name="starts_at"]').inputValue() === '', 'Reused event copied dates');
        await visit(page, '/account/events');
        await page.locator('details').filter({ has: page.locator('#event-batch-edit') }).locator('summary').click();
        for (const input of await events.locator('input[form="event-batch-edit"][name="events[]"]').all()) await input.check();
        await page.locator('#event-batch-edit input[name="value"]').fill('Revised QA hall');
        await page.locator('#event-batch-edit input[name="confirm_update"]').check();
        await submit(page, page.locator('#event-batch-edit').getByRole('button', { name: 'Update selected events' }), '/account/events');
        await events.getByText('Revised QA hall', { exact: true }).nth(1).waitFor();
        await page.locator('details').filter({ has: page.locator('#event-batch-shift') }).locator('summary').click();
        for (const input of await events.locator('input[form="event-batch-shift"][name="events[]"]').all()) await input.check();
        await page.locator('#event-batch-shift input[name="days"]').fill('1');
        await page.locator('#event-batch-shift input[name="confirm_shift"]').check();
        await submit(page, page.locator('#event-batch-shift').getByRole('button', { name: 'Reschedule selected events' }), '/account/events');
        await page.getByText('2 selected events rescheduled for review.', { exact: true }).waitFor();
        await page.locator('details').filter({ has: page.locator('#event-batch-cancel') }).locator('summary').click();
        for (const input of await events.locator('input[form="event-batch-cancel"][name="events[]"]').all()) await input.check();
        await page.locator('#event-batch-cancel input[name="confirm_cancel"]').check();
        await submit(page, page.locator('#event-batch-cancel').getByRole('button', { name: 'Cancel selected events' }), '/account/events');
        await events.locator('[data-status="withdrawn"]').nth(1).waitFor();
        check(await events.locator('[data-status="withdrawn"]').count() === 2, 'Expected both selected submissions withdrawn');
        console.log('PASS: recurring event form, reuse and selected withdrawal');
        await visit(page, '/account/data-export');
        check(await page.locator('select[name="section"] option[value="conversation-metadata"]').count() === 1, 'Conversation metadata export missing');

        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const [name, path] of [['searches', '/account/job-searches'], ['market-searches', '/account/marketplace-searches'], ['export', '/account/data-export'], ['notifications', '/account/notifications'], ['privacy', '/account/privacy-requests'], ['events', '/account/events'], ['chat', conversation]]) {
                await visit(page, path);
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' overflows at ' + width);
                await page.screenshot({ path: 'test-results/staged-' + name + '-' + width + '.png', fullPage: true });
            }
        }
        await visit(admin, '/admin/readiness');
        await admin.getByRole('heading', { name: 'Staged feature inventory' }).waitFor();
        check(await admin.getByText('Configuration mismatch', { exact: true }).count() === 0, 'Staged schema mismatch');
        await visit(admin, '/admin/news');
        console.log('PASS: responsive staged views and admin readiness');
    } catch (error) {
        await page.screenshot({ path: 'test-results/staged-qa-failure.png', fullPage: true }).catch(() => {});
        throw error;
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
