for (const editor of document.querySelectorAll('[data-photo-editor]')) {
    const picker = editor.querySelector('[data-photo-picker]');
    const uploads = editor.querySelector('[data-photo-files]');
    const plan = editor.querySelector('[data-photo-plan]');
    const previews = editor.querySelector('[data-photo-previews]');
    const template = editor.querySelector('[data-photo-template]');
    const add = editor.querySelector('[data-photo-add]');
    const replace = editor.querySelector('[data-photo-replace]');
    const status = editor.querySelector('[data-photo-status]');
    const error = editor.querySelector('[data-photo-error]');
    const count = editor.querySelector('[data-photo-count]');
    const maxPhotos = 5;
    const maxBytes = 4 * 1024 * 1024;
    const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    let selectionTarget = null;
    let photos = Array.from(previews.children, (item, index) => ({
        reference: item.dataset.photoEntry,
        name: 'Current photo ' + (index + 1),
        source: item.querySelector('img').src,
    }));

    // A picker alone cannot retain files across selections or submit their new order.
    try {
        const probe = uploads.cloneNode();
        const transfer = new DataTransfer();
        transfer.items.add(new File(['probe'], 'probe.png'));
        probe.files = transfer.files;
        if (probe.files.length !== 1 || typeof URL.createObjectURL !== 'function') continue;
    } catch {
        continue;
    }

    function showError(message) {
        error.textContent = message;
    }

    function render(references) {
        count.textContent = photos.length + ' of ' + maxPhotos + ' photos.'
            + (photos.length ? ' The first photo is the cover.' : '');
        replace.hidden = photos.length === 0;
        previews.hidden = photos.length === 0;
        previews.replaceChildren(...photos.map((photo, index) => {
            const item = template.content.firstElementChild.cloneNode(true);
            item.dataset.photoEntry = references[index];
            item.querySelector('img').src = photo.source;
            item.querySelector('[data-photo-name]').textContent = photo.name + (index === 0 ? ' · Cover' : '');
            const earlier = item.querySelector('[data-photo-earlier]');
            const later = item.querySelector('[data-photo-later]');
            const replaceOne = item.querySelector('[data-photo-replace-one]');
            const remove = item.querySelector('[data-photo-remove]');
            earlier.disabled = index === 0;
            later.disabled = index === photos.length - 1;
            earlier.setAttribute('aria-label', 'Move earlier: ' + photo.name);
            later.setAttribute('aria-label', 'Move later: ' + photo.name);
            replaceOne.setAttribute('aria-label', 'Replace: ' + photo.name);
            remove.setAttribute('aria-label', 'Remove: ' + photo.name);
            earlier.addEventListener('click', () => move(index, -1));
            later.addEventListener('click', () => move(index, 1));
            replaceOne.addEventListener('click', () => choose(index));
            remove.addEventListener('click', () => {
                if (!commit(photos.filter(candidate => candidate !== photo), 'Photo removed.')) return;
                const remaining = previews.children[Math.min(index, photos.length - 1)];
                (remaining?.querySelector('[data-photo-remove]') ?? add).focus();
            });
            return item;
        }));
    }

    function commit(proposed, message = '') {
        let references;
        try {
            const transfer = new DataTransfer();
            references = proposed.map(photo => {
                if (!photo.file) return photo.reference;
                const index = transfer.files.length;
                if (!transfer.items.add(photo.file)) throw new Error('Unable to stage photo');
                return 'new:' + index;
            });
            uploads.files = transfer.files;
        } catch {
            showError('These photo changes could not be applied. Try choosing the files again.');
            return false;
        }

        for (const photo of photos) {
            if (photo.file && !proposed.includes(photo)) URL.revokeObjectURL(photo.source);
        }
        photos = proposed;
        plan.value = JSON.stringify(references);
        showError('');
        render(references);
        status.textContent = message;
        return true;
    }

    function move(index, offset) {
        const order = [...photos];
        const destination = index + offset;
        [order[index], order[destination]] = [order[destination], order[index]];
        if (!commit(order, 'Photo moved to position ' + (destination + 1) + '.')) return;
        const moved = previews.children[destination];
        const selector = offset < 0 ? '[data-photo-earlier]' : '[data-photo-later]';
        (moved.querySelector(selector + ':enabled') ?? moved.querySelector('button:enabled')).focus();
    }

    function choose(target = null) {
        selectionTarget = target;
        picker.multiple = typeof target !== 'number';
        picker.click();
    }

    function resetPicker() {
        selectionTarget = null;
        picker.multiple = true;
        picker.value = '';
    }

    if (!commit(photos)) continue;
    picker.removeAttribute('name');
    uploads.disabled = false;
    plan.disabled = false;
    editor.querySelector('[data-photo-native]').hidden = true;
    editor.querySelector('[data-photo-actions]').hidden = false;
    count.hidden = false;

    add.addEventListener('click', () => choose());
    replace.addEventListener('click', () => choose('all'));
    picker.addEventListener('cancel', resetPicker);
    picker.addEventListener('change', () => {
        const files = Array.from(picker.files);
        const target = selectionTarget;
        const replacingOne = typeof target === 'number';
        resetPicker();
        if (!files.length) return;
        if (replacingOne && files.length !== 1) {
            showError('Choose one file to replace this photo.');
            return;
        }
        const retained = target === 'all' ? [] : [...photos];
        if (replacingOne) retained.splice(target, 1);
        if (retained.length + files.length > maxPhotos) {
            showError('A listing can have up to five photos. Remove a photo or choose fewer files.');
            return;
        }
        if (files.some(file => file.size === 0 || file.size > maxBytes)) {
            showError('Choose non-empty photos that are 4 MB or smaller.');
            return;
        }
        if (files.some(file => !allowedTypes.has(file.type) && !/\.(jpe?g|png|webp)$/i.test(file.name))) {
            showError('Choose JPEG, PNG or WebP photos.');
            return;
        }
        const selected = files.map(file => ({ file, name: file.name, source: URL.createObjectURL(file) }));
        retained.splice(replacingOne ? target : retained.length, 0, ...selected);
        const message = replacingOne ? 'Replaced photo ' + (target + 1) + '.'
            : (target === 'all' ? 'Replaced with ' : 'Added ') + files.length + (files.length === 1 ? ' photo.' : ' photos.');
        if (!commit(retained, message)) {
            selected.forEach(photo => URL.revokeObjectURL(photo.source));
        } else if (replacingOne) {
            previews.children[target].querySelector('[data-photo-replace-one]').focus();
        }
    });
}
