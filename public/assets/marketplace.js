function initMarketplaceForm() {
    const form = document.querySelector('form[data-autosubmit="true"]');
    if (!form) {
        return;
    }

    const searchInput = form.querySelector('input[name="query"]');
    const mauticInput = form.querySelector('input[name="mautic"]');
    const changeInputs = form.querySelectorAll('select, input[type="radio"], input[type="checkbox"]');
    const appliedFiltersContainer = form.querySelector('#filters-applied');
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
    const escapeHtml = (value) => value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
    const getResetRadio = (input) => {
        if (!input || input.type !== 'radio') {
            return null;
        }

        return form.querySelector(`input[type="radio"][name="${CSS.escape(input.name)}"][value=""]`);
    };
    const renderAppliedFilters = () => {
        if (!appliedFiltersContainer) {
            return;
        }

        const tags = [];

        changeInputs.forEach((input) => {
            if (!input.checked || input.value === '') {
                return;
            }

            const label = form.querySelector(`label[for="${CSS.escape(input.id)}"]`);
            if (!label) {
                return;
            }

            tags.push(`
                <div class="label label-primary" aria-label="${escapeHtml(label.textContent.trim())}" size="md">
                    <span aria-hidden="true">${escapeHtml(label.textContent.trim())}</span>
                    <button type="button" class="label-close" aria-label="Dismiss" title="Dismiss" data-filter-dismiss="${escapeHtml(input.id)}">
                        <i class="ri-close-line" aria-hidden="true" focusable="false"></i>
                    </button>
                </div>
            `);
        });

        appliedFiltersContainer.hidden = tags.length === 0;
        appliedFiltersContainer.innerHTML = tags.join('');
    };
    const dismissFilter = (inputId) => {
        const input = form.querySelector(`#${CSS.escape(inputId)}`);
        if (!input) {
            return;
        }

        if (input.type === 'checkbox') {
            input.checked = false;
        } else if (input.type === 'radio') {
            const resetRadio = getResetRadio(input);
            if (resetRadio) {
                resetRadio.checked = true;
            } else {
                input.checked = false;
            }
        }

        renderAppliedFilters();
        submitForm(input);
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
        input.addEventListener('change', () => {
            renderAppliedFilters();
            submitForm(input);
        });
    });

    if (appliedFiltersContainer && !appliedFiltersContainer.dataset.dismissBound) {
        appliedFiltersContainer.dataset.dismissBound = 'true';
        appliedFiltersContainer.addEventListener('click', (event) => {
            const button = event.target.closest('[data-filter-dismiss]');
            if (!button) {
                return;
            }

            dismissFilter(button.dataset.filterDismiss);
        });
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

    renderAppliedFilters();
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
