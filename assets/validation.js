const VALIDITY_ORDER = [
    'typeMismatch',
    'patternMismatch',
    'tooShort',
    'tooLong',
    'rangeUnderflow',
    'rangeOverflow',
    'stepMismatch',
    'badInput',
];

const FIELD_SELECTOR = '[data-validation-group], [data-validation-field]';

const CUSTOM_RULES = {
    fileTypes({ target, args }) {
        const input = target.focusElement;

        if (!input?.files || 0 === input.files.length) {
            return true;
        }

        const allowed = args.map((value) => value.toLowerCase()).filter(Boolean);

        if (0 === allowed.length) {
            return true;
        }

        return Array.from(input.files).every((file) => {
            const fileType = file.type.toLowerCase();
            const fileName = file.name.toLowerCase();

            return allowed.some((type) => fileType === type || fileName.endsWith(type.startsWith('.') ? type : `.${type}`));
        });
    },
    maxFiles({ target, args }) {
        const input = target.focusElement;
        const max = Number.parseInt(args[0] ?? '', 10);

        if (!Number.isFinite(max) || !input?.files) {
            return true;
        }

        return input.files.length <= max;
    },
    maxWords({ value, args }) {
        const max = Number.parseInt(args[0] ?? '', 10);

        if (!Number.isFinite(max)) {
            return true;
        }

        const wordCount = normalizeStringValue(value)
            .trim()
            .split(/\s+/u)
            .filter(Boolean)
            .length;

        return 0 === wordCount || wordCount <= max;
    },
    noSymbols({ value }) {
        const normalized = normalizeStringValue(value).trim();

        return '' === normalized || /^[\p{L}\p{N}\s]+$/u.test(normalized);
    },
    notBlank({ value }) {
        return '' !== normalizeStringValue(value).trim();
    },
    ratingSelected({ value }) {
        const rating = Number.parseInt(normalizeStringValue(value), 10);

        return rating >= 1 && rating <= 5;
    },
    requiresFileAltText({ value, args }) {
        const [fileInputId, indexValue] = args;
        const fileInput = document.getElementById(fileInputId);
        const index = Number.parseInt(indexValue ?? '', 10);

        if (!fileInput?.files || !Number.isFinite(index) || fileInput.files.length <= index) {
            return true;
        }

        return '' !== normalizeStringValue(value).trim();
    },
    regex({ value, args }) {
        const normalized = normalizeStringValue(value);

        if ('' === normalized) {
            return true;
        }

        const expression = args.join(',').trim();

        if ('' === expression) {
            return true;
        }

        const slashPattern = expression.match(/^\/(.+)\/([a-z]*)$/i);

        try {
            const pattern = slashPattern ? slashPattern[1] : expression;
            const flags = slashPattern ? slashPattern[2] : '';

            return new RegExp(pattern, flags).test(normalized);
        } catch (error) {
            console.warn('Invalid validation regex:', expression, error);

            return true;
        }
    },
};

function normalizeStringValue(value) {
    if (Array.isArray(value)) {
        return value.join(' ');
    }

    return String(value ?? '');
}

function parseJsonAttribute(value, fallback) {
    if (!value) {
        return fallback;
    }

    try {
        return JSON.parse(value);
    } catch (error) {
        console.warn('Invalid validation metadata:', value, error);

        return fallback;
    }
}

function parseRuleToken(token) {
    const normalized = String(token ?? '').trim();

    if ('' === normalized) {
        return null;
    }

    const match = normalized.match(/^([a-zA-Z][\w-]*)(?:\((.*)\))?$/);

    if (!match) {
        return { raw: normalized, name: normalized, args: [] };
    }

    return {
        raw: normalized,
        name: match[1],
        args: undefined === match[2]
            ? []
            : match[2]
                .split(',')
                .map((value) => value.trim()),
    };
}

function getTargets(form) {
    return Array.from(form.querySelectorAll(FIELD_SELECTOR))
        .filter((element) => {
            if (element.matches('[data-validation-group]')) {
                return true;
            }

            return !element.closest('[data-validation-group]');
        })
        .map((element) => element.matches('[data-validation-group]')
            ? buildGroupTarget(element)
            : buildFieldTarget(element))
        .filter(Boolean);
}

function buildFieldTarget(element) {
    return {
        kind: 'field',
        root: element,
        inputs: [element],
        focusElement: element,
    };
}

function buildGroupTarget(element) {
    const inputs = Array.from(element.querySelectorAll('input, select, textarea'))
        .filter((input) => !['button', 'submit', 'reset'].includes(input.type));
    const focusElement = resolveFocusElement(element, inputs);

    if (0 === inputs.length && !focusElement) {
        return null;
    }

    return {
        kind: 'group',
        root: element,
        inputs,
        focusElement: focusElement ?? inputs[0],
    };
}

function resolveFocusElement(root, inputs) {
    const selector = root.dataset.validationFocusSelector;

    if (selector) {
        const target = root.querySelector(selector);

        if (target) {
            return target;
        }
    }

    return inputs.find((input) => 'hidden' !== input.type) ?? inputs[0] ?? null;
}

function getMessages(target) {
    return parseJsonAttribute(target.root.dataset.validationMessages, {});
}

function getRules(target) {
    return parseJsonAttribute(target.root.dataset.validationRules, [])
        .map(parseRuleToken)
        .filter(Boolean);
}

function getLabel(target) {
    return target.root.dataset.validationLabel
        || target.focusElement?.labels?.[0]?.textContent?.trim()
        || target.focusElement?.name
        || target.focusElement?.id
        || 'Field';
}

function getFeedbackElement(target) {
    const feedbackId = target.root.dataset.validationFeedbackId;

    return feedbackId ? document.getElementById(feedbackId) : null;
}

function getHelpElement(target) {
    const helpId = target.root.dataset.validationHelpId;

    return helpId ? document.getElementById(helpId) : null;
}

function getDefaultDescribedBy(target) {
    return (target.root.dataset.validationDescribedbyDefault ?? '')
        .split(/\s+/u)
        .map((value) => value.trim())
        .filter(Boolean);
}

function getAriaTargets(target) {
    return Array.from(new Set([target.focusElement, ...target.inputs].filter(Boolean)));
}

function getRepresentativeInput(target) {
    return target.inputs[0] ?? target.focusElement ?? null;
}

function getTargetValue(target) {
    if ('group' === target.kind) {
        const checkedInputs = target.inputs.filter((input) => ['checkbox', 'radio'].includes(input.type) && input.checked);

        if (checkedInputs.length > 0) {
            return 'radio' === checkedInputs[0].type
                ? checkedInputs[0].value
                : checkedInputs.map((input) => input.value);
        }

        if (1 === target.inputs.length) {
            return target.inputs[0].value;
        }

        return '';
    }

    if (target.root instanceof HTMLSelectElement && target.root.multiple) {
        return Array.from(target.root.selectedOptions).map((option) => option.value);
    }

    if ('checkbox' === target.root.type) {
        return target.root.checked ? target.root.value : '';
    }

    if ('radio' === target.root.type) {
        return target.root.checked ? target.root.value : '';
    }

    return target.root.value;
}

function hasGroupSelection(target) {
    return target.inputs.some((input) => input.checked);
}

function setControlCustomValidity(target, message) {
    if ('field' === target.kind) {
        target.root.setCustomValidity(message);

        return;
    }

    target.inputs.forEach((input) => input.setCustomValidity(''));

    const representativeInput = getRepresentativeInput(target);

    if (representativeInput) {
        representativeInput.setCustomValidity(message);
    }
}

function resolveNativeFailure(input) {
    if (!input.validity) {
        return null;
    }

    if (input.validity.valueMissing) {
        return 'valueMissing';
    }

    return VALIDITY_ORDER.find((key) => input.validity[key]) ?? null;
}

function evaluateCustomRules(target, rules, messages) {
    const value = getTargetValue(target);

    for (const rule of rules) {
        const handler = CUSTOM_RULES[rule.name];

        if (!handler) {
            continue;
        }

        const isValid = handler({
            target,
            value,
            values: Array.isArray(value) ? value : [value],
            args: rule.args,
        });

        if (!isValid) {
            return {
                key: rule.name,
                message: messages[rule.raw] || messages[rule.name] || '',
            };
        }
    }

    return null;
}

function validateTarget(target) {
    const messages = getMessages(target);
    const rules = getRules(target);
    let failure = null;

    setControlCustomValidity(target, '');

    if ('group' === target.kind) {
        if ('true' === target.root.dataset.validationRequired && !hasGroupSelection(target)) {
            failure = {
                key: 'valueMissing',
                message: messages.valueMissing || '',
            };
        }

        if (!failure) {
            failure = evaluateCustomRules(target, rules, messages);
        }
    } else {
        const nativeFailure = resolveNativeFailure(target.root);

        if ('valueMissing' === nativeFailure) {
            failure = {
                key: nativeFailure,
                message: messages[nativeFailure] || target.root.validationMessage,
            };
        }

        if (!failure) {
            failure = evaluateCustomRules(target, rules, messages);
        }

        if (!failure) {
            const remainingNativeFailure = resolveNativeFailure(target.root);

            if (remainingNativeFailure) {
                failure = {
                    key: remainingNativeFailure,
                    message: messages[remainingNativeFailure] || target.root.validationMessage,
                };
            }
        }
    }

    if (failure) {
        setControlCustomValidity(target, failure.message || 'Invalid value.');
    }

    applyTargetState(target, failure);

    return {
        valid: !failure,
        failure,
        target,
    };
}

function applyTargetState(target, failure) {
    const invalid = Boolean(failure);
    const feedbackElement = getFeedbackElement(target);
    const helpElement = getHelpElement(target);
    const describedBy = invalid && feedbackElement ? [feedbackElement.id] : getDefaultDescribedBy(target);

    if (helpElement) {
        helpElement.hidden = invalid;
    }

    if (feedbackElement) {
        feedbackElement.hidden = !invalid;
        feedbackElement.classList.toggle('d-block', invalid);
        feedbackElement.textContent = invalid ? failure.message : '';
    }

    target.root.classList.toggle('is-invalid', invalid);

    if ('group' === target.kind) {
        target.inputs.forEach((input) => {
            input.classList.toggle('is-invalid', invalid);
        });
    } else {
        target.root.classList.toggle('is-invalid', invalid);
    }

    getAriaTargets(target).forEach((element) => {
        if (describedBy.length > 0) {
            element.setAttribute('aria-describedby', describedBy.join(' '));
        } else {
            element.removeAttribute('aria-describedby');
        }

        if (invalid) {
            element.setAttribute('aria-invalid', 'true');
        } else {
            element.removeAttribute('aria-invalid');
        }
    });
}

function focusInvalidTarget(target) {
    const anchorElement = target.focusElement ?? target.inputs.find((input) => input.id) ?? null;

    if (!anchorElement) {
        return;
    }

    if ('function' === typeof anchorElement.focus) {
        anchorElement.focus({ preventScroll: false });
    }
}

export function validateForm(form) {
    const results = getTargets(form).map(validateTarget);
    const failures = results.filter((result) => !result.valid);

    return {
        valid: 0 === failures.length,
        failures,
    };
}

function bindFieldRevalidation(form, target) {
    const events = new Set();

    if ('group' === target.kind) {
        events.add('change');
        events.add('input');
    } else {
        events.add('blur');
    }

    events.forEach((eventName) => {
        const elements = 'blur' === eventName ? [target.focusElement] : target.inputs;

        elements.filter(Boolean).forEach((element) => {
            element.addEventListener(eventName, () => {
                validateTarget(target);
            });
        });
    });
}

function initFormValidation(root = document) {
    root.querySelectorAll('form.needs-validation').forEach((form) => {
        if ('true' === form.dataset.validationBound) {
            return;
        }

        form.dataset.validationBound = 'true';

        const targets = getTargets(form);

        targets.forEach((target) => bindFieldRevalidation(form, target));

        form.addEventListener('submit', (event) => {
            form.dataset.validationSubmitted = 'true';
            form.classList.add('was-validated');

            const result = validateForm(form);

            if (!result.valid) {
                event.preventDefault();
                event.stopPropagation();
                focusInvalidTarget(result.failures[0].target);
            }
        });
    });
}

document.addEventListener('turbo:load', () => {
    initFormValidation();
});
