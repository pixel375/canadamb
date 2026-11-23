import Alpine from 'alpinejs';
import './listing-photos';

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

    const stage = dialog.querySelector('[data-gallery-stage]');
    const preview = dialog.querySelector('[data-gallery-preview]');
    const counter = dialog.querySelector('[data-gallery-counter]');
    const previous = dialog.querySelector('[data-gallery-prev]');
    const next = dialog.querySelector('[data-gallery-next]');
    const close = dialog.querySelector('[data-gallery-close]');
    const thumbs = Array.from(dialog.querySelectorAll('[data-gallery-thumb]'));
    let active = 0;
    let opener = null;
    let pageScroll = 0;
    let touchStart = null;

    const display = index => {
        active = (index + links.length) % links.length;
        preview.src = links[active].href;
        preview.alt = links[active].querySelector('img')?.alt ?? 'Listing photo';
        counter.textContent = 'Photo ' + (active + 1) + ' of ' + links.length;
        thumbs.forEach((thumb, position) => {
            if (position === active) thumb.setAttribute('aria-current', 'true');
            else thumb.removeAttribute('aria-current');
        });
    };

    links.forEach((link, index) => link.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        pageScroll = window.scrollY;
        try {
            dialog.showModal();
        } catch {
            return;
        }
        event.preventDefault();
        opener = link;
        display(index);
        document.documentElement.style.setProperty('--listing-lightbox-scroll', -pageScroll + 'px');
        document.documentElement.classList.add('listing-lightbox-open');
        close.focus({ preventScroll: true });
    }));

    thumbs.forEach((thumb, index) => thumb.addEventListener('click', () => display(index)));
    previous.addEventListener('click', () => display(active - 1));
    next.addEventListener('click', () => display(active + 1));
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        touchStart = null;
        document.documentElement.classList.remove('listing-lightbox-open');
        document.documentElement.style.removeProperty('--listing-lightbox-scroll');
        window.scrollTo(0, pageScroll);
        opener?.focus({ preventScroll: true });
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('keydown', event => {
        if (links.length > 1 && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
            event.preventDefault();
            display(active + (event.key === 'ArrowRight' ? 1 : -1));
        }
        if (event.key === 'Tab') {
            const controls = dialog.querySelectorAll('button:not([hidden])');
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (document.activeElement === (event.shiftKey ? first : last)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            }
        }
    });
    stage.addEventListener('touchstart', event => {
        touchStart = event.touches.length === 1 ? event.touches[0] : null;
    }, { passive: true });
    stage.addEventListener('touchcancel', () => { touchStart = null; }, { passive: true });
    stage.addEventListener('touchend', event => {
        const start = touchStart;
        touchStart = null;
        if (!start || event.touches.length || links.length < 2) return;
        const end = Array.from(event.changedTouches).find(touch => touch.identifier === start.identifier);
        if (!end) return;
        const horizontal = end.clientX - start.clientX;
        const vertical = end.clientY - start.clientY;
        if (Math.abs(horizontal) >= 45 && Math.abs(horizontal) > Math.abs(vertical) * 1.25) {
            display(active + (horizontal < 0 ? 1 : -1));
        }
    }, { passive: true });
}



const discussionReply = document.getElementById('discussion-reply');
const discussionQuoteStatus = document.getElementById('discussion-quote-status');
if (discussionReply && discussionQuoteStatus) {
    for (const button of document.querySelectorAll('[data-discussion-quote]')) {
        button.hidden = false;
        button.addEventListener('click', () => {
            const reply = button.closest('article');
            const author = reply.querySelector('[data-quote-author]').textContent.replace(/\s+/gu, ' ').trim();
            const text = Array.from(reply.querySelector('[data-quote-body]').textContent.trim());
            const excerpt = text.slice(0, 280).join('') + (text.length > 280 ? '…' : '');
            const quoted = excerpt.split(/\r?\n/).map(line => '> ' + line).join('\n');
            const separator = discussionReply.value ? '\n\n' : '';
            const insertion = separator + author + ' wrote:\n' + quoted + '\n\n';
            if (discussionReply.value.length + insertion.length > discussionReply.maxLength) {
                discussionQuoteStatus.textContent = 'Shorten your reply to add this quote.';
            } else {
                discussionReply.value += insertion;
                discussionQuoteStatus.textContent = '';
            }
            discussionReply.focus();
        });
    }
    discussionReply.addEventListener('input', () => { discussionQuoteStatus.textContent = ''; });
}

// Desktop dropdowns stay anchored, never in a horizontally scrolling nav
// container. Native <details> allows keyboard Enter/Space and no-JS fallback.
const desktopNavGroups = Array.from(document.querySelectorAll('.desktop-nav-group'));
if (desktopNavGroups.length) {
    const closeOtherGroups = except => {
        for (const group of desktopNavGroups) {
            if (group !== except) group.open = false;
        }
    };
    for (const group of desktopNavGroups) {
        // A native details "toggle" event fires asynchronously, so relying on
        // it alone permits two menus to appear open for a frame (or longer in
        // automated keyboard/navigation sequences). Close siblings on the
        // summary activation itself, before the default details toggle.
        group.querySelector('summary')?.addEventListener('click', () => closeOtherGroups(group));
        group.addEventListener('toggle', () => {
            if (group.open) closeOtherGroups(group);
        });
    }
    document.addEventListener('pointerdown', event => {
        if (!event.target.closest('.desktop-nav-group')) closeOtherGroups(null);
    });
    document.addEventListener('focusin', event => {
        if (!event.target.closest('.desktop-nav-group')) closeOtherGroups(null);
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const open = desktopNavGroups.find(group => group.open);
        if (open) {
            event.preventDefault();
            open.open = false;
            open.querySelector('summary')?.focus();
        }
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
