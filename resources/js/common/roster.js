/**
 * Weekly roster helpers shared by the dashboard and the roster window. Dates are base-local calendar dates
 * ("YYYY-MM-DD") handled as UTC midnights, so the browser's own timezone never shifts a day. Every
 * decision (legality, conflicts, coverage) comes from the API; these helpers only label and format.
 */
import { chip, element, icon } from './ui';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
/** Seat / position names. */
export const RANKS = { CPT: 'Captain', FO: 'First officer', CC: 'Cabin crew' };
/** Day-planning activity labels (short for grid cells, long for detail). */
export const ACTIVITIES = {
    leave: { short: 'Leave', long: 'Leave', tone: 'leave' },
    day_off: { short: 'Off', long: 'Protected day off', tone: 'off' },
    sim: { short: 'SIM', long: 'Simulator session', tone: 'sim' },
    standby: { short: 'SBY', long: 'Standby', tone: 'standby' },
};

/** "YYYY-MM-DD" -> Date at UTC midnight. */
export function parseDay(value) { const [year, month, day] = value.split('-').map(Number); return new Date(Date.UTC(year, month - 1, day)); }
/** Date -> "YYYY-MM-DD" using its UTC calendar fields. */
export function isoDay(date) { return date.toISOString().slice(0, 10); }
/** Add whole days to a calendar date string. */
export function addDays(value, days) { const date = parseDay(value); date.setUTCDate(date.getUTCDate() + days); return isoDay(date); }
/** The Monday of the week containing a date. */
export function mondayOf(value) { const date = parseDay(value); return addDays(value, -((date.getUTCDay() + 6) % 7)); }
/** "05 Oct" */
export function shortDate(value) { const date = parseDay(value); return `${String(date.getUTCDate()).padStart(2, '0')} ${MONTHS[date.getUTCMonth()]}`; }
/** "05–11 Oct 2026" (or "28 Sep–04 Oct 2026" across months). */
export function weekRange(startsOn) {
    const start = parseDay(startsOn); const end = parseDay(addDays(startsOn, 6));
    const left = start.getUTCMonth() === end.getUTCMonth() ? String(start.getUTCDate()).padStart(2, '0') : shortDate(startsOn);
    return `${left}–${shortDate(addDays(startsOn, 6))} ${end.getUTCFullYear()}`;
}
/** ISO 8601 week number of a Monday. */
export function isoWeek(startsOn) {
    const thursday = parseDay(addDays(startsOn, 3));
    const firstThursday = new Date(Date.UTC(thursday.getUTCFullYear(), 0, 4));
    return 1 + Math.round((thursday - parseDay(mondayOf(isoDay(firstThursday)))) / 604800000);
}

/**
 * Where a week stands: not created, draft (built or not yet built) or published. Ended weeks are history.
 * Returns a label and a chip tone.
 */
export function weekState(week) {
    if (!week || week.status === 'missing') return { key: 'missing', label: 'Not created', tone: undefined };
    if (week.status === 'published') return { key: 'published', label: 'Published', tone: 'success' };
    if (!week.built_at) return { key: 'unbuilt', label: 'Draft · not built', tone: 'warning' };
    return { key: 'draft', label: 'Draft', tone: 'info' };
}

/**
 * A filled/total coverage bar. A native <progress> carries the value (no inline styles) and is announced
 * by screen readers; the text beside it repeats the numbers.
 */
export function coverageBar(filled, seats) {
    const wrap = element('div', null, 'coverage');
    const bar = element('progress', null, 'coverage-bar');
    bar.max = Math.max(seats, 1); bar.value = filled;
    bar.setAttribute('aria-label', `${filled} of ${seats} seats filled`);
    if (seats && filled === seats) bar.dataset.full = 'true';
    wrap.append(bar, element('span', `${filled}/${seats}`, 'coverage-text mono'));
    return wrap;
}

/** Chip for a conflict's severity: rule break (blocking or accepted) or warning. */
export function conflictChip(conflict) {
    if (conflict.severity !== 'danger') return chip('Warning', 'warning', { iconName: 'alert' });
    return conflict.acknowledged ? chip('Override recorded', 'info', { iconName: 'lock' }) : chip('Rule break', 'danger', { iconName: 'shield' });
}

/** One conflict as an alert row, with an optional action link or button. */
export function conflictRow(conflict, action) {
    const row = element('div', null, 'alert-row'); if (conflict.severity === 'danger' && !conflict.acknowledged) row.dataset.tone = 'danger';
    const severity = icon(conflict.severity === 'danger' ? 'shield' : 'alert'); severity.classList.add('alert-row-icon');
    const text = element('div', null, 'alert-row-text');
    const who = [conflict.rank ? RANKS[conflict.rank] : null, conflict.crew_name].filter(Boolean).join(' · ');
    text.append(element('span', `${conflict.flight_code} · ${shortDate(conflict.date)}${who ? ` · ${who}` : ''}`, 'alert-row-title'), element('span', conflict.message, 'alert-row-meta'));
    row.append(severity, text, conflictChip(conflict));
    if (action) row.append(action);
    return row;
}
