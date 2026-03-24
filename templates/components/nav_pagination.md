**disableOverflow** — When `true`, the '...' pagination overflow (range collapse) does not render page links between the first and last candidates. Set to `true` if you expect performance issues with large page counts.

**itemsShown** — Number of page number links to show (minimum 4 unless total pages is less than 4). Default: `10`.

**loop** — Allows user to loop from first to last page (and vice versa) using navigation controls. Default: `false`.

**page** — Current page index (1-based). Default: `1`.

**size** — Visual size token for the navigation. Possible values: `sm`, `md`, `lg`. Default: `lg`.

**tooltipAlignment** — Alignment for the tooltip on icon-only prev/next buttons. One of: `start`, `center`, `end`. Default: (varies by usage, commonly `center`).

**tooltipPosition** — Position for the tooltip on icon-only prev/next buttons. One of: `top`, `right`, `bottom`, `left`. Default: `top`.

**totalItems** — (Optional) Total number of items in the full set. Used for page count calculations. Default: not set. Example: `25`.
