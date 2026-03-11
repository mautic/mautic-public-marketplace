function initMarketplaceForm() {
    const form = document.querySelector('form[data-autosubmit="true"]');
    if (!form) {
        return;
    }

    const searchInput = form.querySelector('input[name="query"]');
    const typeSelect = form.querySelector('select[name="type"]');
    const mauticInput = form.querySelector('input[name="mautic"]');
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

    if (typeSelect) {
        typeSelect.addEventListener('change', () => submitForm(typeSelect));
    }

    if (mauticInput) {
        mauticInput.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => submitForm(mauticInput), 400);
        });
    }

    try {
        const saved = sessionStorage.getItem(focusKey);
        if (saved) {
            const data = JSON.parse(saved);
            const target = data && data.focus === 'mautic' ? mauticInput : searchInput;
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

document.addEventListener('turbo:load', initMarketplaceForm);
