(function () {
    const form = document.querySelector('form[data-autosubmit="true"]');
    if (!form) {
        return;
    }

    const searchInput = form.querySelector('input[name="query"]');
    const typeSelect = form.querySelector('select[name="type"]');
    const mauticInput = form.querySelector('input[name="mautic"]');
    const popularitySelect = form.querySelector('select[name="popularity"]');
    const maintainerInput = form.querySelector('input[name="maintainer"]');
    const dateRangeSelect = form.querySelector('select[name="date_range"]');
    const focusKey = 'marketplace:focus';

    let debounceTimer = null;
    const submitForm = (focusTarget) => {
        if (form.checkValidity()) {
            if (focusTarget) {
                sessionStorage.setItem(focusKey, JSON.stringify({
                    focus: focusTarget.name,
                    position: focusTarget.selectionStart ?? focusTarget.value.length,
                }));
            }
            form.submit();
        }
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => submitForm(searchInput), 400);
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', () => submitForm(typeSelect));
    }

    if (mauticInput) {
        mauticInput.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => submitForm(mauticInput), 400);
        });
    }

    if (popularitySelect) {
        popularitySelect.addEventListener('change', () => submitForm(popularitySelect));
    }

    if (maintainerInput) {
        maintainerInput.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => submitForm(maintainerInput), 400);
        });
    }

    if (dateRangeSelect) {
        dateRangeSelect.addEventListener('change', () => submitForm(dateRangeSelect));
    }

    try {
        const saved = sessionStorage.getItem(focusKey);
        if (saved) {
            const data = JSON.parse(saved);
            const focusTargets = { query: searchInput, mautic: mauticInput, maintainer: maintainerInput };
            const target = (data && focusTargets[data.focus]) || searchInput;
            if (target) {
                target.focus({ preventScroll: true });
                const pos = typeof data.position === 'number' ? data.position : target.value.length;
                if (typeof target.setSelectionRange === 'function') {
                    target.setSelectionRange(pos, pos);
                }
            }
        }
        sessionStorage.removeItem(focusKey);
    } catch (e) {
        sessionStorage.removeItem(focusKey);
    }
})();
