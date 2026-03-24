**backwardText** — Translation key for the previous control. Default: `marketplace.index.pagination.previous`.

**backwardHref** — URL for the previous page. When empty and **route** is set, it is set to `path(route, routeQuery|merge({offset: (page - 2) * pageSize}))` when `page > 1`. After that, if there is no previous page or the control is disabled, it is cleared and the control is a `button` with `type="button"` (same rules as `pagination.html.twig`). When non-empty, a non-empty value from the caller is kept.

**disabled** — When `true`, disables prev/next and the overflow select. Default: `false`.

**forwardText** — Translation key for the next control. Default: `marketplace.index.pagination.next`.

**forwardHref** — URL for the next page. When empty and **route** is set, it is set to `path(route, routeQuery|merge({offset: page * pageSize}))` when `page < totalPages`. Then cleared when there is no next page or the control is disabled; otherwise same rules as **backwardHref**.

**id** — Prefix for `id` attributes (e.g. overflow select). Default: `pagination-nav`.

**page** — Current page (1-based). Default: `1`.

**pageSize** — Items per page; used with `offset` in `path(route, routeQuery|merge({offset: …}))` for page links. Default: `10`.

**route** — Optional route name for page-number links and the overflow `<select>`. When `null`, page controls are `<button type="button" data-page="…">`. Default: `null`.

**routeQuery** — Query parameters merged with `offset` for each page when **route** is set. Default: `{}`.

**size** — `sm`, `md`, or `lg`. Default: `md`.

**totalItems** — Total item count; used with **pageSize** to compute **totalPages** when **totalPages** is not provided. Default: `null`.

**totalPages** — Optional override for the number of pages. If omitted, computed like `pagination.html.twig`: `ceil(totalItems / pageSize)` with a minimum of `1`. Pass only when the computed value would be wrong for your data source.

Overflow (middle range as a `<select>`) uses a fixed window internally (Carbon-style); there are no extra props for that.
