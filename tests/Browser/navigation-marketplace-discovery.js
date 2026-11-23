async page => {
    // Standalone visual/browser acceptance script for the local two-host fixture.
    // A browser run is still required: checking this file into GitHub is not proof
    // that physical phone, tablet or Firefox interactions were performed.
    const port = new URL(page.url()).port || '8000';
    const hosts = ['http://canadamb.localhost:' + port, 'http://brandonmb.localhost:' + port];
    const check = (condition, description) => { if (!condition) throw new Error(description); };

    for (const host of hosts) {
        for (const width of [320, 390, 768, 1024, 1280, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(host + '/');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                'Horizontal overflow on ' + host + ' at ' + width + 'px');
            if (width < 1024) {
                const menu = page.locator('#site-menu-open');
                check(await menu.isVisible(), 'Tablet/mobile hamburger hidden at ' + width);
                await menu.click();
                check(await page.locator('#site-drawer').evaluate(dialog => dialog.open),
                    'Hamburger does not open native dialog at ' + width);
                await page.keyboard.press('Escape');
                check(!(await page.locator('#site-drawer').evaluate(dialog => dialog.open)),
                    'Escape did not close the drawer at ' + width);
            } else {
                const menus = page.locator('.desktop-nav-group');
                check(await menus.count() >= 2, 'Missing real desktop dropdowns');
                await menus.first().locator('summary').click();
                check(await menus.first().evaluate(node => node.open), 'Businesses summary did not open');
                const placement = await page.evaluate(() => {
                    const nav = document.querySelector('.navigation nav');
                    const popup = document.querySelector('.desktop-nav-group[open] .desktop-nav-submenu');
                    const outer = nav.getBoundingClientRect();
                    const inner = popup.getBoundingClientRect();
                    return {
                        overflow: getComputedStyle(nav).overflowX,
                        belowBar: inner.bottom > outer.bottom,
                        left: inner.left, right: inner.right,
                        scrollWidth: document.documentElement.scrollWidth,
                    };
                });
                check(placement.overflow === 'visible', 'Desktop nav still uses clipping scroll overflow');
                check(placement.belowBar && placement.left >= -1 && placement.right <= width + 1,
                    'Dropdown clipped/offscreen at ' + width);
                check(placement.scrollWidth <= width, 'Desktop dropdown caused horizontal overflow');
                await menus.nth(1).locator('summary').click();
                check(!(await menus.first().evaluate(node => node.open)), 'Old desktop menu stayed open');
                check(await menus.nth(1).evaluate(node => node.open), 'Discussions menu did not open');
                await page.keyboard.press('Escape');
                check(!(await menus.nth(1).evaluate(node => node.open)), 'Escape failed for desktop menu');
                await menus.nth(1).locator('summary').click();
                await page.locator('.brand').click();
                check(!(await page.locator('.desktop-nav-group').nth(1).evaluate(node => node.open)),
                    'Desktop menu failed to dismiss on navigation');
            }
        }

        await page.setViewportSize({ width: 390, height: 850 });
        await page.goto(host + '/marketplace');
        check(await page.getByRole('heading', { name: 'What are you looking for?' }).isVisible(),
            'Marketplace did not start at main categories');
        check(await page.locator('a[href="/marketplace?view=all"]').count() === 1,
            'Marketplace has no explicit View all');
        const propertyTile = page.locator('.market-category-upcoming');
        check(await propertyTile.getByText('Real Estate').isVisible(), 'Real estate not discoverable');
        check(await propertyTile.getByText('Coming later').isVisible(), 'Property publishing gate not explained');
        check(await propertyTile.locator('a').count() === 0, 'Unavailable public property tile is clickable');

        await page.locator('a[href="/marketplace?group=vehicles"]').click();
        check(await page.getByRole('heading', { name: 'Vehicles & Motors' }).isVisible(),
            'Parent category did not show child categories');
        await page.locator('a[href="/marketplace?category=cars"]').click();
        check(new URL(page.url()).searchParams.get('category') === 'cars',
            'Leaf category did not preserve legacy slug');
        await page.goto(host + '/marketplace?view=all');
        check(await page.getByText('Browse categories instead').isVisible(),
            'View all did not provide a category-navigation return link');

        if (host.includes('canadamb.localhost')) {
            await page.goto(host + '/marketplace?location=brandon');
            check(await page.getByRole('heading', { name: 'What are you looking for?' }).isVisible(),
                'Location-only visit bypassed category chooser');
            check(await page.getByText('Browsing Brandon listings.').isVisible(),
                'Selected location is not visible to shoppers');
            await page.locator('a[href="/marketplace?group=vehicles&location=brandon"]').click();
            await page.locator('a[href="/marketplace?category=cars&location=brandon"]').click();
            check(new URL(page.url()).searchParams.get('location') === 'brandon',
                'Category drill-down lost selected location');
        }
    }
};
