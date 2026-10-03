---
name: malawi-ops-frontend
description: "Use for ANY frontend work in the Malawi Airlines Roster Builder: creating or changing Blade pages, page/common CSS, page/common JavaScript modules, dialogs, tables, KPI strips, status chips, alerts, icons, the login screen or the app shell. Also use when replicating a uibuilder/ mockup, adding a new screen for a feature (fleet, maintenance, flights, crew, roster, rules), or reviewing UI for accessibility and design consistency. Skip for backend-only PHP, migrations, API resources and tests."
license: Proprietary
metadata:
  author: malawi-airlines-roster-builder
---

# Malawi Airlines operations console — frontend

The UI is an **aviation dispatch console**: flat, dense, high-legibility, navy ink on a chart-grey canvas, buff ATC "flight strips", monospaced operational data. The source of truth for the look is `uibuilder/aeronautical_operations_system/DESIGN.md` plus the `uibuilder/*/screen.png` mockups. The shipped implementation is in `resources/css/common/` and `resources/views/components/`.

Read `docs/architecture.md` and the project rules in `CLAUDE.md` first. They override anything here.

## Non-negotiable rules

1. **Blade is a shell.** Pages render static structure; all domain data comes from `/api/v1` via `resources/js/common/api.js`. Never query models in Blade (model constants such as `Aircraft::STATUSES` and `config('roster.*')` are fine for labels/options).
2. **No business logic in JS.** Due/expiry states, availability counts, block minutes, etc. come from API Resources/services. JS may filter, sort and format what the server returns.
3. **Colours only in `resources/css/common/tokens.css`.** CSS uses `var(--color-*)`/`var(--palette-*)`; JS/Blade use classes and `data-tone`, never colour values. Every token is a `light-dark(light, dark)` pair: add both values for a new colour. The theme follows the system unless `<html data-theme>` is set (theme.js toggle, stored in the unencrypted `theme` cookie so Blade sets it before first paint); PDFs always take the light value.
4. **No inline `<script>`/`style=""`, no CDN fonts/scripts/icons.** Fonts are local stacks (`Public Sans`/`JetBrains Mono` if installed, system fallbacks). Icons come from the SVG sprite.
5. **API text goes in with `textContent`** (use `element()`/`chip()` helpers). Never `innerHTML` with data.
6. **Times:** pattern times are base-local; instants are UTC. Format dates with `formatDate()` (no browser timezone) and instants with `formatInstant()` (explicit UTC).
7. **Be honest.** No fake Build/Publish/Export/Reset buttons, no "FTL compliant" claims. Unimplemented features get an explanatory empty state.
8. Every page has **loading, empty and error states**; outcomes are announced through the pop-up region (it is an aria-live region).

## File layout for a page `foo`

| Concern | File |
| --- | --- |
| Route | `routes/web.php` (staff pages go in the `foreach` list) |
| Shell | `resources/views/pages/foo.blade.php` extending `layouts.app` (or `layouts.guest` for public pages) |
| Page CSS | `resources/css/pages/foo.css` — layout specific to this page only |
| Page JS | `resources/js/pages/foo.js` |
| Vite | add `'foo'` to `pages` in `vite.config.js` |
| Nav | tab entry + optional `data-tab-count` in `layouts/app.blade.php`; counts set in `resources/js/common/shell.js` |
| Test | add `['foo']` to `tests/Feature/Web/PageTest.php::pages()` |

## Blade components

- `<x-page-heading eyebrow heading description>` + slot for action buttons. Page-level outcomes are pop-ups (toast.js), not inline banners.
- `<x-kpi label icon value="group.key" meta tone href>` — skeleton until JS calls `setKpi('group.key', value, meta)`; `setKpiTone()` changes the accent.
- `<x-icon name="plane" class="icon-sm" />` — sprite icons: plane, calendar, users, route, wrench, shield, alert, plus, pencil, trash, copy, x, check, logout, clock, sliders, grid, eye, eye-off, lock, mail, chevron-left/right, search, bell, clipboard, id-card, power, ban, gauge, moon, arrow-right, zap (build), send (publish), rotate (reopen), layout (dashboard). Add new symbols to `components/icon-sprite.blade.php` only.

## CSS component vocabulary (`components.css`)

- Frame: `.topbar`, `.tabs/.tab/.tab-count`, `.workspace`, `.statusbar`, `.alert-pill`, `.user-chip`, `.avatar`.
- Surfaces: `.panel` + `.panel-heading/.panel-title/.panel-body/.panel-footer`, `.split` (content + 380px side panel), `.sticky-panel`, `.link-card`.
- Data: `table` (styled in base), `.table-wrap`, `.mono`, `.label-caps`, `.eyebrow`, `.detail-list`, `.strip` (buff flight strip with `--accent` bar; set `accent-{palette}`), `.code-tag`.
- Roster: rosters are 1 week, 2 weeks or a month (`period.length`, `period.days`); iterate the slot's dates, never assume 7. Layover days of multi-day trips render as `.activity.layover` ("Night stop"), not as free days. `.coverage` + `.coverage-bar` (a native `<progress>`, built by `coverageBar()`; `data-full` turns it green). Page-scoped roster window classes (`.week-chip`, `.roster-grid`, `.duty`, `.activity[data-kind]`, `.open-seat`, `.seat-row`, `.candidate`) live in `resources/css/pages/roster.css`.
- Feedback: `.toast-region`, `.toast[data-type]`, `.confirm-dialog[data-tone]` (built by toast.js / confirm.js — do not hand-write them).
- State: `.chip[data-tone=success|warning|danger|info|brand]` (+`.chip-dot`), `.palette-{forest|gold|sky|plum|coral}`, `.alert-row[data-tone=danger]` with `.alert-row-title/.alert-row-meta/.alert-row-icon`, `.empty-state`, `.loading-row`, `.loading-block`, `.skeleton`.
- Controls: `.button` (primary navy, uppercase mono), `.button-secondary`, `.button-quiet`, `.button-danger`, `.button-sm`, `.icon-button[data-tone=danger]`, `.segmented` (buttons with `data-value` + `aria-pressed`), `.switch[role=switch][aria-checked]`, `.search`, `.toolbar`.
- Forms/dialogs: native `<dialog class="dialog">` with `.dialog-heading`, `.form-grid` (`.span-2`), `.field-hint`, `.form-actions`, `.check-label`, `.option-cards/.option-card` (radio cards), `.day-picker`, `[data-form-status]`, `[data-close]`, `[data-close-secondary]`.

Roles in the browser: `body[data-role]` is admin | scheduler | crew_control | crew. Treat admin as staff wherever scheduler is; only show controls the server allows (it enforces everything).

PDF documents are Blade views in `resources/views/pdf` styled by `resources/css/pdf/document.css` (dompdf: tables and blocks only, no flex/grid). Use `var(--token)` there; PdfService substitutes values from tokens.css.

Semantic tones: available/valid/enabled → `success`; due soon/maintenance → `warning`; overdue/expired/grounded/AOG → `danger`; informational → `info`; neutral/unavailable → no tone.

## JavaScript helpers (`resources/js/common`)

- `api.js`: `upload(path, formData, {notify})` posts multipart forms (imports, backups) with CSRF and the usual error pop-ups. `download(path, fallbackName)` saves a file response (CSV, .ics) with the session cookie and shows errors as pop-ups. `api(path, {method, body, notify})` (CSRF-aware, throws `ApiError` with `.errors`/`.code`/`.status`), `allPages(path)`, `csrf()`. Responses follow `app/Support/Api/ApiResponse`: `{success, message, data, errors, code}`. `notify` defaults to true for POST/PUT/PATCH/DELETE: the server's configured `message` pops up on success and the error message on failure. Do **not** add client-side success messages for API changes — edit `config/api.php` wording instead. Pass `notify: false` only when the page presents the outcome itself (e.g. sign-in).
- `toast.js`: `toast(message, {type: success|info|warning|error, title})` and `flash(message, options)` (shows on the next page load, e.g. after sign-in/out). Toasts live in a manual popover so they appear above modal dialogs; they pause on hover/focus and close with Escape.
- `confirm.js`: `confirmAction({title, message, confirmLabel, cancelLabel, tone})` → `Promise<boolean>`. Use instead of `window.confirm`; destructive confirmations focus Cancel.
- `ui.js`: `status(message, error, target)` shows inline text in a `[data-form-status]` target, or a pop-up when no target is given; `showError(error, target)` never repeats a pop-up already raised by `api()`. Also `element`, `icon`, `chip`, `iconButton`, `cell`, `actions(row, onEdit, onDelete, extraButtons)`, `emptyRow`, `loadingRow`, `emptyState`, `options(select, rows, valueKey, labelKeyOrFn)`, `segmented`, `status`, `showError`, `busy`, `setKpi`, `setKpiTone`, `formatDate`, `formatInstant`, `formatMinutes`, `formatNumber`, `plural`, `dueSummary`, tone maps `aircraftStatusTone`/`dueTone`.
- `editor.js`: `editor({form, dialog, endpoint, read, fill, refresh, label})` → `{ open(record, {copy}), remove(record) }` handles PUT/POST, validation messages and `aria-invalid`.
- `roster.js`: weekly roster helpers shared by the dashboard and roster window: calendar maths on "YYYY-MM-DD" strings without the browser timezone (`parseDay`, `addDays`, `mondayOf`, `isoWeek`, `weekRange`, `shortDate`), labels (`RANKS`, `ACTIVITIES`, `WEEKDAYS`), `weekState()`, `coverageBar()`, `conflictChip()`, `conflictRow()`. Legality, conflicts and coverage always come from the API.
- `account.js`: the "My account" password dialog opened from the user chip (every page).
- `overview.js`/`shell.js`: memoised `/api/v1/overview`; call `refreshShell({ refresh: true })` after mutations so nav badges and header alert pills stay current.

## Page recipe

1. Heading card with eyebrow `Area / Sub-area`, a plain-language description, primary action right-aligned (disabled until lookups load).
2. KPI strip (`.kpi-grid`) with 4–6 server-derived counts.
3. Main `.panel`: heading with title + count chip + toolbar (search, segmented filter); table or card grid; footer explaining the time reference.
4. Optional `.split` side panel for the selected record's details.
5. Dialogs for create/edit; quick state changes (enable/disable, status) use a dedicated PATCH endpoint and a switch/option cards, never a full-form resubmit.

## Accessibility checklist

- Visible focus (`:focus-visible` ring), 44px targets for primary controls (36px for dense row tools), `aria-label` on icon-only buttons that contains any visible text.
- Segmented controls use `aria-pressed`; switches use `role="switch"` + `aria-checked`; live regions are `role="status"`/`role="alert"`.
- Never convey state by colour alone: chips carry text ("Overdue", "Grounded (AOG)").
- Layout must work at 390px: `.form-grid` collapses, tables scroll in `.table-wrap`, header pills hide.
- Respect `prefers-reduced-motion` (already global).

## Verification

Run `npm run build`, `php artisan test --compact` and `vendor/bin/pint --dirty --format agent`. For visual checks, prefer Herd (`roster-builder.test`). Do not seed demo data or create accounts in the developer's own database without asking; use an isolated scratch SQLite database if you need screenshots.
