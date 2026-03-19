function initMarketplaceForm() {
    const form = document.querySelector('form[data-autosubmit="true"]');
    if (!form) {
        return;
    }

    const searchInput = form.querySelector('input[name="query"]');
    const typeSelect = form.querySelector('select[name="type"]');
    const mauticInput = form.querySelector('input[name="mautic"]');
    const popularitySelect = form.querySelector('select[name="popularity"]');
    const dateRangeSelect = form.querySelector('select[name="date_range"]');
    const focusKey = 'marketplace:focus';
    const scrollKey = 'marketplace:scroll';

    let debounceTimer = null;
    const submitForm = (focusTarget) => {
        if (form.checkValidity()) {
            if (focusTarget) {
                sessionStorage.setItem(focusKey, JSON.stringify({
                    focus: focusTarget.name,
                    position: focusTarget.selectionStart ?? focusTarget.value.length,
                }));
            }
            sessionStorage.setItem(scrollKey, String(window.scrollY));
            form.requestSubmit();
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

    if (dateRangeSelect) {
        dateRangeSelect.addEventListener('change', () => submitForm(dateRangeSelect));
    }

    try {
        const saved = sessionStorage.getItem(focusKey);
        if (saved) {
            const data = JSON.parse(saved);
            const focusTargets = { query: searchInput, mautic: mauticInput };
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

    try {
        const savedScrollY = sessionStorage.getItem(scrollKey);
        if (null !== savedScrollY) {
            window.requestAnimationFrame(() => {
                window.scrollTo({ top: Number.parseInt(savedScrollY, 10) || 0, left: 0 });
            });
        }
        sessionStorage.removeItem(scrollKey);
    } catch (e) {
        sessionStorage.removeItem(scrollKey);
    }
}

function initTooltips() {
    if (!window.bootstrap || !window.bootstrap.Tooltip) {
        return;
    }

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        if (!window.bootstrap.Tooltip.getInstance(element)) {
            new window.bootstrap.Tooltip(element);
        }
    });
}

document.addEventListener('turbo:load', () => {
    initMarketplaceForm();
    initTooltips();
});
