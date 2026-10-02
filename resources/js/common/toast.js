/**
 * Pop-up notifications ("toasts") for system interactions.
 *
 * toast(message, { type, title })   success | info | warning | error
 * flash(message, { type, title })   shows the toast on the next page load (e.g. after sign-in / sign-out)
 *
 * The region is a manual popover so notifications appear above modal dialogs (both live in the top layer).
 * Toasts pause while hovered or focused, can be dismissed with the close button or Escape, and never
 * use innerHTML: all text goes in with textContent.
 */
const SVG = 'http://www.w3.org/2000/svg';
const FLASH_KEY = 'roster-builder:flash';
const MAX_VISIBLE = 4;
const DEFAULTS = {
    success: { title: 'Done', icon: 'check', duration: 4500 },
    info: { title: 'Notice', icon: 'bell', duration: 5000 },
    warning: { title: 'Attention', icon: 'alert', duration: 7000 },
    error: { title: 'Something went wrong', icon: 'alert', duration: 9000 },
};
let region = null;

/** Minimal element factory (kept local so this module has no dependency on ui.js, which imports it). */
function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined && text !== null) element.textContent = text;
    return element;
}
/** Icon from the layout's SVG sprite. */
function svgIcon(name) {
    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('class', 'icon'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false');
    const use = document.createElementNS(SVG, 'use'); use.setAttribute('href', `#icon-${name}`); svg.append(use);
    return svg;
}
/** Older browsers without the Popover API fall back to a plain fixed-position region. */
const supportsPopover = () => typeof HTMLElement !== 'undefined' && 'popover' in HTMLElement.prototype;

/** Create the notification region once (an aria-live region, and a manual popover where supported). */
function getRegion() {
    if (region?.isConnected) return region;
    region = node('section', 'toast-region');
    region.setAttribute('aria-label', 'Notifications');
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-relevant', 'additions');
    if (supportsPopover()) region.setAttribute('popover', 'manual');
    document.body.append(region);
    return region;
}
/** Re-insert the region at the top of the top layer so it sits above any dialog opened since. */
function raise() {
    const target = getRegion();
    if (!supportsPopover()) return;
    try {
        if (target.matches(':popover-open')) target.hidePopover();
        target.showPopover();
    } catch { /* popover unsupported in this context; the region still renders as a fixed element */ }
}
/** Close the popover when the last toast has gone so it does not sit in the top layer. */
function hideIfEmpty() {
    if (!region || region.childElementCount || !supportsPopover()) return;
    try { if (region.matches(':popover-open')) region.hidePopover(); } catch { /* ignore */ }
}

/** Animate a toast out and remove it (immediately when the user prefers reduced motion). */
function dismiss(item) {
    if (!item.isConnected || item.dataset.leaving) return;
    item.dataset.leaving = 'true';
    clearTimeout(item.timer);
    const remove = () => { item.remove(); hideIfEmpty(); };
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) remove();
    else { item.addEventListener('animationend', remove, { once: true }); setTimeout(remove, 400); }
}
/** Start (or restart) the auto-dismiss timer with the toast's remaining time. */
function schedule(item) {
    clearTimeout(item.timer);
    item.startedAt = Date.now();
    item.timer = setTimeout(() => dismiss(item), item.remaining);
}
/** Pause auto-dismiss while the toast is hovered or focused, remembering the time left. */
function pause(item) {
    if (item.paused) return;
    item.paused = true; clearTimeout(item.timer);
    item.remaining = Math.max(1200, item.remaining - (Date.now() - item.startedAt));
    item.dataset.paused = 'true';
}
/** Resume auto-dismiss once the pointer and focus have both left the toast. */
function resume(item) {
    if (!item.paused || item.matches(':hover') || item.contains(document.activeElement)) return;
    item.paused = false; delete item.dataset.paused; schedule(item);
}

export function toast(message, { type = 'success', title, duration } = {}) {
    if (!message) return null;
    const settings = DEFAULTS[type] ?? DEFAULTS.info;
    const container = getRegion();
    raise();

    const existing = [...container.children].find(item => item.dataset.type === type && item.dataset.message === message && !item.dataset.leaving);
    if (existing) { existing.remaining = duration ?? settings.duration; existing.paused = false; schedule(existing); existing.dataset.repeat = String(Number(existing.dataset.repeat || 1) + 1); return existing; }

    const item = node('div', 'toast');
    item.dataset.type = type; item.dataset.message = message;
    if (type === 'error' || type === 'warning') item.setAttribute('role', 'alert');
    const badge = node('span', 'toast-icon'); badge.append(svgIcon(settings.icon));
    const body = node('div', 'toast-body');
    body.append(node('p', 'toast-title', title ?? settings.title), node('p', 'toast-message', message));
    const close = node('button', 'toast-close'); close.type = 'button'; close.setAttribute('aria-label', 'Dismiss notification'); close.append(svgIcon('x'));
    close.addEventListener('click', () => dismiss(item));
    const progress = node('span', 'toast-progress'); progress.setAttribute('aria-hidden', 'true');
    item.append(badge, body, close, progress);
    item.addEventListener('mouseenter', () => pause(item));
    item.addEventListener('mouseleave', () => resume(item));
    item.addEventListener('focusin', () => pause(item));
    item.addEventListener('focusout', () => setTimeout(() => resume(item), 0));
    item.addEventListener('keydown', event => { if (event.key === 'Escape') { event.stopPropagation(); dismiss(item); } });

    container.append(item);
    const active = [...container.children].filter(child => !child.dataset.leaving);
    for (const old of active.slice(0, Math.max(0, active.length - MAX_VISIBLE))) dismiss(old);
    item.remaining = duration ?? settings.duration;
    schedule(item);
    return item;
}

/** Queue a toast for the next page (sign-in, sign-out, redirects). Falls back to showing it now. */
export function flash(message, options = {}) {
    try { sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message, ...options })); }
    catch { toast(message, options); }
}
/** Show (once) a toast queued by flash() on the previous page. */
function consumeFlash() {
    let stored = null;
    try { stored = sessionStorage.getItem(FLASH_KEY); sessionStorage.removeItem(FLASH_KEY); } catch { return; }
    if (!stored) return;
    try { const { message, ...options } = JSON.parse(stored); toast(message, options); } catch { /* ignore malformed flash */ }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', consumeFlash, { once: true });
else consumeFlash();
