import Alpine from 'alpinejs';

// Show browse filters immediately on desktop, without forcing them open on
// phones or preventing a visitor from collapsing the native disclosure.
if (window.matchMedia('(min-width: 1024px)').matches) {
    for (const sidebar of document.querySelectorAll('details.browse-sidebar')) {
        sidebar.open = true;
    }
}


const modes = ['system', 'light', 'dark'];
const themeChoice = document.getElementById('theme-choice');
const mobileThemeChoice = document.getElementById('theme-choice-mobile');
const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
const systemBarTheme = document.querySelector('meta[name="theme-color"]');

function updateSystemBarTheme(value) {
    if (!systemBarTheme) return;
    const dark = value === 'dark' || (value === 'system' && systemDark.matches);
    const color = dark ? systemBarTheme.dataset.darkColor : systemBarTheme.dataset.lightColor;
    if (color && /^#[0-9a-f]{6}$/i.test(color)) systemBarTheme.content = color;
}

function theme(value) {
    if (value === 'system') {
        delete document.documentElement.dataset.theme;
    } else {
        document.documentElement.dataset.theme = value;
    }
    if (themeChoice) themeChoice.value = value;
    if (mobileThemeChoice) mobileThemeChoice.value = value;
    updateSystemBarTheme(value);
}

try {
    const preference = localStorage.getItem('canadamb-theme') ?? 'system';
    theme(modes.includes(preference) ? preference : 'system');
} catch {
    theme('system');
}

// Keep installed PWA chrome aligned when the device's dark mode changes.
// An explicit Light/Dark selection always takes precedence over the OS.
systemDark.addEventListener('change', () => {
    if (!document.documentElement.dataset.theme) updateSystemBarTheme('system');
});

for (const control of [themeChoice, mobileThemeChoice]) {
    control?.addEventListener('change', () => {
        theme(control.value);
        try {
            localStorage.setItem('canadamb-theme', control.value);
        } catch {
            // A browser may disable persistent site storage.
        }
    });
}

Alpine.data('themeEditor', (palettes, current, selected) => ({
    palettes,
    current,
    selected,
    preset() {
        if (this.selected !== 'custom') {
            this.current = JSON.parse(JSON.stringify(this.palettes[this.selected]));
        }
    },
    modify(mode, token, value) {
        this.current[mode][token] = value;
        this.selected = 'custom';
    },
    preview(mode) {
        return Object.fromEntries(Object.entries(this.current[mode])
            .filter(([, value]) => /^#[\da-f]{6}$/i.test(value))
            .map(([key, value]) => ['--sample-' + key.replace('_', '-'), value]));
    },
    contrast(mode) {
        const { text, page, button, button_text: buttonText } = this.current[mode];
        const luminance = hex => {
            if (!/^#[\da-f]{6}$/i.test(hex)) return NaN;
            const values = hex.slice(1).match(/.{2}/g).map(value => parseInt(value, 16) / 255)
                .map(value => value <= 0.04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
            return values[0] * .2126 + values[1] * .7152 + values[2] * .0722;
        };
        return [[text, page], [buttonText, button]].some(pair => {
            const values = pair.map(luminance);
            return (Math.max(...values) + .05) / (Math.min(...values) + .05) < 4.5;
        });
    },
}));

Alpine.data('accentEditor', (type, colors, size, direction, radius, shadow) => ({
    type, colors, size, direction, radius, shadow,
    preview() {
        const colors = this.colors.split(',').map(color => color.trim()).filter(color => /^#[\da-f]{6}$/i.test(color));
        const angle = Math.min(360, Math.max(0, Number(this.direction)));
        return { background: this.type === 'gradient' && colors.length > 1 ? 'linear-gradient(' + angle + 'deg,' + colors.join(',') + ')' : colors[0],
            height: Math.min(8, Math.max(2, Number(this.size))) + 'px', borderRadius: Math.min(8, Math.max(0, Number(this.radius))) + 'px',
            boxShadow: this.shadow ? '0 1px 4px rgb(0 0 0 / 0.18)' : 'none' };
    },
}));


/**
 * Photo viewer progressively enhances full-image links. Without JavaScript or
 * native <dialog> support, every photo opens as a normal accessible image URL.
 */
for (const gallery of document.querySelectorAll('[data-listing-gallery]')) {
    const links = Array.from(gallery.querySelectorAll('[data-gallery-item]'));
    const dialog = gallery.querySelector('[data-gallery-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function' || links.length === 0) continue;

    const preview = dialog.querySelector('[data-gallery-preview]');
    const counter = dialog.querySelector('[data-gallery-counter]');
    const previous = dialog.querySelector('[data-gallery-prev]');
    const next = dialog.querySelector('[data-gallery-next]');
    const close = dialog.querySelector('[data-gallery-close]');
    let active = 0;
    let opener = null;
    let touchStart = null;

    const display = index => {
        active = (index + links.length) % links.length;
        preview.src = links[active].href;
        preview.alt = links[active].querySelector('img')?.alt ?? 'Listing photo';
        counter.textContent = (active + 1) + ' of ' + links.length;
        previous.hidden = next.hidden = links.length < 2;
    };

    links.forEach((link, index) => link.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        opener = link;
        display(index);
        dialog.showModal();
        document.documentElement.classList.add('listing-lightbox-open');
        close.focus();
    }));

    previous.addEventListener('click', () => display(active - 1));
    next.addEventListener('click', () => display(active + 1));
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        document.documentElement.classList.remove('listing-lightbox-open');
        opener?.focus();
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            if (links.length > 1) display(active + (event.key === 'ArrowRight' ? 1 : -1));
        }
        // Native dialog already handles Escape and traps focus.
    });
    preview.addEventListener('touchstart', event => {
        touchStart = event.touches.length === 1 ? event.touches[0].clientX : null;
    }, { passive: true });
    preview.addEventListener('touchend', event => {
        if (touchStart === null || !event.changedTouches.length) return;
        const change = event.changedTouches[0].clientX - touchStart;
        touchStart = null;
        if (Math.abs(change) >= 50 && links.length > 1) display(active + (change < 0 ? 1 : -1));
    }, { passive: true });
}



// Keep sibling desktop <details> menus exclusive, even during rapid clicks.
// Close on outside click, focus loss or Escape; native details still works
// with mouse, keyboard and with scripting unavailable.
const desktopMenus = [...document.querySelectorAll('.desktop-nav-group')];
if (desktopMenus.length) {
    const closeOthers = except => {
        for (const group of desktopMenus) {
            if (group !== except) group.open = false;
        }
    };
    for (const group of desktopMenus) {
        group.querySelector('summary')?.addEventListener('click', () => closeOthers(group));
        group.addEventListener('toggle', () => {
            if (group.open) closeOthers(group);
        });
    }
    document.addEventListener('pointerdown', event => {
        if (!event.target.closest('.desktop-nav-group')) closeOthers(null);
    });
    document.addEventListener('focusin', event => {
        if (!event.target.closest('.desktop-nav-group')) closeOthers(null);
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const opened = desktopMenus.find(group => group.open);
        if (!opened) return;
        event.preventDefault();
        opened.open = false;
        opened.querySelector('summary')?.focus();
    });
}

// Account disclosure is a separate native <details>; never leave it over other
// desktop dropdowns, and return keyboard focus after an Escape dismissal.
const accountMenu = document.querySelector('.header-account-menu');
if (accountMenu) {
    const summary = accountMenu.querySelector('summary');
    accountMenu.addEventListener('toggle', () => {
        if (accountMenu.open) {
            for (const menu of desktopMenus) menu.open = false;
        }
    });
    document.addEventListener('pointerdown', event => {
        if (!accountMenu.contains(event.target)) accountMenu.open = false;
    });
    document.addEventListener('focusin', event => {
        if (!accountMenu.contains(event.target)) accountMenu.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !accountMenu.open) return;
        event.preventDefault();
        accountMenu.open = false;
        summary?.focus();
    });
}

// Native modal drawer traps focus and supports Escape without custom key handling.
const siteDrawer = document.getElementById('site-drawer');
const siteMenuOpen = document.getElementById('site-menu-open');
const siteMenuClose = document.getElementById('site-menu-close');
if (siteDrawer && siteMenuOpen && typeof siteDrawer.showModal === 'function') {
    siteMenuOpen.addEventListener('click', () => {
        if (!siteDrawer.open) siteDrawer.showModal();
        document.documentElement.classList.add('site-menu-open');
        siteMenuClose?.focus();
    });
    siteMenuClose?.addEventListener('click', () => siteDrawer.close());
    siteDrawer.addEventListener('click', event => {
        if (event.target === siteDrawer) siteDrawer.close();
    });
    siteDrawer.addEventListener('close', () => {
        document.documentElement.classList.remove('site-menu-open');
        siteMenuOpen.focus();
    });
    window.matchMedia('(min-width: 1024px)').addEventListener('change', event => {
        if (event.matches && siteDrawer.open) siteDrawer.close();
    });
}



// Installation remains optional. The tiny service worker uses network-only
// fetches: it never caches account pages, OAuth responses or private messages. Chromium provides a prompt only after its own eligibility
// checks; iOS Safari offers on-demand Home Screen instructions instead.
// Register a pass-through worker so Chromium can offer real app install prompts.
// Every request still goes to Hostinger; no Cache API, precache or offline store.
if ('serviceWorker' in navigator && document.querySelector('link[rel="manifest"]')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Installation CTA remains hidden without browser eligibility.
        });
    });
}

const installWrap = document.getElementById('pwa-install');
const installButton = document.getElementById('pwa-install-button');
const installHelp = document.getElementById('pwa-install-help');
if (installWrap && installButton) {
    const standalone = () => window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true;
    const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const safari = ios && /Safari/i.test(navigator.userAgent) &&
        !/CriOS|FxiOS|EdgiOS|OPiOS/i.test(navigator.userAgent);
    let pendingInstall = null;

    const reveal = () => {
        if (!standalone()) installWrap.hidden = false;
    };
    const hide = () => {
        installWrap.hidden = true;
        if (installHelp) installHelp.hidden = true;
    };

    if (standalone()) {
        hide();
    } else if (safari) {
        reveal();
    }

    window.addEventListener('beforeinstallprompt', event => {
        if (standalone()) return;
        event.preventDefault();
        pendingInstall = event;
        reveal();
    });

    installButton.addEventListener('click', async () => {
        if (standalone()) {
            hide();
            return;
        }
        if (pendingInstall) {
            const request = pendingInstall;
            pendingInstall = null;
            try {
                await request.prompt();
                const choice = await request.userChoice;
                if (choice?.outcome === 'accepted') hide();
            } catch {
                // Browser/OS may withdraw the prompt; never claim installation.
                hide();
            }
        } else if (safari && installHelp) {
            installHelp.hidden = !installHelp.hidden;
        } else {
            hide();
        }
    });

    window.addEventListener('appinstalled', hide);
    window.matchMedia('(display-mode: standalone)').addEventListener('change', event => {
        if (event.matches) hide();
    });
}

// Keep the many administration links in a compact disclosure on phones.
const adminMenu = document.querySelector('.admin-nav-disclosure');
if (adminMenu) {
    const mobileAdmin = window.matchMedia('(max-width: 760px)');
    const syncAdmin = () => { adminMenu.open = !mobileAdmin.matches; };
    syncAdmin();
    mobileAdmin.addEventListener('change', syncAdmin);
}

Alpine.start();


// Preview only locally selected listing photos; never upload before submission.
for (const input of document.querySelectorAll('[data-listing-photos]')) {
    const form = input.closest('form');
    const preview = form.querySelector('[data-photo-preview]');
    const feedback = form.querySelector('[data-photo-feedback]');
    let urls = [];
    const clear = () => {
        for (const url of urls) URL.revokeObjectURL(url);
        urls = [];
        preview.replaceChildren();
        preview.hidden = true;
    };
    input.addEventListener('change', () => {
        clear();
        input.setCustomValidity('');
        const files = [...input.files];
        const invalid = files.some(file => file.size > 4 * 1024 * 1024 ||
            !['image/jpeg', 'image/png', 'image/webp'].includes(file.type));
        const error = files.length > 5 ? 'Choose no more than five photos.' :
            invalid ? 'Use JPEG, PNG or WebP photos no larger than 4 MB each.' : '';
        input.setCustomValidity(error);
        feedback.textContent = error || (files.length ? files.length + ' of 5 photos selected.' : '');
        if (error) {
            input.reportValidity();
            return;
        }
        for (const [index, file] of files.entries()) {
            const url = URL.createObjectURL(file);
            urls.push(url);
            const figure = document.createElement('figure');
            const image = document.createElement('img');
            image.src = url;
            image.alt = 'Selected photo ' + (index + 1);
            const caption = document.createElement('figcaption');
            caption.textContent = index === 0 ? 'Cover photo' : 'Photo ' + (index + 1);
            figure.append(image, caption);
            preview.append(figure);
        }
        preview.hidden = files.length === 0;
    });
}
