// Drives the four-step "Upload a package" wizard.
//
// The template (marketplace/upload/package.html.twig) renders all four step
// forms up front; only the active one is shown. Keeping every step in the DOM
// (rather than reloading per ?step=N) means the ZIP chosen in step 1 survives
// navigation — a File object can't be re-populated into a file input after a
// reload. The flow:
//   1. Upload   — pick the ZIP; Next parses its composer.json (server-side) and
//                 prefills the Basics step before advancing.
//   2. Basics   — name, version, category, headline, keywords.
//   3. Details  — description, languages, Mautic compatibility, banner, gallery.
//   4. Pricing  — license, price, legal confirmation; Submit posts everything
//                 to /api/package/submit in a single multipart request.
//
// Each step is its own `form.needs-validation`, so validation.js validates only
// that step's fields. We re-use its exported validateForm() to gate navigation.
import { validateForm } from './validation.js';

const GENERIC_ERROR = "Something went wrong while publishing. Please try again, or contact support if it keeps happening.";

function initUploadWizard() {
    const forms = Array.from(document.querySelectorAll('form[data-wizard-step]'))
        .sort((a, b) => Number(a.dataset.wizardStep) - Number(b.dataset.wizardStep));

    if (forms.length === 0) {
        return;
    }

    const stepByNumber = new Map(forms.map((form) => [Number(form.dataset.wizardStep), form]));
    const lastStep = Math.max(...stepByNumber.keys());
    const fileInput = document.getElementById('package-zip');

    // The package type read from composer.json in step 1. SubmitApiController's
    // `category` field is the package type (mautic-plugin/theme/resource) and
    // must match composer.json exactly, so we submit this — not the granular
    // category select, which the backend has no column for yet.
    let detectedType = '';

    showStep(1);

    forms.forEach((form) => {
        const step = Number(form.dataset.wizardStep);

        // Enter key or a clicked submit button. Back is a type="button" and is
        // handled separately, so a submit here always means "advance/finish".
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const nav = event.submitter?.dataset.wizardNav || (step === lastStep ? 'submit' : 'next');
            if (nav === 'back') {
                goTo(step - 1);
                return;
            }
            handleForward(form, step, nav, event.submitter);
        });

        form.querySelectorAll('[data-wizard-nav="back"]').forEach((btn) => {
            btn.addEventListener('click', () => goTo(step - 1));
        });
    });

    function handleForward(form, step, nav, submitter) {
        form.classList.add('was-validated');
        if (!validateForm(form).valid) {
            return;
        }

        if (step === 1) {
            parseAndAdvance(form, submitter);
            return;
        }

        if (nav === 'submit' || step === lastStep) {
            submitAll(form, submitter);
            return;
        }

        goTo(step + 1);
    }

    // Step 1 → parse the ZIP's composer.json server-side, prefill Basics, advance.
    async function parseAndAdvance(form, submitter) {
        if (!fileInput || fileInput.files.length === 0) {
            return;
        }

        const restore = busy(submitter, 'Checking package...');
        hideStatus(form);

        try {
            const body = new FormData();
            body.append('zip', fileInput.files[0], fileInput.files[0].name);

            const response = await fetch('/api/package/parse-composer', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body,
            });

            let data = null;
            try { data = await response.json(); } catch (e) { /* non-JSON */ }

            if (!response.ok) {
                throw new Error((data && data.error) ? data.error : GENERIC_ERROR);
            }

            prefillBasics(data || {});
            restore();
            goTo(2);
        } catch (err) {
            console.error('Composer parse error:', err);
            showStatus(form, 'error', err.message || GENERIC_ERROR);
            restore();
        }
    }

    function prefillBasics(data) {
        detectedType = typeof data.type === 'string' ? data.type : '';
        applyCategoryOptions(data.type);
        setValue('package-name', data.name);
        setValue('package-version', data.version);
        setValue('package-description', data.description);
        if (Array.isArray(data.keywords) && data.keywords.length > 0) {
            setValue('package-keywords', data.keywords.join(', '));
        }
    }

    // Step 4 → assemble the full submission. Field names are translated from the
    // form's markup to what SubmitApiController expects (e.g. banner_image →
    // banner, gallery_images[] → gallery[], legal_confirmation → ip_ownership).
    async function submitAll(form, submitter) {
        if (!fileInput || fileInput.files.length === 0) {
            // The ZIP lives in step 1; bounce back if it was never chosen.
            showStatus(form, 'error', 'Please choose a package ZIP in step 1 before submitting.');
            goTo(1);
            return;
        }

        const restore = busy(submitter, 'Publishing...');
        hideStatus(form);

        try {
            const response = await fetch('/api/package/submit', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: buildSubmission(),
            });

            let data = null;
            try { data = await response.json(); } catch (e) { /* non-JSON */ }

            if (!response.ok) {
                throw new Error(formatError(data));
            }

            if (!data || !data.package_name) {
                throw new Error(GENERIC_ERROR);
            }

            const action = data.created ? 'published' : 'updated';
            showStatus(form, 'success', 'Package ' + action + ' successfully as "' + data.package_name + '" (version ' + data.version + '). Redirecting...');

            setTimeout(() => {
                // vendor/package — keep the slash literal so the route matches
                // without relying on the web server decoding %2F.
                const segments = data.package_name.split('/').map(encodeURIComponent).join('/');
                window.location.href = '/package/' + segments;
            }, 2000);
        } catch (err) {
            console.error('Submit error:', err);
            showStatus(form, 'error', err.message || GENERIC_ERROR);
            restore();
        }
    }

    function buildSubmission() {
        const body = new FormData();

        body.append('zip', fileInput.files[0], fileInput.files[0].name);

        appendValue(body, 'name', 'package-name');
        appendValue(body, 'version', 'package-version');
        // `category` is the package type the backend validates against composer.json.
        body.append('category', detectedType || valueById('package-category'));
        appendValue(body, 'headline', 'package-headline');
        appendValue(body, 'keywords', 'package-keywords');
        appendValue(body, 'description', 'package-description');
        appendValue(body, 'license_type', 'package-license-type');
        appendValue(body, 'price', 'package-price');
        appendValue(body, 'github_url', 'package-github-url');
        appendValue(body, 'packagist_url', 'package-packagist-url');
        appendValue(body, 'documentation', 'package-documentation');

        // Multi-value selects / checkbox groups → repeated [] fields.
        selectedOptions('package-languages').forEach((value) => body.append('languages[]', value));
        checkedValues('works_with[]').forEach((value) => body.append('works_with[]', value));

        // Images: form names differ from the API's expected fields.
        const banner = fileById('package-banner-image');
        if (banner) {
            body.append('banner', banner, banner.name);
        }
        Array.from(document.getElementById('package-gallery-images')?.files || []).forEach((file) => {
            body.append('gallery[]', file, file.name);
        });
        ['package-gallery-alt-0', 'package-gallery-alt-1'].forEach((id) => {
            body.append('gallery_alt[]', valueById(id));
        });

        // Legal confirmation checkbox → the boolean the API validates.
        if (checkedValues('legal_confirmation[]').length > 0) {
            body.append('ip_ownership_accepted', '1');
        }

        return body;
    }

    function goTo(step) {
        showStep(Math.min(Math.max(step, 1), lastStep));
    }

    function showStep(step) {
        stepByNumber.forEach((form, number) => {
            form.hidden = number !== step;
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// --- DOM helpers -----------------------------------------------------------

function setValue(id, value) {
    const el = document.getElementById(id);
    if (el && typeof value === 'string' && value !== '') {
        el.value = value;
    }
}

// Rebuild the category <select> so its options match the package type detected
// from composer.json. Option sets are emitted server-side (translated) as JSON;
// any placeholder option (empty value) is preserved.
function applyCategoryOptions(type) {
    const select = document.getElementById('package-category');
    const dataEl = document.getElementById('package-category-options-data');
    if (!select || !dataEl) {
        return;
    }

    let optionsByType;
    try {
        optionsByType = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }

    const options = optionsByType[type] || optionsByType['mautic-plugin'];
    if (!Array.isArray(options)) {
        return;
    }

    const placeholders = Array.from(select.options).filter((option) => option.value === '');
    select.innerHTML = '';
    placeholders.forEach((option) => select.appendChild(option));
    options.forEach((option) => {
        const el = document.createElement('option');
        el.value = option.value;
        el.textContent = option.label;
        select.appendChild(el);
    });
    select.value = '';
}

function valueById(id) {
    return document.getElementById(id)?.value ?? '';
}

function appendValue(body, field, id) {
    body.append(field, valueById(id));
}

function fileById(id) {
    const el = document.getElementById(id);
    return el?.files && el.files.length > 0 ? el.files[0] : null;
}

function selectedOptions(id) {
    const el = document.getElementById(id);
    if (!el || !el.selectedOptions) {
        return [];
    }
    return Array.from(el.selectedOptions).map((option) => option.value);
}

function checkedValues(name) {
    return Array.from(document.querySelectorAll('input[name="' + name + '"]:checked')).map((el) => el.value);
}

function formatError(data) {
    if (!data) {
        return GENERIC_ERROR;
    }
    if (Array.isArray(data.violations) && data.violations.length > 0) {
        return data.violations.map((v) => v.message).join(' ');
    }
    return data.error || GENERIC_ERROR;
}

function busy(button, label) {
    if (!button) {
        return () => {};
    }
    const labelEl = button.querySelector('span') || button;
    const originalLabel = labelEl.textContent;
    button.disabled = true;
    labelEl.textContent = label;
    return () => {
        button.disabled = false;
        labelEl.textContent = originalLabel;
    };
}

function getStatusEl(form) {
    let el = form.querySelector('.upload-wizard-status');
    if (!el) {
        el = document.createElement('div');
        el.className = 'upload-wizard-status alert mx-32 mt-16';
        el.setAttribute('role', 'alert');
        form.prepend(el);
    }
    return el;
}

function showStatus(form, kind, msg) {
    const el = getStatusEl(form);
    el.classList.remove('alert-danger', 'alert-success', 'd-none');
    el.classList.add(kind === 'success' ? 'alert-success' : 'alert-danger');
    el.textContent = msg;
}

function hideStatus(form) {
    const el = form.querySelector('.upload-wizard-status');
    if (el) {
        el.classList.add('d-none');
    }
}

document.addEventListener('turbo:load', initUploadWizard);
