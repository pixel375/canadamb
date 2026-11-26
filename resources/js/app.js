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
const settingsThemeChoice = document.getElementById('theme-choice-settings');
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
    if (settingsThemeChoice) settingsThemeChoice.value = value;
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

for (const control of [themeChoice, mobileThemeChoice, settingsThemeChoice]) {
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

    const help = message => {
        if (installHelp) {
            installHelp.textContent = message;
            installHelp.hidden = false;
        }
    };
    const android = /Android/i.test(navigator.userAgent);
    const updateInstall = () => {
        if (standalone()) { hide(); return; }
        reveal();
        installButton.textContent = pendingInstall ? 'Install App' : 'App installation help';
    };
    updateInstall();

    window.addEventListener('beforeinstallprompt', event => {
        if (standalone()) return;
        event.preventDefault();
        pendingInstall = event;
        if (installHelp) installHelp.hidden = true;
        updateInstall();
    });

    installButton.addEventListener('click', async () => {
        if (standalone()) { hide(); return; }
        if (pendingInstall) {
            const request = pendingInstall;
            pendingInstall = null;
            try {
                await request.prompt();
                const choice = await request.userChoice;
                if (choice?.outcome === 'accepted') {
                    help('Installation requested. Your browser and device will finish the installation.');
                } else {
                    help('Installation was dismissed. You can also check your browser’s app installation menu.');
                }
            } catch {
                help('The browser withdrew the installation prompt. Try again from the browser menu.');
            }
            updateInstall();
        } else if (installHelp && !installHelp.hidden) {
            installHelp.hidden = true;
        } else if (safari) {
            help('In Safari, tap Share, then Add to Home Screen. Enable "Open as Web App" if offered.');
        } else if (android) {
            help('Open this website in Chrome and check its menu for Install app. If it only offers Add to Home screen or Create shortcut, a full app installation is not currently available. Private prelaunch access can limit installation.');
        } else {
            help('In a supported browser such as Chrome or Edge, look for the install icon or Install app in the browser menu. A shortcut is not necessarily an installed app.');
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
    const limit = Number(input.dataset.photoLimit) || 5;
    const megabytes = Number(input.dataset.photoMb) || 4;
    const existing = form.querySelector('[data-existing-photo-grid]');
    const existingFeedback = form.querySelector('[data-existing-feedback]');
    const kept = () => existing ? [...existing.querySelectorAll('input[name="keep_photos[]"]:checked')] : [];
    const updateExisting = () => {
        if (!existing) return;
        const retained = kept();
        for (const figure of existing.querySelectorAll('[data-existing-photo]')) {
            const checkbox = figure.querySelector('input');
            const index = retained.indexOf(checkbox);
            figure.classList.toggle('photo-will-remove', index < 0);
            figure.querySelector('[data-existing-caption]').textContent = index < 0 ? 'Will be removed' : index === 0 ? 'Cover photo' : 'Photo ' + (index + 1);
            figure.querySelector('[data-existing-cover]').disabled = index <= 0;
            figure.querySelector('[data-existing-move]').disabled = index <= 0;
        }
        existingFeedback.textContent = retained.length + ' existing photo(s) retained. ' + (limit - retained.length) + ' upload slot(s) available.';
    };
    let urls = [];
    const clear = () => {
        for (const url of urls) URL.revokeObjectURL(url);
        urls = [];
        preview.replaceChildren();
        preview.hidden = true;
    };

    const render = () => {
        clear();
        input.setCustomValidity('');
        const files = [...input.files];
        const invalid = files.some(file => file.size > megabytes * 1024 * 1024 ||
            !['image/jpeg', 'image/png', 'image/webp'].includes(file.type));
        const available = limit - kept().length;
        const error = files.length > available ? 'Choose no more than ' + available + ' new photos, or remove existing photos first.' :
            invalid ? 'Use JPEG, PNG or WebP photos no larger than ' + megabytes + ' MB each.' : '';
        input.setCustomValidity(error);
        feedback.textContent = error || (files.length ? (files.length + kept().length) + ' of ' + limit + ' photos selected.' : '');
        if (error) return;
        const editable = typeof DataTransfer === 'function';
        const apply = updated => {
            try {
                const transfer = new DataTransfer();
                for (const file of updated) transfer.items.add(file);
                input.files = transfer.files;
            } catch {
                feedback.textContent = 'This browser cannot reorder selected files. Choose them again using the file picker.';
                return;
            }
            render();
        };
        for (const [index, file] of files.entries()) {
            const url = URL.createObjectURL(file);
            urls.push(url);
            const figure = document.createElement('figure');
            const image = document.createElement('img');
            image.src = url;
            image.alt = 'Selected photo ' + (index + 1);
            const caption = document.createElement('figcaption');
            caption.textContent = index === 0 && kept().length === 0 ? 'Cover photo' : 'Photo ' + (kept().length + index + 1);
            figure.append(image, caption);
            if (editable) {
                const actions = document.createElement('div');
                actions.className = 'listing-photo-actions';
                const button = (label, accessible, action) => {
                    const control = document.createElement('button');
                    control.type = 'button';
                    control.className = 'button button-outline';
                    control.textContent = label;
                    control.setAttribute('aria-label', accessible);
                    control.addEventListener('click', action);
                    actions.append(control);
                };
                if (index > 0) button(kept().length ? 'Move first among new photos' : 'Make cover', 'Make photo ' + (index + 1) + ' the cover', () => {
                    const updated = [...files];
                    updated.unshift(updated.splice(index, 1)[0]);
                    apply(updated);
                });
                button('Remove', 'Remove photo ' + (index + 1), () => {
                    apply(files.filter((_, position) => position !== index));
                });
                figure.append(actions);
            }
            preview.append(figure);
        }
        preview.hidden = files.length === 0;
    };
    if (existing) {
        for (const figure of existing.querySelectorAll('[data-existing-photo]')) {
            figure.querySelector('[data-existing-controls]').hidden = false;
            figure.querySelector('input').addEventListener('change', () => { updateExisting(); render(); });
            figure.querySelector('[data-existing-cover]').addEventListener('click', () => {
                existing.prepend(figure);
                updateExisting(); render();
            });
            figure.querySelector('[data-existing-move]').addEventListener('click', () => {
                const retained = kept();
                const index = retained.indexOf(figure.querySelector('input'));
                if (index > 0) existing.insertBefore(figure, retained[index - 1].closest('figure'));
                updateExisting(); render();
            });
        }
        updateExisting();
    }
    input.addEventListener('change', () => {
        render();
        if (!input.validity.valid) input.reportValidity();
    });
}


const updateActivityCounts = counts => {
    for (const [name, path] of [['messages', '/account/messages'], ['notifications', '/account/notifications']]) {
        const count = Math.max(0, Number(counts[name]) || 0);
        for (const link of document.querySelectorAll('.site-header a, .site-drawer a, .mobile-tab-bar a, .account-shortcut')) {
            if (link.getAttribute('href') !== path) continue;
            let badge = link.querySelector('.nav-count');
            if (!count) { badge?.remove(); continue; }
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'nav-count';
                link.append(badge);
            }
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.setAttribute('aria-label', count + ' unread ' + name);
            badge.title = count + ' unread ' + name;
        }
    }
};

// Incremental, same-origin conversation refresh. Do not replace the composer
// or reload the page; only acknowledge messages after they are visible.
const chatPanel = document.querySelector('[data-chat-sync]');
if (chatPanel) {
    const history = chatPanel.querySelector('[data-chat-history]');
    const status = chatPanel.querySelector('[data-chat-status]');
    const send = chatPanel.querySelector('[data-chat-send]');
    const jump = chatPanel.querySelector('[data-chat-jump]');
    const goLatest = () => history.lastElementChild?.scrollIntoView({ block: 'end' });
    jump?.addEventListener('click', () => { goLatest(); jump.textContent = 'Jump to latest message'; });
    let cursor = Number(chatPanel.dataset.chatCursor);
    let pending = false;
    let stopped = false;
    let delay = 15000;
    let timer;
    const defaultStatus = 'Messages refresh automatically while this conversation is open.';
    const latestVisible = () => {
        if (document.hidden || !document.hasFocus()) return 0;
        const last = history.lastElementChild;
        if (!last) return 0;
        const rect = last.getBoundingClientRect();
        return rect.bottom <= innerHeight && rect.bottom > 0 ? Number(last.dataset.messageId) : 0;
    };
    const append = item => {
        if (item.id <= cursor) return;
        const article = document.createElement('article');
        article.className = 'chat-message ' + (item.own ? 'is-own' : 'is-other');
        article.dataset.messageId = String(item.id);
        const meta = document.createElement('div');
        meta.className = 'chat-message-meta';
        const sender = document.createElement('strong');
        sender.textContent = item.sender;
        const time = document.createElement('time');
        time.dateTime = item.created_at;
        time.textContent = item.time_label;
        const body = document.createElement('p');
        body.className = 'chat-bubble';
        body.textContent = item.body;
        meta.append(sender, time);
        article.append(meta, body);
        const conversation = Number(chatPanel.dataset.chatConversation);
        if (item.has_attachment && Number.isSafeInteger(item.id) && Number.isSafeInteger(conversation) && conversation > 0) {
            const attachment = document.createElement('a');
            attachment.className = 'text-link';
            attachment.href = '/account/messages/' + conversation + '/attachments/' + item.id;
            attachment.textContent = 'Download photo attachment';
            article.append(attachment);
        }
        if (!item.own && chatPanel.dataset.chatReportable === '1' && Number.isSafeInteger(item.id)) {
            const report = document.createElement('a');
            report.className = 'text-link field-note';
            report.href = '/account/report/message/' + item.id;
            report.textContent = 'Report message';
            article.append(report);
            if (item.has_attachment) {
                const photoReport = document.createElement('a');
                photoReport.className = 'text-link field-note';
                photoReport.href = '/account/report/message-photo/' + item.id;
                photoReport.textContent = 'Report photo';
                article.append(photoReport);
            }
        }
        history.append(article);
        cursor = item.id;
    };
    const schedule = () => {
        clearTimeout(timer);
        if (!stopped && !document.hidden) timer = setTimeout(refresh, delay);
    };
    async function refresh() {
        if (pending || stopped || document.hidden) return;
        pending = true;
        const seen = latestVisible();
        try {
            const response = await fetch(chatPanel.dataset.chatSync, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': chatPanel.dataset.chatToken },
                body: JSON.stringify({ after: cursor, seen }),
            });
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) {
                stopped = true;
                status.textContent = 'Refresh paused. Reload this page to reconnect.';
                return;
            }
            if (!response.ok) throw new Error('Refresh failed');
            const data = await response.json();
            const wasAtLatest = latestVisible() > 0;
            for (const item of data.messages) append(item);
            if (data.messages.length && jump) {
                if (wasAtLatest && !document.activeElement?.matches('textarea, input')) goLatest();
                else jump.textContent = 'Jump to new messages';
            }
            updateActivityCounts(data.counts);
            if (send) send.disabled = Boolean(data.blocked);
            if (data.blocked) status.textContent = 'New messages are blocked for this conversation.';
            else if (data.messages.length) status.textContent = data.messages.length + ' new message(s) received.';
            else if (!send) status.textContent = 'Messaging availability changed. Reload to reply.';
            else status.textContent = defaultStatus;
            delay = 15000;
        } catch {
            status.textContent = 'Could not refresh messages. Retrying automatically.';
            delay = Math.min(delay * 2, 120000);
        } finally {
            pending = false;
            schedule();
        }
    }
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) refresh();
    });
    schedule();
}


// Refresh counts, not message contents, while browsing other signed-in pages.
// The open chat already includes counts in its own sync response.
const activityEndpoint = document.body.dataset.activityCounts;
const activitySetting = document.getElementById('activity-refresh-setting');
const preferenceStatus = document.getElementById('device-preference-status');
let activityEnabled = true;
try {
    activityEnabled = localStorage.getItem('canadamb-activity-refresh') !== 'off';
} catch {
    // Storage restrictions leave the default enabled.
}
if (activitySetting) {
    activitySetting.checked = activityEnabled;
    activitySetting.addEventListener('change', () => {
        activityEnabled = activitySetting.checked;
        try {
            localStorage.setItem('canadamb-activity-refresh', activityEnabled ? 'on' : 'off');
            preferenceStatus.textContent = 'Device preference saved.';
        } catch {
            preferenceStatus.textContent = 'Preference applies to this page only; browser storage is unavailable.';
        }
        document.dispatchEvent(new Event('activity-preference-changed'));
    });
}

if (activityEndpoint && !chatPanel) {
    let running = false;
    let stopped = false;
    let timer;
    let delay = 45000;
    const schedule = () => {
        clearTimeout(timer);
        if (!document.hidden && !stopped && activityEnabled) timer = setTimeout(refresh, delay);
    };
    async function refresh() {
        if (running || stopped || document.hidden || !activityEnabled) return;
        running = true;
        try {
            const response = await fetch(activityEndpoint, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            });
            if (response.redirected || [401, 403, 404, 419].includes(response.status)) {
                stopped = true;
                return;
            }
            if (!response.ok) throw new Error('Activity refresh failed');
            updateActivityCounts(await response.json());
            delay = 45000;
        } catch {
            delay = Math.min(delay * 2, 180000);
        } finally {
            running = false;
            schedule();
        }
    }
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) refresh();
    });
    document.addEventListener('activity-preference-changed', schedule);
    window.addEventListener('storage', event => {
        if (event.key !== 'canadamb-activity-refresh') return;
        activityEnabled = event.newValue !== 'off';
        if (activitySetting) activitySetting.checked = activityEnabled;
        schedule();
    });
    schedule();
}

 // Keep text length feedback local; submissions still use server validation.
for (const field of document.querySelectorAll('[data-character-count]')) {
    const feedback = field.closest('label')?.querySelector('[data-character-feedback]');
    if (!feedback) continue;
    const update = () => { feedback.textContent = field.value.length + ' / ' + field.maxLength + ' characters'; };
    field.addEventListener('input', update);
    update();
}

// Password visibility remains local; password values are never persisted.
for (const toggle of document.querySelectorAll('[data-password-toggle]')) {
    const field = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!field) continue;
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const visible = field.type === 'password';
        field.type = visible ? 'text' : 'password';
        toggle.textContent = visible ? 'Hide' : 'Show';
        toggle.setAttribute('aria-pressed', String(visible));
    });
}

// Manitoba-local clock: use the named timezone, not the visitor device timezone.
const localClocks = document.querySelectorAll('[data-manitoba-clock]');
if (localClocks.length) {
    const timeFormat = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Winnipeg', hour: 'numeric', minute: '2-digit', hour12: true });
    const dateFormat = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Winnipeg', weekday: 'short', month: 'short', day: 'numeric' });
    let clockTimer;
    const updateClocks = () => {
        clearTimeout(clockTimer);
        const now = new Date();
        for (const clock of localClocks) {
            clock.textContent = timeFormat.format(now);
            clock.dateTime = now.toISOString();
        }
        for (const date of document.querySelectorAll('[data-manitoba-date]')) date.textContent = dateFormat.format(now);
        if (!document.hidden) clockTimer = setTimeout(updateClocks, 60000 - Date.now() % 60000 + 50);
    };
    document.addEventListener('visibilitychange', updateClocks);
    updateClocks();
}
