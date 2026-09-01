// Upgrades any <select data-tom-select> into a searchable TomSelect widget.
//
// The package "Languages" field offers the full ISO 639 list (600+ entries), which
// is unusable as a native multi-select — there is no search and selecting several
// languages means ctrl-clicking through a giant list. TomSelect adds type-ahead
// search, removable tags and a sensible placeholder.
import TomSelect from 'tom-select';
// TomSelect's base stylesheet is vendored into styles/vendor/_tom-select.default.scss and
// imported from app.scss just before our theme override in styles/components/_tom-select.scss,
// so the override reliably wins the cascade.

function enhanceSelects() {
    document.querySelectorAll('select[data-tom-select]').forEach((el) => {
        // Re-running on turbo:load would otherwise stack a second widget on top.
        if (el.tomselect) {
            return;
        }

        new TomSelect(el, {
            placeholder: el.dataset.placeholder || '',
            plugins: el.multiple ? ['remove_button'] : [],
            maxOptions: null,
            // Match on the visible label so "Engl" finds "English".
            searchField: ['text'],
        });
    });
}

document.addEventListener('turbo:load', enhanceSelects);

export { enhanceSelects };
