function initMarketplaceForm() {
    const form = document.querySelector('form[data-autosubmit="true"]');
    if (!form) {
        return;
    }

    const searchInput = form.querySelector('input[name="query"]');
    const mauticInput = form.querySelector('input[name="mautic"]');
    const changeInputs = form.querySelectorAll('select, input[type="radio"], input[type="checkbox"]');
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
            form.requestSubmit();
        }
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => submitForm(searchInput), 400);
        });
    }

    if (mauticInput) {
        mauticInput.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => submitForm(mauticInput), 400);
        });
    }

    changeInputs.forEach((input) => {
        input.addEventListener('change', () => submitForm(input));
    });

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
