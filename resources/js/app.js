import Alpine from 'alpinejs';

const modes = ['system', 'light', 'dark'];
const themeChoice = document.getElementById('theme-choice');

function theme(value) {
    if (value === 'system') {
        delete document.documentElement.dataset.theme;
    } else {
        document.documentElement.dataset.theme = value;
    }
    if (themeChoice) themeChoice.value = value;
}

try {
    const preference = localStorage.getItem('canadamb-theme') ?? 'system';
    theme(modes.includes(preference) ? preference : 'system');
} catch {
    theme('system');
}

themeChoice?.addEventListener('change', () => {
    theme(themeChoice.value);
    try {
        localStorage.setItem('canadamb-theme', themeChoice.value);
    } catch {
        // A browser may disable persistent site storage.
    }
});

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
    window.matchMedia('(min-width: 761px)').addEventListener('change', event => {
        if (event.matches && siteDrawer.open) siteDrawer.close();
    });
}

Alpine.start();
