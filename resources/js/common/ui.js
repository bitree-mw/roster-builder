/**
 * Safe DOM helpers shared by every page. API text is always set with textContent (never innerHTML),
 * and dates are formatted without the browser's timezone.
 */
import { toast } from './toast';

const SVG = 'http://www.w3.org/2000/svg';
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Create an element with optional text (as textContent) and class names. */
export function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined && text !== null) node.textContent = text;
    if (className) node.className = className;
    return node;
}
/** Reference an icon from the layout sprite (resources/views/components/icon-sprite.blade.php). */
export function icon(name, className = '') {
    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('class', `icon ${className}`.trim());
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS(SVG, 'use');
    use.setAttribute('href', `#icon-${name}`);
    svg.append(use);
    return svg;
}
/** A small status chip; tone is success | warning | danger | info | brand (colours come from tokens.css). */
export function chip(text, tone, { dot = false, iconName } = {}) {
    const node = element('span', null, dot ? 'chip chip-dot' : 'chip');
    if (tone) node.dataset.tone = tone;
    if (iconName) node.append(icon(iconName, 'icon-sm'));
    node.append(text);
    return node;
}
/** An icon-only button with an accessible label and tooltip. */
export function iconButton(iconName, label, onClick, tone) {
    const button = element('button', null, 'icon-button');
    button.type = 'button'; button.title = label; button.setAttribute('aria-label', label);
    if (tone) button.dataset.tone = tone;
    button.append(icon(iconName)); button.addEventListener('click', onClick);
    return button;
}
/**
 * Report an outcome. With a target (a [data-form-status] element inside a form or dialog) the message is shown
 * inline next to the fields; without one it appears as a pop-up notification.
 */
export function status(message = '', error = false, target = null) {
    if (!target) { if (message) toast(message, { type: error ? 'error' : 'success' }); return; }
    target.textContent = message; target.dataset.error = String(error);
}
/** Show an API or client error. Pop-ups already raised by api() are not repeated. */
export function showError(error, target) {
    const messages = Object.values(error.errors || {}).flat();
    const text = messages.length ? messages.join(' ') : error.message || 'Unable to complete this action.';
    if (!target) { if (!error.notified) toast(text, { type: 'error' }); return; }
    status(text, true, target);
}
export { toast };
/** Disable a button while an async action runs, re-enabling it afterwards even on failure. */
export async function busy(button, action) {
    button.disabled = true;
    try { await action(); } finally { button.disabled = false; }
}
/** Replace a select's options; labelKey may be a property name or a function building the label. */
export function options(select, rows, valueKey = 'id', labelKey = 'code') {
    select.replaceChildren(...rows.map(row => {
        const option = element('option', typeof labelKey === 'function' ? labelKey(row) : row[labelKey]); option.value = row[valueKey]; return option;
    }));
}
/** Append a table cell to a row and return it. */
export function cell(row, value, className) { const td = element('td', value, className); row.append(td); return td; }
/** Append the standard edit/remove buttons (plus any extra buttons) as the row's last cell. */
export function actions(row, onEdit, onDelete, extra = []) {
    const td = cell(row); const wrap = element('div', null, 'row-actions');
    wrap.append(...extra, iconButton('pencil', 'Edit', onEdit), iconButton('trash', 'Remove', onDelete, 'danger'));
    td.append(wrap); return td;
}
/** Replace a table body with a single full-width empty-state row. */
export function emptyRow(tbody, columns, message, iconName = 'search') {
    const row = element('tr'); const td = cell(row); td.colSpan = columns;
    const empty = element('div', null, 'empty-state'); empty.append(icon(iconName), element('p', message)); td.append(empty);
    tbody.replaceChildren(row);
}
/** Replace a table body with a single loading row. */
export function loadingRow(tbody, columns, message = 'Loading…') {
    const row = element('tr', null, 'loading-row'); const td = cell(row, message); td.colSpan = columns; tbody.replaceChildren(row);
}
/** A centred empty/error block with icon, title and explanation. */
export function emptyState(title, message, iconName = 'clipboard') {
    const empty = element('div', null, 'empty-state'); empty.append(icon(iconName), element('h3', title), element('p', message)); return empty;
}
/** Format a calendar date string (YYYY-MM-DD) without involving the browser timezone. */
export function formatDate(value) {
    if (!value) return '—';
    const [year, month, day] = value.split('-').map(Number);
    return `${String(day).padStart(2, '0')} ${MONTHS[month - 1]} ${year}`;
}
/** Format an ISO 8601 instant explicitly in UTC. */
export function formatInstant(value) {
    if (!value) return '—';
    const date = new Date(value);
    return `${String(date.getUTCDate()).padStart(2, '0')} ${MONTHS[date.getUTCMonth()]} ${date.getUTCFullYear()} ${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')} UTC`;
}
/** 125 -> "2h05" (block times). */
export function formatMinutes(minutes) {
    if (minutes === null || minutes === undefined) return '—';
    return `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, '0')}`;
}
/** Fixed-decimal number with thousands separators, e.g. 18240.5 -> "18,240.5". */
export function formatNumber(value, digits = 1) {
    if (value === null || value === undefined) return '—';
    return Number(value).toLocaleString('en-GB', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}
/** "1 alert" / "3 alerts". */
export function plural(count, singular, pluralForm = `${singular}s`) { return `${count} ${count === 1 ? singular : pluralForm}`; }
/** Wire a segmented control (buttons with data-value) and call onChange with the selected value. */
export function segmented(container, onChange) {
    container.addEventListener('click', event => {
        const button = event.target.closest('button[data-value]'); if (!button) return;
        for (const other of container.querySelectorAll('button[data-value]')) other.setAttribute('aria-pressed', String(other === button));
        onChange(button.dataset.value);
    });
}
/** Fill a <x-kpi> card's value (and optional meta line) by its data-kpi key. */
export function setKpi(key, value, meta) {
    const target = document.querySelector(`[data-kpi="${key}"]`); if (target) target.textContent = value;
    const metaTarget = document.querySelector(`[data-kpi-meta="${key}"]`); if (metaTarget && meta !== undefined) metaTarget.textContent = meta;
}
/** Change a KPI card's accent (success | warning | danger | info, or none). */
export function setKpiTone(key, tone) {
    const card = document.querySelector(`[data-kpi-card="${key}"]`); if (!card) return;
    if (tone) card.dataset.tone = tone; else delete card.dataset.tone;
}
/** Chip tones for aircraft statuses and due/expiry states returned by the API. */
export const aircraftStatusTone = { available: 'success', maintenance: 'warning', grounded: 'danger', unavailable: undefined };
export const dueTone = { overdue: 'danger', due_soon: 'warning', ok: 'success', expired: 'danger', valid: 'success' };
/** Human description of a maintenance due item returned by the API. */
export function dueSummary(item) {
    const parts = [];
    if (item.days_remaining !== null) {
        const days = item.days_remaining;
        parts.push(days < 0 ? `overdue by ${plural(-days, 'day')}` : days === 0 ? 'due today' : `due in ${plural(days, 'day')}`);
    }
    if (item.hours_remaining !== null) {
        const hours = item.hours_remaining;
        parts.push(hours <= 0 ? `${formatNumber(-hours)} h past due hours` : `${formatNumber(hours)} h remaining`);
    }
    return parts.join(' · ');
}
