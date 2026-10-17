/**
 * Roster window (pages/roster.blade.php): rosters of 1 week, 2 weeks or a calendar month.
 *
 * Staff: a timeline of past and upcoming weeks; for the selected week a crew × day grid, a trips view and a
 * conflicts view; a duty inspector explaining each seat; and actions to create, build (the server-side
 * generator), publish and reopen the week. Any seat can be changed through the seat editor, which lists
 * candidates checked by the server and asks for a reason when a choice breaks a rule.
 *
 * Staff also plan leave, days off, SIM and standby from a crew member's name, exclude crew from a trip,
 * undo the last seat change, download CSV or calendar files, print the week and email it to crew.
 *
 * Crew: only published weeks, their own duties, activities and accumulated hours, and their own CSV and
 * calendar downloads (the API filters all of this).
 *
 * All legality, coverage and conflict decisions come from the API. This script only filters, groups and
 * formats them. Dates are base-local calendar strings; local clock times come from the server snapshot.
 */
import { api, download } from '../common/api';
import { confirmAction } from '../common/confirm';
import { ACTIVITIES, RANKS, WEEKDAYS, addDays, conflictRow, mondayOf, parseDay, shortDate, weekState } from '../common/roster';
import { busy, chip, element, emptyState, formatDate, formatInstant, formatMinutes, icon, iconButton, normalizeClock, plural, segmented, setKpi, setKpiTone, showError, status, toast } from '../common/ui';

const staff = ['admin', 'scheduler', 'crew_control'].includes(document.body.dataset.role);
const today = document.querySelector('#roster-root').dataset.today;
// The timeline shows 13 weeks at a time; rosters are 1 week, 2 weeks or a calendar month.
const WINDOW_DAYS = 7 * 13;
const LENGTHS = { week: '1 week', fortnight: '2 weeks', month: 'Month' };
// Plain-language labels for the generator's rejection codes (shown for open seats).
const REASONS = {
    inactive: 'inactive', rank: 'other position', base: 'other base', rating: 'not rated', document_missing: 'document missing',
    document_expired: 'document expires', excluded: 'excluded', already_on_trip: 'already on this trip', unavailable: 'leave, day off, SIM or standby',
    overlap: 'already on duty', rest: 'not enough rest', duty_7d: '7-day duty limit', block_month: 'monthly block limit',
    consecutive_days: 'consecutive duty days', days_off_month: 'monthly days off', weekly_hours: 'weekly working hours', positioning: 'would need positioning',
};

// Page state. "slot" is the selected timeline entry (a roster or a gap without one); "week" is the full
// roster for it (null when there is none). ?start= (or the older ?week=) selects the slot containing a date.
const query = new URLSearchParams(window.location.search);
const requested = [query.get('start'), query.get('week')].find(value => /^\d{4}-\d{2}-\d{2}$/.test(value || '')) ?? today;
const state = {
    windowStart: addDays(mondayOf(requested), -28),
    periods: [],
    slots: [],
    slot: null,
    week: null,
    crewId: Number(query.get('crew')) || null,
    view: query.get('crew') ? 'individual' : 'grid',
    rank: '',
    search: '',
    dutiesOnly: false,
    tripId: Number(query.get('trip')) || null,
    index: null,
    openCollapsed: openTimeCollapsed(),
};

/**
 * Whether this viewer collapsed the grid's Open time row last time. Only this layout preference is kept in the
 * browser (never roster data); storage can be blocked, so it falls back to expanded.
 */
function openTimeCollapsed() {
    try { return window.localStorage.getItem('roster.openTimeCollapsed') === 'true'; } catch { return false; }
}

/** Remember the Open time row's collapsed state for the next visit (ignored when storage is unavailable). */
function rememberOpenTime(collapsed) {
    try { window.localStorage.setItem('roster.openTimeCollapsed', String(collapsed)); } catch { /* preference only */ }
}

const view = document.querySelector('#roster-view');
const strip = document.querySelector('#week-strip');
const inspector = document.querySelector('#inspector');
const notice = document.querySelector('#week-notice');
const buttons = {
    build: document.querySelector('#build-week'),
    publish: document.querySelector('#publish-week'),
    reopen: document.querySelector('#reopen-week'),
    email: document.querySelector('#email-week'),
};

/* ---------------------------------------------------------------- Formatting helpers */

/** "HH:MM" base-local clock time of a UTC instant, using the week's explicit UTC offset (not the browser's). */
function localClock(instant, offset) {
    const date = new Date(Date.parse(instant) + offset * 60000);
    return `${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')}`;
}
/** "05 Oct 2026 05:00 → 11:30 UTC", repeating the date only when the duty ends on a later UTC day. */
function utcRange(report, release) {
    const start = formatInstant(report); const end = formatInstant(release);
    return start.slice(0, 11) === end.slice(0, 11) ? `${start.slice(0, -4)} → ${end.slice(12)}` : `${start} → ${end}`;
}
/** "Captain", "Cabin crew 2". */
function seatName(seat) { return seat.rank === 'CC' ? `${RANKS.CC} ${seat.seat_number}` : RANKS[seat.rank]; }
/** Week status for this viewer: crew only ever receive published weeks, so anything else is "Not published" to them. */
function viewerState(period) {
    const status = weekState(period);
    return !staff && status.key === 'missing' ? { ...status, label: 'Not published' } : status;
}
/** Whether a slot (roster or gap) has ended: its last day is before today at base. */
function ended(slot) { return Boolean(slot) && slot.end < today; }
/** Whether seats on a trip can still be changed by the scheduler: drafts and published rosters, not ended or flown. */
function changeable(trip) { return staff && state.week?.changeable && !trip.operated; }
/**
 * The duty periods a seat's holder flies: a captain section's own periods (worked out by the server), or the
 * whole trip's for every other seat.
 */
function seatDuties(trip, seat = null) { return seat?.section?.duties ?? trip.duties; }
/** The duty periods of a trip (or of a captain's section of it) that touch a date (a night stop has one per day). */
function dutiesOn(trip, date, seat = null) { return seatDuties(trip, seat).filter(duty => duty.dates.includes(date)); }
/**
 * Where a trip's crew are staying on a day without flying between two of its duty periods (a multi-night
 * layover), or null. The crew are away from base, so the day is not free to plan. For a captain section only
 * the layovers inside that section count.
 */
function layoverOn(trip, date, seat = null) {
    const duties = seatDuties(trip, seat);
    if (dutiesOn(trip, date, seat).length || !duties.some(duty => duty.dates[0] > date) || !duties.some(duty => duty.dates[duty.dates.length - 1] < date)) return null;
    const before = duties.filter(duty => duty.dates[duty.dates.length - 1] < date);
    const legs = before[before.length - 1]?.legs ?? [];
    return legs[legs.length - 1]?.to_airport ?? null;
}
/** "Night stop · MZU" tag for a layover day; selecting it opens the trip like a duty does. */
function layoverTag(trip, airport) {
    const tag = element('button', null, 'activity layover'); tag.type = 'button';
    tag.dataset.tripId = trip.id;
    tag.append(element('strong', 'Night stop'), element('span', `${trip.code} · ${airport}`, 'mono'));
    tag.title = `Away at ${airport} with ${trip.code}; the crew stay with the trip until it is back at base`;
    // A night stop of a disabled flight carries the same warning as its duties.
    if (!trip.flight_active && !trip.operated) { tag.dataset.flight = 'disabled'; tag.append(element('span', 'Flight disabled', 'duty-disabled')); tag.title = `${trip.code} is disabled on Flight routes. Review the route changes and rebuild to remove this trip.`; }
    tag.addEventListener('click', () => selectTrip(trip.id));
    return tag;
}
/** First report and last release, base local. */
function tripTimes(trip) {
    if (!trip.duties.length) return '';
    return `${trip.duties[0].report_local}–${trip.duties[trip.duties.length - 1].release_local} LT`;
}

/* ---------------------------------------------------------------- Roster timeline */

/**
 * Timeline slots for the visible window: one per existing roster (whatever its length), and "no roster"
 * gaps between them, each gap at most one Monday–Sunday week long.
 */
function buildSlots() {
    const slots = [];
    const last = addDays(state.windowStart, WINDOW_DAYS - 1);
    let date = state.windowStart;
    while (date <= last) {
        const period = state.periods.find(item => item.starts_on <= date && item.ends_on >= date);
        if (period) { slots.push({ key: period.starts_on, start: period.starts_on, end: period.ends_on, period }); date = addDays(period.ends_on, 1); continue; }
        const next = state.periods.find(item => item.starts_on > date);
        let end = addDays(mondayOf(date), 6);
        if (next && addDays(next.starts_on, -1) < end) end = addDays(next.starts_on, -1);
        slots.push({ key: date, start: date, end, period: null });
        date = addDays(end, 1);
    }
    return slots;
}

/** Load the rosters overlapping the visible window and redraw the strip. */
async function loadTimeline() {
    const to = addDays(state.windowStart, WINDOW_DAYS - 1);
    const { data } = await api(`/api/v1/roster-periods?from=${state.windowStart}&to=${to}`);
    state.periods = data;
    state.slots = buildSlots();
    renderTimeline();
}

/** "05–11 Oct" or "28 Sep–04 Oct" for a slot. */
function slotRange(slot) {
    if (slot.start === slot.end) return shortDate(slot.start);
    const sameMonth = slot.start.slice(0, 7) === slot.end.slice(0, 7);
    return `${sameMonth ? slot.start.slice(8) : shortDate(slot.start)}–${shortDate(slot.end)}`;
}

/** Every date of the selected slot (a week, two weeks, a month or a gap). */
function slotDates() {
    const dates = [];
    for (let date = state.slot.start; date <= state.slot.end; date = addDays(date, 1)) dates.push(date);
    return dates;
}

/** One button per slot: length, dates, status and (staff) open seats. The selected slot is pressed. */
function renderTimeline() {
    strip.setAttribute('aria-busy', 'false');
    strip.replaceChildren(...state.slots.map(slot => {
        const status = viewerState(slot.period);
        const item = element('div', null, 'week-item'); item.setAttribute('role', 'listitem');
        const button = element('button', null, 'week-chip'); button.type = 'button';
        button.dataset.state = status.key; button.dataset.length = slot.period?.length ?? 'gap';
        button.setAttribute('aria-pressed', String(slot.key === state.slot?.key));
        if (slot.start <= today && slot.end >= today) button.dataset.current = 'true';
        if (ended(slot)) button.dataset.past = 'true';
        const top = element('span', null, 'week-chip-top');
        const when = slot.start <= today && slot.end >= today ? 'Now' : ended(slot) ? 'Past' : 'Upcoming';
        top.append(element('span', slot.period ? LENGTHS[slot.period.length] : 'No roster', 'label-caps'), element('span', when, 'week-chip-when'));
        button.append(top, element('span', slot.period?.length === 'month' ? slot.period.label : `${slotRange(slot)} ${slot.end.slice(0, 4)}`, 'week-chip-range mono'));
        const chips = element('span', null, 'chip-list'); chips.append(chip(status.label, status.tone, { dot: true }));
        if (staff && slot.period?.open_seats_count) chips.append(chip(`${slot.period.open_seats_count} open`, 'warning'));
        // Flights were disabled, enabled or edited after this roster was built: it needs a review and rebuild.
        if (staff && slot.period?.route_changes_count) chips.append(chip('Routes changed', 'warning', { iconName: 'route' }));
        button.append(chips);
        button.addEventListener('click', () => selectSlot(slot.key).catch(showError));
        item.append(button); return item;
    }));
    strip.querySelector('[aria-pressed="true"]')?.scrollIntoView({ block: 'nearest', inline: 'center' });
}

/**
 * Select the slot containing a date (moving the window when needed), record it in the URL and load it.
 */
async function selectSlot(date, { tripId = null } = {}) {
    state.tripId = tripId;
    if (date < state.windowStart || date > addDays(state.windowStart, WINDOW_DAYS - 1)) {
        state.windowStart = addDays(mondayOf(date), -28);
        await loadTimeline();
    }
    state.slot = state.slots.find(slot => slot.start <= date && slot.end >= date) ?? state.slots[0];
    renderTimeline();
    const url = new URL(window.location.href); url.searchParams.set('start', state.slot.start); url.searchParams.delete('week');
    if (tripId) url.searchParams.set('trip', tripId); else url.searchParams.delete('trip');
    window.history.replaceState(null, '', url);
    await loadWeek();
}

/* ---------------------------------------------------------------- Selected roster */

/** Load the roster for the selected slot, or show the "no roster" state. */
async function loadWeek() {
    view.setAttribute('aria-busy', 'true');
    const period = state.slot?.period;
    if (!period) { state.week = null; state.index = null; renderMissing(); return; }
    const { data } = await api(`/api/v1/roster-periods/${period.id}`);
    applyWeek(data);
}

/**
 * Store a roster returned by the API (after loading, building, publishing or reopening) and redraw
 * everything that depends on it.
 */
function applyWeek(week) {
    state.week = week;
    const index = state.periods.findIndex(period => period.id === week.id);
    // Keep the timeline chip in step with the window (route changes clear after a confirmed rebuild).
    const summary = { ...week, open_seats_count: week.summary.open, route_changes_count: week.route_changes?.count ?? 0 };
    if (index >= 0) state.periods[index] = { ...state.periods[index], ...summary }; else state.periods.push(summary);
    state.periods.sort((a, b) => a.starts_on.localeCompare(b.starts_on));
    state.slots = buildSlots();
    state.slot = state.slots.find(slot => slot.period?.id === week.id) ?? state.slot;
    state.index = buildIndex(week);
    if (state.tripId && !week.trips.some(trip => trip.id === state.tripId)) state.tripId = null;
    renderTimeline(); renderHeader(); renderNotice(); renderKpis(); renderActions(); renderView(); renderInspector();
}

/**
 * Lookups used by every view: seats per crew member, conflicts per seat and per trip, and the pending route
 * change ("removed" or "changed") of each trip so cards and the inspector can say what a rebuild will do.
 */
function buildIndex(week) {
    const seatsByCrew = new Map(); const seatConflicts = new Map(); const tripConflicts = new Map();
    const routeChanges = new Map([
        ...(week.route_changes?.removed || []).map(trip => [trip.trip_id, 'removed']),
        ...(week.route_changes?.changed || []).map(trip => [trip.trip_id, 'changed']),
    ]);
    for (const trip of week.trips) {
        for (const seat of trip.assignments) {
            if (seat.crew_member_id) seatsByCrew.set(seat.crew_member_id, [...(seatsByCrew.get(seat.crew_member_id) || []), { trip, seat }]);
        }
    }
    for (const conflict of week.conflicts) {
        if (conflict.assignment_id && conflict.code !== 'open_seat') seatConflicts.set(conflict.assignment_id, [...(seatConflicts.get(conflict.assignment_id) || []), conflict]);
        if (!conflict.assignment_id) tripConflicts.set(conflict.trip_id, [...(tripConflicts.get(conflict.trip_id) || []), conflict]);
    }
    return { seatsByCrew, seatConflicts, tripConflicts, routeChanges };
}

/** Roster title and status chip. */
function renderHeader() {
    document.querySelector('#week-title').textContent = state.week ? state.week.label : `No roster · ${slotRange(state.slot)} ${state.slot.end.slice(0, 4)}`;
    const status = viewerState(state.week);
    const target = document.querySelector('#week-state');
    target.textContent = ended(state.slot) && state.week ? `${status.label} · ended` : status.label;
    if (status.tone) target.dataset.tone = status.tone; else delete target.dataset.tone;
}

/**
 * One-line explanation of what can be done with the selected roster. Pending route changes (flights disabled,
 * enabled or edited since the build) come first for staff, with a button to review them.
 */
function renderNotice() {
    const week = state.week;
    let message = null; let tone = 'info'; let action = null;
    const routeChanges = staff && !ended(state.slot) ? week?.route_changes : null;
    if (week && ended(state.slot)) message = 'This roster has ended. It is kept as read-only history.';
    else if (routeChanges?.count) {
        tone = 'warning';
        message = `Flight routes changed since this roster was built: ${routeChangeSummary(routeChanges)}. ${week.status === 'published' ? 'Reopen it as a draft, then review and confirm the changes to rebuild.' : 'Review and confirm the changes to rebuild.'}`;
        action = element('button', 'Review changes', 'button button-sm button-secondary'); action.type = 'button';
        action.addEventListener('click', openChanges);
    }
    else if (week?.status === 'published' && staff && week.changed_crew?.length) {
        // Changed after publication (edited, or published again): name how many crew to tell and offer to email them.
        message = `${plural(week.changed_crew.length, 'crew member')} ${week.changed_crew.length === 1 ? 'has' : 'have'} changed duties they have not been told about yet. They already see them in the app.`;
        action = element('button', 'Email changed crew', 'button button-sm button-secondary'); action.type = 'button';
        action.addEventListener('click', event => busy(event.currentTarget, emailChangedCrew));
    }
    else if (week?.status === 'published') message = staff ? 'Published to crew. You can still change seats and planes; crew see changes straight away and you can email them afterwards. Reopen it as a draft to rebuild.' : null;
    else if (week && staff && !week.built_at) { message = 'This draft has not been built yet. Build it to fill every seat automatically from crew working hours and the duty rules. Click an empty day in the grid to plan leave, a day off, standby or SIM first.'; tone = 'warning'; }
    else if (week && staff && !week.trips.length) { message = 'No trips in this roster: no enabled flight operates on its remaining days. Check Flight routes, then rebuild.'; tone = 'warning'; }
    else if (week && staff && week.summary.blocking) { message = `${plural(week.summary.blocking, 'rule conflict')} must be changed or accepted with an override reason before this roster can be published.`; tone = 'danger'; }
    notice.hidden = !message;
    if (message) { notice.dataset.tone = tone; notice.replaceChildren(icon(tone === 'danger' ? 'shield' : tone === 'warning' ? 'alert' : 'clipboard'), element('span', message)); }
    if (message && action) notice.append(action);
}

/** KPI strip: coverage and conflicts for staff, own totals for crew. */
function renderKpis() {
    const week = state.week;
    if (!staff) {
        setKpi('week.duties', week ? week.summary.duties : '—');
        setKpi('week.duty', week ? formatMinutes(week.summary.duty_minutes) : '—');
        setKpi('week.block', week ? formatMinutes(week.summary.block_minutes) : '—');
        return;
    }
    if (!week) {
        for (const key of ['coverage', 'open', 'conflicts', 'crew', 'hours']) { setKpi(`week.${key}`, '—'); setKpiTone(`week.${key}`, null); }
        return;
    }
    const summary = week.summary;
    setKpi('week.coverage', `${summary.filled}/${summary.seats}`, `${summary.seats ? Math.round(100 * summary.filled / summary.seats) : 0}% of ${plural(summary.trips, 'trip')}`);
    setKpiTone('week.coverage', summary.seats && summary.filled === summary.seats ? 'success' : 'warning');
    setKpi('week.open', summary.open, summary.open ? 'No legal crew available' : 'Every seat filled');
    setKpiTone('week.open', summary.open ? 'warning' : 'success');
    setKpi('week.conflicts', summary.blocking, `${summary.acknowledged} accepted with override · ${plural(summary.warnings, 'warning')}`);
    setKpiTone('week.conflicts', summary.blocking ? 'danger' : 'success');
    setKpi('week.crew', state.index.seatsByCrew.size, `of ${week.crew.filter(crew => crew.active).length} active crew`);
    const minutes = week.crew.reduce((total, crew) => total + crew.period_duty_minutes, 0);
    setKpi('week.hours', formatMinutes(minutes), 'Duty incl. timed SIM / standby');
}

/**
 * Show only the roster actions that apply: build/rebuild, publish, reopen or email; and enable the
 * downloads once the roster exists.
 */
function renderActions() {
    const week = state.week;
    for (const id of ['#download-csv', '#download-pdf', '#download-crew-pdf', '#download-calendar', '#print-week']) {
        const button = document.querySelector(id); if (button) button.disabled = !week;
    }
    if (!staff) return;
    const past = ended(state.slot);
    buttons.email.hidden = week?.status !== 'published';
    document.querySelector('#email-log').hidden = week?.status !== 'published';
    buttons.build.hidden = !week?.editable;
    // With pending route changes the rebuild goes through the review dialog first, so say so on the button.
    buttons.build.querySelector('[data-label]').textContent = !week?.built_at ? 'Build roster' : week.route_changes?.count ? 'Review changes & rebuild' : 'Rebuild roster';
    buttons.publish.hidden = !(week?.editable && week.built_at);
    buttons.publish.disabled = Boolean(week?.summary.blocking);
    buttons.publish.title = week?.summary.blocking ? 'Resolve the rule conflicts first' : '';
    buttons.reopen.hidden = !(week?.status === 'published' && !past);
}

/* ---------------------------------------------------------------- Creating rosters (1 week, 2 weeks or a month) */

// What each length means, shown on the option cards of the create form.
const LENGTH_HELP = { week: 'Monday to Sunday', fortnight: 'Two weeks from a Monday', month: 'A whole calendar month, from the 1st' };

/**
 * First valid start on or after a date for a roster of a length: a Monday for one or two weeks, the 1st for a
 * month. Dates before this week are moved up to this week (the current month for a monthly roster).
 */
function suggestedStart(length, from = state.slot?.start ?? today) {
    const start = from < today ? mondayOf(today) : from;
    if (length === 'month') return start.slice(8) === '01' ? start : addDays(`${start.slice(0, 8)}01`, 40).slice(0, 8) + '01';
    return parseDay(start).getUTCDay() === 1 ? start : addDays(mondayOf(start), 7);
}

/** Last day of a roster of a length starting on a date (the same rule as RosterPeriod::endFor on the server). */
function rosterEnd(start, length) {
    if (length === 'month') { const day = parseDay(start); return new Date(Date.UTC(day.getUTCFullYear(), day.getUTCMonth() + 1, 0)).toISOString().slice(0, 10); }
    return addDays(start, length === 'fortnight' ? 13 : 6);
}

/** The first day after the latest loaded roster that is still running or upcoming (today when there is none). */
function nextFreeDate() {
    const latest = state.periods.filter(period => period.ends_on >= today).reduce((last, period) => (period.ends_on > last ? period.ends_on : last), '');
    return latest ? addDays(latest, 1) : today;
}

/**
 * Checks shown under the create form before anything is sent: the start day rule, dates that have already
 * passed, and overlap with a roster already on the timeline. The server repeats every check.
 *
 * @returns {{ text: string, tone: string|undefined }}
 */
function createPreview(start, length) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(start)) return { text: 'Choose the first day of the roster.', tone: 'warning' };
    if (length === 'month' && start.slice(8) !== '01') return { text: 'A monthly roster starts on the 1st of the month.', tone: 'warning' };
    if (length !== 'month' && parseDay(start).getUTCDay() !== 1) return { text: 'One- and two-week rosters start on a Monday.', tone: 'warning' };
    const end = rosterEnd(start, length);
    const days = Math.round((parseDay(end) - parseDay(start)) / 86400000) + 1;
    const covers = `Covers ${WEEKDAYS[(parseDay(start).getUTCDay() + 6) % 7]} ${formatDate(start)} – ${WEEKDAYS[(parseDay(end).getUTCDay() + 6) % 7]} ${formatDate(end)} (${days} days).`;
    if (end < today) return { text: `${covers} These dates have passed.`, tone: 'warning' };
    const same = state.periods.find(period => period.starts_on === start && period.length === length);
    if (same) return { text: `${covers} This roster already exists; it will be opened and built.`, tone: 'info' };
    const overlap = state.periods.find(period => period.starts_on <= end && period.ends_on >= start);
    if (overlap) return { text: `${covers} This overlaps the roster for ${overlap.label}.`, tone: 'warning' };
    return { text: covers, tone: 'success' };
}

/**
 * The create form used by the "New roster" dialog and the "No roster" view: three length cards (1 week,
 * 2 weeks, calendar month), the start date (moved to a valid start when the length changes), a preview of the
 * dates covered, and "Create & build roster". onDone runs after the roster is created and built.
 */
function createRosterForm({ from, onDone = () => {} } = {}) {
    const form = element('form', null, 'create-roster-form'); form.noValidate = true;
    const remembered = (() => { try { return localStorage.getItem('roster.length') || 'fortnight'; } catch { return 'fortnight'; } })();
    // Start with the remembered length unless it would overlap a roster or start in the past; then the first that fits.
    let length = [remembered, 'fortnight', 'week', 'month'].find(value => createPreview(suggestedStart(value, from), value).tone !== 'warning') ?? remembered;
    const cards = element('fieldset', null, 'create-lengths');
    cards.append(element('legend', 'How long does the roster run?'));
    const options = element('div', null, 'option-cards');
    for (const [value, label] of Object.entries(LENGTHS)) {
        const card = element('label', null, 'option-card');
        const radio = element('input'); radio.type = 'radio'; radio.name = 'length'; radio.value = value; radio.checked = value === length;
        const text = element('span'); text.append(element('strong', label === 'Month' ? 'Calendar month' : label), element('small', LENGTH_HELP[value]));
        card.append(radio, text); options.append(card);
    }
    cards.append(options);
    const startLabel = element('label', 'Starts on'); const start = element('input'); start.type = 'date'; start.name = 'starts_on'; start.required = true;
    start.value = suggestedStart(length, from); startLabel.append(start);
    const preview = element('p', '', 'create-preview'); preview.setAttribute('role', 'status');
    const status = element('div', null, 'status'); status.dataset.formStatus = ''; status.setAttribute('role', 'alert');
    const submit = element('button', null, 'button'); submit.type = 'submit'; submit.append(icon('zap', 'icon-sm'), 'Create & build roster');
    /** Redraw the preview line for the current choice. */
    const update = () => { const { text, tone } = createPreview(start.value, length); preview.textContent = text; preview.dataset.tone = tone ?? ''; };
    options.addEventListener('change', event => {
        length = event.target.value; start.value = suggestedStart(length, start.value || from); update();
        try { localStorage.setItem('roster.length', length); } catch { /* remembering the choice is optional */ }
    });
    start.addEventListener('input', update);
    form.append(cards, startLabel, preview, status, submit);
    form.addEventListener('submit', event => {
        event.preventDefault();
        busy(submit, async () => {
            try { await createPeriod(start.value, length); onDone(); } catch (error) { showError(error, status); }
        });
    });
    update();
    return form;
}

/** No roster for the selected slot: explain, and let staff create one (1 week, 2 weeks or a month) and build it. */
function renderMissing() {
    view.setAttribute('aria-busy', 'false');
    renderHeader(); renderNotice(); renderKpis(); renderActions(); renderInspector();
    document.querySelector('#conflict-tab-count')?.replaceChildren();
    if (!staff) return view.replaceChildren(emptyState('No published roster for these dates', 'Your duties appear here once crew control publishes the roster.', 'calendar'));
    if (ended(state.slot)) return view.replaceChildren(emptyState('No roster was made for these dates', 'These dates have passed, so a roster can no longer be created for them.', 'calendar'));
    const wrap = element('div', null, 'create-roster');
    wrap.append(emptyState('No roster for these dates yet', 'Choose 1 week, 2 weeks or a calendar month and when it starts. Creating it freezes the current duty rules, then the generator fills every seat from enabled flights, crew working hours, leave and documents. You can change any seat afterwards.', 'calendar'));
    wrap.append(createRosterForm({ from: state.slot.start }));
    view.replaceChildren(wrap);
}

/** "New roster" dialog: the create form, starting after the latest running or upcoming roster. */
function openCreateDialog() {
    const dialog = document.querySelector('#create-dialog');
    dialog.querySelector('[data-create-body]').replaceChildren(createRosterForm({ from: nextFreeDate(), onDone: () => dialog.close() }));
    dialog.showModal();
}

/**
 * Create a roster of a length from a date, select it and build it straight away. Errors are thrown so the
 * form that asked can show them next to its fields.
 */
async function createPeriod(startsOn, length) {
    const { data } = await api('/api/v1/roster-periods', { method: 'POST', body: { starts_on: startsOn, length }, notify: false });
    await loadTimeline();
    state.slot = state.slots.find(slot => slot.period?.id === data.id) ?? state.slot;
    state.week = { ...data, built_at: null };
    await build();
}

/* ---------------------------------------------------------------- Views */

/**
 * Draw the active view and keep the crew grid where it was scrolled. Redraws happen after a seat change, a
 * filter or a build; without this the grid's own scroll box would jump back to the first row and day.
 */
function renderView() {
    const before = view.querySelector('.roster-grid-wrap');
    const scroll = before ? { top: before.scrollTop, left: before.scrollLeft } : null;
    drawView();
    const after = view.querySelector('.roster-grid-wrap');
    if (scroll && after) { after.scrollTop = scroll.top; after.scrollLeft = scroll.left; }
}

/** Draw the active view: crew grid, trips, conflicts or one person's roster (crew accounts see their own). */
function drawView() {
    view.setAttribute('aria-busy', 'false');
    const counter = document.querySelector('#conflict-tab-count');
    if (counter) counter.textContent = state.week.summary.conflicts ? `(${state.week.summary.blocking}/${state.week.summary.conflicts})` : '';
    // Keep the view switch in step with the view (it can change from a crew name or a ?crew= link).
    for (const button of document.querySelectorAll('#view-switch button[data-value]')) button.setAttribute('aria-pressed', String(button.dataset.value === state.view));
    if (!staff || state.view === 'individual') return view.replaceChildren(individualView());
    if (!state.week.trips.length && state.view !== 'grid') {
        return view.replaceChildren(emptyState('No trips in this roster', state.week.built_at ? 'No enabled flight operates on its remaining days. Check Flight routes, then rebuild.' : 'Build the roster to create its trips from the enabled flight routes.', 'calendar'));
    }
    if (state.view === 'trips') return view.replaceChildren(tripsView());
    if (state.view === 'conflicts') return view.replaceChildren(conflictsView());
    view.replaceChildren(gridView());
}

/** Crew rows that pass the position, search and "only with duties" filters. */
function visibleCrew() {
    const term = state.search.trim().toLowerCase();
    return state.week.crew.filter(crew => {
        const seats = state.index.seatsByCrew.get(crew.id) || [];
        if (state.rank && crew.rank !== state.rank) return false;
        if (state.dutiesOnly && !seats.length) return false;
        if (!term) return true;
        return crew.name.toLowerCase().includes(term) || seats.some(({ trip }) => trip.code.toLowerCase().includes(term));
    });
}

/**
 * Crew × day grid (like the uibuilder roster matrix) for every day of the roster: an "Open time" row for
 * unfilled seats, then one row per crew member with duties and activities per day, and their hours for the
 * period against their working hours. Staff click an empty day to plan leave, a day off, standby or SIM, an
 * activity to change it, and a name to open that person's individual roster.
 */
function gridView() {
    const days = slotDates().map(date => ({ date, name: WEEKDAYS[(parseDay(date).getUTCDay() + 6) % 7] }));
    const table = element('table', null, 'roster-grid'); table.dataset.days = String(days.length);
    const head = element('tr'); head.append(element('th', 'Crew member'));
    for (const day of days) {
        const th = element('th', null, 'roster-day'); th.scope = 'col';
        if (day.date === today) th.dataset.today = 'true';
        if (day.name === 'Mon') th.dataset.weekStart = 'true';
        th.append(element('span', day.name), element('strong', shortDate(day.date), 'mono'));
        head.append(th);
    }
    head.append(element('th', 'Hours'));
    const thead = element('thead'); thead.append(head);
    const body = element('tbody');

    // Open time: unfilled seats per day (filtered by position too). It collapses to a count per day so a long
    // list of open seats does not push the crew rows down; the choice is remembered (openTimeCollapsed).
    const openRow = element('tr', null, 'open-row'); openRow.dataset.collapsed = String(state.openCollapsed);
    const openHead = element('th', null, 'roster-crew'); openHead.scope = 'row';
    const toggle = element('button', null, 'open-toggle'); toggle.type = 'button';
    toggle.setAttribute('aria-expanded', String(!state.openCollapsed));
    toggle.title = 'Show or hide the open seats';
    // A labelled "Hide" / "Show" pill so the control is obvious, not just a small arrow.
    const action = element('span', state.openCollapsed ? 'Show' : 'Hide', 'open-toggle-action');
    toggle.append(icon('chevron-right', 'icon-sm open-toggle-icon'), element('strong', 'Open time'), action);
    toggle.addEventListener('click', () => {
        state.openCollapsed = !state.openCollapsed;
        openRow.dataset.collapsed = String(state.openCollapsed);
        toggle.setAttribute('aria-expanded', String(!state.openCollapsed));
        action.textContent = state.openCollapsed ? 'Show' : 'Hide';
        rememberOpenTime(state.openCollapsed);
    });
    openHead.append(toggle, element('span', 'Seats with no legal crew', 'small muted'));
    openRow.append(openHead);
    let openTotal = 0;
    for (const day of days) {
        const td = element('td', null, 'roster-cell');
        const seats = element('div', null, 'open-seats');
        for (const trip of state.week.trips.filter(item => item.start_date === day.date)) {
            for (const seat of trip.assignments.filter(item => !item.crew_member_id && (!state.rank || item.rank === state.rank))) {
                const button = element('button', null, 'open-seat'); button.type = 'button';
                button.dataset.tripId = trip.id;
                button.append(element('span', trip.code, 'mono'), element('span', seat.rank === 'CC' ? `CC${seat.seat_number}` : seat.rank));
                // Open seats on a disabled flight do not need filling: they go when the route changes are confirmed.
                // Disabled flight: red until the trip is removed; greyed out if it had already flown.
                if (!trip.flight_active && !trip.operated) { button.dataset.flight = 'disabled'; button.prepend(icon('ban', 'icon-sm')); button.title = `${trip.code} is disabled on Flight routes`; }
                if (!trip.flight_active && trip.operated) button.dataset.flight = 'flown';
                // An open captain section names its own route.
                if (seat.section && trip.flight_active) button.title = `Captain section ${seat.section.route}`;
                button.setAttribute('aria-label', `Open ${seatName(seat)} seat${seat.section ? ` (section ${seat.section.route})` : ''} on ${trip.code}, ${formatDate(trip.start_date)}${trip.flight_active ? '' : ', flight disabled'}`);
                button.addEventListener('click', () => (changeable(trip) ? openSeat(trip, seat) : selectTrip(trip.id)));
                // An open seat can be dragged onto a crew member's row to fill it.
                if (changeable(trip)) {
                    button.draggable = true;
                    button.addEventListener('dragstart', event => startDrag(event, trip, seat));
                    button.addEventListener('dragend', endDrag);
                }
                seats.append(button);
            }
        }
        openTotal += seats.childElementCount;
        // Collapsed view: just the number of open seats that day.
        td.append(seats, element('span', seats.childElementCount ? `${seats.childElementCount} open` : '', 'open-count mono'));
        openRow.append(td);
    }
    openRow.append(element('td', openTotal ? plural(openTotal, 'open seat') : 'None', 'roster-total small'));
    // Dropping a held seat on Open time clears it.
    if (staff) dropTarget(openRow, seat => seat.crew_member_id !== null, clearDraggedSeat);
    body.append(openRow);

    const crews = visibleCrew();
    for (const crew of crews) {
        const row = element('tr');
        const name = element('th', null, 'roster-crew'); name.scope = 'row';
        // The name opens the crew member's individual roster.
        const open = element('button', null, 'crew-plan-button'); open.type = 'button';
        open.append(element('strong', crew.name)); open.title = `Open ${crew.name}'s individual roster`;
        open.addEventListener('click', () => showIndividual(crew.id));
        name.append(open);
        const meta = element('span', `${crew.rank} · ${crew.base_airport}`, 'small muted mono'); name.append(meta);
        if (!crew.active) name.append(chip('Inactive', 'danger'));
        row.append(name);
        const seats = state.index.seatsByCrew.get(crew.id) || [];
        for (const day of days) {
            const td = element('td', null, 'roster-cell');
            for (const { trip, seat } of seats) {
                for (const duty of dutiesOn(trip, day.date, seat)) td.append(dutyButton(trip, seat, duty, day.date, { draggable: staff }));
                const away = layoverOn(trip, day.date, seat);
                if (away) td.append(layoverTag(trip, away));
            }
            for (const activity of crew.activities.filter(item => item.date === day.date)) td.append(activityTag(activity, crew));
            // An empty day: staff can plan leave, a day off, standby or SIM from here.
            if (staff && !td.childElementCount) td.append(planButton(crew, day.date));
            // Same-person drops transfer the seat to this date; other-person drops keep the existing row behavior.
            if (staff) dropTarget(td, seat => crew.active && seat.crew_member_id === crew.id && dragging?.trip.start_date !== day.date,
                dropped => moveSeatDate(dropped, day.date));
            row.append(td);
        }
        row.append(hoursCell(crew));
        // Drop a duty (or open seat) of the same position here to give it to this crew member.
        if (staff) {
            row.dataset.rank = crew.rank;
            dropTarget(row, seat => crew.active && seat.rank === crew.rank && seat.crew_member_id !== crew.id, dropped => moveSeat(dropped, crew));
        }
        body.append(row);
    }
    if (!crews.length) {
        const row = element('tr'); const td = element('td', null, 'roster-cell'); td.colSpan = days.length + 2;
        td.append(emptyState('No crew match this view', 'Change the position filter or search.', 'users')); row.append(td); body.append(row);
    }
    table.append(thead, body);
    const wrap = element('div', null, 'table-wrap roster-grid-wrap'); wrap.append(table);
    return wrap;
}

/** The (nearly invisible until hovered or focused) "plan this day" button in an empty grid cell. */
function planButton(crew, date) {
    const button = element('button', null, 'plan-cell'); button.type = 'button';
    button.append(icon('plus', 'icon-sm'));
    button.setAttribute('aria-label', `Plan leave, day off, standby or SIM for ${crew.name} on ${formatDate(date)}`);
    button.title = 'Plan leave, day off, standby or SIM';
    button.addEventListener('click', () => openPlan(crew, date));
    return button;
}

/**
 * A duty in the grid: flight code, local times, lock for manual seats and a conflict marker. A duty whose
 * flight was disabled on Flight routes is hatched with a "Flight disabled" line until a confirmed rebuild
 * removes it, so it is never mistaken for a flight that still operates. One that had already flown before the
 * flight was disabled is simply greyed out: it is history and nothing needs doing.
 *
 * In the crew grid ({ draggable: true }) a duty that can still change can be dragged onto another crew
 * member's row to give them the seat (see startDrag()).
 */
function dutyButton(trip, seat, duty, date, { draggable = false } = {}) {
    const conflicts = state.index.seatConflicts.get(seat.id) || [];
    const disabled = !trip.flight_active && !trip.operated;
    const button = element('button', null, `duty accent-${trip.palette || 'sky'}`); button.type = 'button';
    button.dataset.tripId = trip.id;
    if (trip.id === state.tripId) button.dataset.selected = 'true';
    if (conflicts.some(conflict => !conflict.acknowledged && conflict.severity === 'danger')) button.dataset.conflict = 'danger';
    else if (conflicts.length) button.dataset.conflict = 'accepted';
    if (seat.source === 'manual') button.dataset.manual = 'true';
    if (disabled) button.dataset.flight = 'disabled';
    // Flown before its flight was disabled: greyed out, no warning.
    if (!trip.flight_active && trip.operated) { button.dataset.flight = 'flown'; button.title = `${trip.code} flew before the flight was disabled.`; }
    const top = element('span', null, 'duty-top'); top.append(element('span', trip.code, 'duty-code'));
    if (seat.source === 'manual') top.append(icon('lock', 'icon-sm'));
    if (conflicts.length) top.append(icon('alert', 'icon-sm'));
    // Red warning badge in the top-right corner of the card for a disabled flight.
    if (disabled) { const badge = element('span', null, 'duty-flag'); badge.append(icon('alert', 'icon-sm')); top.append(badge); }
    const times = duty.date === date ? `${duty.report_local}–${duty.release_local}` : `→ ${duty.release_local}`;
    // A captain who flies only a section shows that section's route (marked with the section icon).
    const duties = seatDuties(trip, seat);
    const routeText = duties.length > 1 && duty.date === date && duty !== duties[0] ? 'Return' : seat.section?.route ?? trip.route ?? '';
    const route = disabled ? element('span', 'Flight disabled', 'duty-route duty-disabled') : element('span', routeText, 'duty-route');
    // Kept before the disabled-flight badge so that badge stays in the top-right corner.
    if (seat.section) { button.dataset.section = 'true'; top.insertBefore(icon('route', 'icon-sm'), top.querySelector('.duty-flag')); }
    button.append(top, element('span', times, 'duty-times mono'), route);
    if (disabled) button.title = `${trip.code} is disabled on Flight routes. Review the route changes and rebuild to remove this trip.`;
    button.setAttribute('aria-label', `${trip.code} ${formatDate(trip.start_date)}, ${seatName(seat)}${seat.section ? `, section ${seat.section.route}` : ''}, ${times} local${seat.source === 'manual' ? ', locked' : ''}${conflicts.length ? `, ${plural(conflicts.length, 'conflict')}` : ''}${disabled ? ', flight disabled' : ''}`);
    button.addEventListener('click', () => selectTrip(trip.id));
    if (draggable && changeable(trip)) {
        button.draggable = true;
        button.title = [button.title, 'Drag onto another crew member, or another day in your row to move to that occurrence of the same flight.'].filter(Boolean).join(' ');
        button.addEventListener('dragstart', event => startDrag(event, trip, seat));
        button.addEventListener('dragend', endDrag);
    }
    return button;
}

/* ---------------------------------------------------------------- Drag and drop in the crew grid */

// The seat being dragged ({ trip, seat }), or null.
let dragging = null;

/**
 * Start dragging a seat (a duty or an open seat). Rows of crew who could take it (same position, not already
 * holding it) light up as drop targets; dropping on the Open time row clears it. Keyboard users keep the
 * seat editor (Change / Fill), which does the same.
 */
function startDrag(event, trip, seat) {
    dragging = { trip, seat };
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(seat.id));
    view.querySelector('.roster-grid')?.setAttribute('data-dragging', seat.rank);
}

/** Finish a drag (dropped or cancelled): forget the seat and clear every highlight. */
function endDrag() {
    dragging = null;
    view.querySelector('.roster-grid')?.removeAttribute('data-dragging');
    for (const row of view.querySelectorAll('[data-drop]')) delete row.dataset.drop;
}

/**
 * Make a grid row a drop target. accepts(seat) says whether the dragged seat may be dropped here; onDrop runs
 * the change. The row is highlighted while a seat it accepts is over it.
 */
function dropTarget(row, accepts, onDrop) {
    row.addEventListener('dragover', event => {
        if (!dragging || !accepts(dragging.seat)) return;
        event.stopPropagation();
        event.preventDefault(); event.dataTransfer.dropEffect = 'move'; row.dataset.drop = 'ok';
    });
    row.addEventListener('dragleave', event => { if (!row.contains(event.relatedTarget)) delete row.dataset.drop; });
    row.addEventListener('drop', event => {
        if (!dragging || !accepts(dragging.seat)) return;
        event.stopPropagation();
        event.preventDefault();
        const dropped = dragging; endDrag();
        if (dropped) onDrop(dropped).catch(showError);
    });
}

/** Transfer this person's duty to an open matching seat on the chosen date, with server legality checks. */
async function moveSeatDate({ trip, seat }, date) {
    const targets = state.week.trips.filter(item => item.flight_id === trip.flight_id && item.start_date === date && changeable(item));
    const matches = targets.flatMap(target => target.assignments.filter(item => item.rank === seat.rank && !item.crew_member_id
        && item.from_leg === seat.from_leg && item.to_leg === seat.to_leg).map(targetSeat => ({ trip: target, seat: targetSeat })));
    if (!matches.length) {
        toast(`No open matching seat for ${trip.code} on ${formatDate(date)}. The flight must already operate that day, with the same captain section if split.`, { type: 'warning' });
        return;
    }
    const target = matches[0];
    const confirmed = await confirmAction({ title: 'Move duty to another day?',
        message: `${seat.crew?.name} moves from ${trip.code} on ${formatDate(trip.start_date)} to ${formatDate(date)}. The original seat will be open. The whole rotation or captain section moves together.`, confirmLabel: 'Move duty' });
    if (!confirmed) return;
    try {
        const response = await api(`/api/v1/assignments/${seat.id}`, { method: 'PUT',
            body: { crew_member_id: seat.crew_member_id, target_assignment_id: target.seat.id }, notify: false });
        toast(response.message, { type: 'success' });
        await Promise.all([loadTimeline(), loadWeek()]);
    } catch (error) { showError(error); }
}

/**
 * Give a dragged seat to a crew member. When the server's rule checks need an override reason, the seat editor
 * opens with that crew member selected so the reason can be recorded; other problems pop up as usual.
 */
async function moveSeat({ trip, seat }, crew) {
    try {
        const response = await api(`/api/v1/assignments/${seat.id}`, { method: 'PUT', body: { crew_member_id: crew.id }, notify: false });
        toast(response.message, { type: 'success' });
        await Promise.all([loadTimeline(), loadWeek()]);
    } catch (error) {
        if (error.errors?.override_reason) { await openSeat(trip, seat, crew.id); return; }
        showError(error);
    }
}

/** Clear a dragged seat by dropping it on the Open time row, after confirming. */
async function clearDraggedSeat({ trip, seat }) {
    const confirmed = await confirmAction({ title: `Clear ${seatName(seat)} seat?`, message: `${seat.crew?.name} is removed from ${trip.code} on ${formatDate(trip.start_date)}. The seat stays open until you fill it or rebuild.`, confirmLabel: 'Clear seat' });
    if (confirmed) await seatAction(`/api/v1/assignments/${seat.id}`, 'PUT', { crew_member_id: null });
}

/**
 * Leave, day off, SIM or standby in a grid cell (timed activities show local times). For staff it is a
 * button that opens day planning for that day, where the item can be removed or replaced.
 */
function activityTag(activity, crew = null) {
    const label = ACTIVITIES[activity.type];
    const tag = element(staff && crew ? 'button' : 'span', null, 'activity'); tag.dataset.kind = label.tone;
    if (staff && crew) { tag.type = 'button'; tag.addEventListener('click', () => openPlan(crew, activity.date)); }
    // Standby the generator planned (replaced on rebuild) is marked so it is not mistaken for a manual plan.
    if (activity.generated) tag.dataset.generated = 'true';
    tag.append(element('strong', activity.generated ? `${label.short} · auto` : label.short));
    if (activity.starts_at && activity.ends_at) tag.append(element('span', `${localClock(activity.starts_at, state.week.utc_offset_minutes)}–${localClock(activity.ends_at, state.week.utc_offset_minutes)}`, 'mono'));
    tag.title = [label.long, activity.generated ? 'planned by the generator to fill a workload gap' : null, activity.note].filter(Boolean).join(' — ');
    return tag;
}

/** Duty hours in the period against working hours for the period (weekly hours × weeks, or the 7-day limit). */
function hoursCell(crew) {
    const td = element('td', null, 'roster-total');
    const weeks = slotDates().length / 7;
    const capacity = Math.round((crew.weekly_hours ? crew.weekly_hours * 60 : (state.week.rules?.max_duty_7d_h ?? 60) * 60) * weeks);
    const value = element('strong', formatMinutes(crew.period_duty_minutes), 'mono');
    if (crew.period_duty_minutes > capacity) value.dataset.tone = 'danger';
    const bar = element('progress', null, 'coverage-bar hours-bar'); bar.max = capacity; bar.value = Math.min(crew.period_duty_minutes, capacity);
    bar.setAttribute('aria-label', `${formatMinutes(crew.period_duty_minutes)} of ${formatMinutes(capacity)} for the period`);
    td.append(value, element('span', crew.weekly_hours ? `of ${formatMinutes(capacity)}` : 'no hours set', 'small muted'), bar);
    return td;
}

/** Switch to the individual view for a crew member. */
function showIndividual(crewId) {
    state.crewId = crewId; state.view = 'individual';
    for (const button of document.querySelectorAll('#view-switch button[data-value]')) button.setAttribute('aria-pressed', String(button.dataset.value === 'individual'));
    const url = new URL(window.location.href); url.searchParams.set('crew', crewId); window.history.replaceState(null, '', url);
    renderView();
}

/**
 * Individual roster: one crew member's whole period day by day — duties (local and GMT times, seat, route),
 * leave, days off, SIM and standby — with totals, a printable PDF page and a calendar file. Staff pick the
 * crew member; empty days can be planned from here.
 */
function individualView() {
    const crews = state.week.crew;
    const crew = crews.find(item => item.id === state.crewId) ?? crews[0];
    const wrap = element('div', null, 'individual');
    if (!crew) return emptyState('No crew', 'Add crew members on the Crew page.', 'users');
    state.crewId = crew.id;
    const head = element('div', null, 'individual-head');
    const pick = element('label', 'Crew member', 'inline-field');
    const select = element('select'); select.name = 'crew';
    for (const item of crews) { const option = element('option', `${item.name} · ${item.rank} · ${item.base_airport}`); option.value = item.id; option.selected = item.id === crew.id; select.append(option); }
    select.addEventListener('change', () => showIndividual(Number(select.value)));
    pick.append(select);
    pick.hidden = !staff; // Crew accounts only ever see themselves.
    const seats = state.index.seatsByCrew.get(crew.id) || [];
    const block = seats.reduce((total, { trip }) => total + trip.block_minutes, 0);
    const totals = element('div', null, 'chip-list');
    totals.append(chip(plural(seats.length, 'duty', 'duties'), 'info'), chip(`Block ${formatMinutes(block)}`, undefined), chip(`Duty ${formatMinutes(crew.period_duty_minutes)}`, undefined),
        chip(`${plural(crew.activities.length, 'planned day')}`, undefined));
    const tools = element('div', null, 'row-actions');
    const pdf = element('button', 'PDF page', 'button button-sm button-secondary'); pdf.type = 'button';
    pdf.addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/roster.pdf?layout=crew&crew_member_ids[]=${crew.id}`, 'roster.pdf').catch(() => {})));
    const ics = element('button', 'Calendar file', 'button button-sm button-secondary'); ics.type = 'button';
    ics.addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/calendar.ics?crew_member_id=${crew.id}`, 'roster.ics').catch(() => {})));
    tools.append(pdf, ics);
    head.append(pick, totals, tools);
    wrap.append(head);

    const list = element('ol', null, 'individual-days');
    for (const date of slotDates()) {
        const item = element('li', null, 'individual-day'); if (date === today) item.dataset.today = 'true';
        item.append(element('span', `${WEEKDAYS[(parseDay(date).getUTCDay() + 6) % 7]} ${shortDate(date)}`, 'individual-date mono'));
        const entries = element('div', null, 'individual-entries');
        for (const { trip, seat } of seats) {
            for (const duty of dutiesOn(trip, date, seat)) {
                const entry = dutyButton(trip, seat, duty, date); entry.classList.add('individual-duty');
                const first = duty.legs[0]; const last = duty.legs[duty.legs.length - 1];
                entry.append(element('span', `${seatName(seat)} · ${trip.aircraft_type || ''} · GMT ${first ? utcClock(first.departs) : ''}–${last ? utcClock(last.arrives) : ''}`, 'duty-route'));
                entries.append(entry);
            }
            const away = layoverOn(trip, date, seat);
            if (away) entries.append(layoverTag(trip, away));
        }
        for (const activity of crew.activities.filter(activity => activity.date === date)) entries.append(activityTag(activity, crew));
        if (!entries.childElementCount) {
            entries.append(element('span', 'Off', 'muted small'));
            if (staff && state.week) entries.append(planButton(crew, date));
        }
        item.append(entries); list.append(item);
    }
    wrap.append(list);
    return wrap;
}

/** "HH:MM" GMT of an ISO instant. */
function utcClock(instant) { const date = new Date(instant); return `${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')}`; }

/**
 * Trips grouped by day, each as a flight strip with its seats. Crew accounts also see their own leave,
 * days off, SIM and standby on the day they fall.
 */
function tripsView() {
    const wrap = element('div', null, 'trip-days');
    const term = state.search.trim().toLowerCase();
    const own = staff ? null : state.week.crew[0];
    for (const date of slotDates()) {
        const index = (parseDay(date).getUTCDay() + 6) % 7;
        const trips = state.week.trips.filter(trip => trip.start_date === date && (!term || trip.code.toLowerCase().includes(term) || trip.assignments.some(seat => seat.crew?.name.toLowerCase().includes(term))));
        const activities = own ? own.activities.filter(activity => activity.date === date) : [];
        if (!trips.length && !activities.length) continue;
        const day = element('section', null, 'trip-day');
        const title = element('h3', `${WEEKDAYS[index]} ${formatDate(date)}`, 'trip-day-title mono'); if (date === today) title.append(chip('Today', 'brand'));
        const list = element('div', null, 'trip-strips');
        for (const trip of trips) list.append(tripCard(trip));
        for (const activity of activities) list.append(activityTag(activity));
        day.append(title, list); wrap.append(day);
    }
    if (!wrap.childElementCount) wrap.append(emptyState('No trips match this search', 'Clear the search to see every trip.', 'search'));
    return wrap;
}

/** A trip strip: code, route, local times, seat holders and open/conflict chips. */
function tripCard(trip) {
    const card = element('button', null, `strip trip-card accent-${trip.palette || 'sky'}`); card.type = 'button';
    card.dataset.tripId = trip.id;
    if (trip.id === state.tripId) card.dataset.selected = 'true';
    // Registration when a plane is assigned, otherwise the planned type.
    const head = element('span', null, 'trip-strip-head'); head.append(element('span', trip.code, 'code-tag'), element('span', trip.registration ? `${trip.registration} · ${trip.aircraft_type || ''}` : trip.aircraft_type || '', 'small muted mono'));
    card.append(head, element('span', `${trip.route || ''} · ${tripTimes(trip)}`, 'small mono'));
    const seats = element('span', null, 'trip-seats');
    for (const seat of trip.assignments.filter(item => !state.rank || item.rank === state.rank)) {
        const line = element('span', null, 'trip-seat'); if (!seat.crew) line.dataset.open = 'true';
        // Captains sharing a trip show their section after the name, e.g. "Open · LLW-NBO-LLW".
        line.append(element('span', seat.rank === 'CC' ? `CC${seat.seat_number}` : seat.rank, 'mono'), element('span', `${seat.crew?.name || 'Open'}${seat.section ? ` · ${seat.section.route}` : ''}`));
        if (seat.source === 'manual') line.append(icon('lock', 'icon-sm'));
        if ((state.index.seatConflicts.get(seat.id) || []).some(conflict => !conflict.acknowledged)) line.append(icon('alert', 'icon-sm'));
        seats.append(line);
    }
    card.append(seats);
    const chips = element('span', null, 'chip-list');
    const open = trip.assignments.filter(seat => !seat.crew_member_id).length;
    if (open) chips.append(chip(`${open} open`, 'warning'));
    if (trip.operated) chips.append(chip('Operated', undefined));
    if (!trip.flight_active) chips.append(trip.operated ? chip('Flown, flight since disabled', undefined) : chip('Flight disabled', 'danger'));
    const pending = routeChangeChip(trip); if (pending) chips.append(pending);
    for (const conflict of state.index.tripConflicts.get(trip.id) || []) if (conflict.code === 'no_aircraft') chips.append(chip('No airframe', 'danger'));
    if (chips.childElementCount) card.append(chips);
    card.addEventListener('click', () => selectTrip(trip.id));
    return card;
}

/** Every conflict in the week, rule breaks first, each with a way to fix it. */
function conflictsView() {
    const conflicts = [...state.week.conflicts].filter(conflict => conflict.code !== 'open_seat')
        .sort((a, b) => Number(b.blocking) - Number(a.blocking) || a.date.localeCompare(b.date) || a.flight_code.localeCompare(b.flight_code));
    if (!conflicts.length) return emptyState('No conflicts', 'Every filled seat passes the duty rules and no flight or fleet problems were found. Open seats are listed in the grid\'s Open time row.', 'shield');
    const list = element('div', null, 'conflict-list');
    for (const conflict of conflicts) {
        const trip = state.week.trips.find(item => item.id === conflict.trip_id);
        const seat = trip?.assignments.find(item => item.id === conflict.assignment_id);
        const action = element('button', null, 'button button-sm button-secondary'); action.type = 'button';
        if (seat && changeable(trip)) { action.append('Change seat'); action.addEventListener('click', () => { selectTrip(trip.id); openSeat(trip, seat); }); }
        else { action.append('View trip'); action.addEventListener('click', () => selectTrip(conflict.trip_id)); }
        list.append(conflictRow(conflict, action));
    }
    return list;
}

/* ---------------------------------------------------------------- Duty inspector */

/**
 * Select a trip for the inspector and highlight it in the current view. The highlight is moved in place
 * (elements carry data-trip-id) rather than redrawing the view, so the grid stays exactly where it was.
 */
function selectTrip(tripId) {
    state.tripId = tripId;
    const url = new URL(window.location.href); url.searchParams.set('trip', tripId); window.history.replaceState(null, '', url);
    for (const node of view.querySelectorAll('[data-selected]')) delete node.dataset.selected;
    for (const node of view.querySelectorAll(`[data-trip-id="${tripId}"]`)) node.dataset.selected = 'true';
    renderInspector();
    if (window.matchMedia('(max-width: 1100px)').matches) document.querySelector('.inspector').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** Trip details: times (local and UTC), legs, problems and every seat with the reason behind it. */
function renderInspector() {
    const trip = state.week?.trips.find(item => item.id === state.tripId);
    if (!trip) {
        const hint = staff ? 'Choose a duty in the grid or a trip to see its seats, times and why each crew member was chosen.' : 'Choose one of your duties to see its sectors and times.';
        return inspector.replaceChildren(emptyState('Select a duty', state.week ? hint : 'Pick a week with a roster to inspect its duties.', 'eye'));
    }
    const nodes = [];
    const head = element('div', null, `strip inspector-strip accent-${trip.palette || 'sky'}`);
    const title = element('div', null, 'trip-strip-head'); title.append(element('span', trip.code, 'code-tag'), chip(trip.registration ? `${trip.registration} · ${trip.aircraft_type || ''}` : trip.aircraft_type || 'Aircraft', 'brand'));
    head.append(title, element('span', `${formatDate(trip.start_date)} · ${trip.route || ''}`, 'small mono'));
    const chips = element('div', null, 'chip-list');
    if (trip.operated) chips.append(chip('Operated · read-only', undefined, { iconName: 'lock' }));
    if (!trip.flight_active) chips.append(trip.operated ? chip('Flown, flight since disabled', undefined) : chip('Flight disabled', 'danger'));
    const pending = routeChangeChip(trip); if (pending) chips.append(pending);
    if (chips.childElementCount) head.append(chips);
    nodes.push(head);

    const details = element('dl', null, 'detail-list');
    const add = (term, value) => details.append(element('dt', term), element('dd', value, 'mono'));
    add('Duty (LT)', tripTimes(trip) || '—');
    if (trip.report) add('Duty (UTC)', utcRange(trip.report, trip.release));
    add('Block', formatMinutes(trip.block_minutes));
    add('Duty time', formatMinutes(trip.duty_minutes));
    // The plane flying the trip ("7Q-TBA · Boeing 737-800 (B737)"), and whether crew control chose it.
    add('Aircraft', `${trip.aircraft_label || '—'}${staff && trip.aircraft_source === 'manual' ? ' · set by hand' : ''}`);
    nodes.push(details);
    if (changeable(trip)) nodes.push(planeTools(trip));

    const legs = element('ol', null, 'leg-list');
    for (const duty of trip.duties) {
        for (const leg of duty.legs) {
            const item = element('li'); item.append(element('span', `${leg.from_airport} → ${leg.to_airport}`, 'mono'), element('span', `${leg.departs_local}–${leg.arrives_local} LT${trip.duties.length > 1 ? ` · day ${duty.trip_day}` : ''}`, 'small muted mono'));
            legs.append(item);
        }
    }
    if (legs.childElementCount) nodes.push(element('h3', 'Sectors', 'label-caps muted'), legs);
    for (const warning of trip.warnings || []) nodes.push(chip(warning, 'warning', { iconName: 'alert' }));
    for (const conflict of state.index.tripConflicts.get(trip.id) || []) if (conflict.code !== 'open_seat') nodes.push(conflictRow(conflict));

    nodes.push(element('h3', staff ? 'Seats' : 'Your seat', 'label-caps muted'));
    const seats = element('div', null, 'seat-list');
    for (const seat of trip.assignments) seats.append(seatRow(trip, seat));
    nodes.push(seats);
    // Crew control's "never assign this person to this trip" decisions, which rebuilds respect.
    if (trip.exclusions?.length) {
        nodes.push(element('h3', 'Excluded from this trip', 'label-caps muted'));
        const list = element('div', null, 'exclusion-list');
        for (const exclusion of trip.exclusions) {
            const item = element('div', null, 'exclusion'); item.append(icon('ban', 'icon-sm'), element('span', exclusion.name || 'Crew member'));
            if (changeable(trip)) {
                const allow = element('button', 'Allow again', 'text-button'); allow.type = 'button';
                allow.addEventListener('click', event => busy(event.currentTarget, () => seatAction(`/api/v1/exclusions/${exclusion.id}`, 'DELETE')));
                item.append(allow);
            }
            list.append(item);
        }
        nodes.push(list);
    }
    inspector.replaceChildren(...nodes);
}

/** One seat: position, holder, lock/auto, conflicts, rationale and the Change button. */
function seatRow(trip, seat) {
    const row = element('div', null, 'seat-row'); if (!seat.crew) row.dataset.open = 'true';
    const top = element('div', null, 'seat-row-top');
    top.append(chip(seat.rank === 'CC' ? `CC ${seat.seat_number}` : seat.rank, seat.rank === 'CC' ? 'info' : 'brand'), element('strong', seat.crew?.name || 'Open seat', 'seat-name'));
    if (staff) top.append(seat.source === 'manual' ? chip('Locked', 'info', { iconName: 'lock' }) : chip('Auto', undefined));
    if (changeable(trip)) {
        const change = element('button', seat.crew ? 'Change' : 'Fill', 'button button-sm button-secondary'); change.type = 'button';
        change.addEventListener('click', () => openSeat(trip, seat));
        top.append(change);
    }
    row.append(top);
    // A captain who flies only part of the trip: which legs and their own report/release times.
    if (seat.section) {
        const periods = seat.section.duties;
        row.append(element('p', `Section ${seat.section.route} · ${periods[0]?.report_local}–${periods[periods.length - 1]?.release_local} LT · legs ${seat.section.from_leg}–${seat.section.to_leg} of ${trip.legs.length}`, 'seat-section small mono'));
    }
    if (seat.rank === 'CPT' && changeable(trip) && trip.legs?.length > 1) row.append(captainTools(trip, seat));
    // Seat tools: exclude the holder from this trip, or undo the last manual change.
    if (changeable(trip) && (seat.crew || seat.can_undo)) {
        const tools = element('div', null, 'seat-tools');
        if (seat.crew) {
            const exclude = element('button', 'Never on this trip', 'text-button'); exclude.type = 'button';
            exclude.addEventListener('click', () => excludeSeat(trip, seat));
            tools.append(exclude);
        }
        if (seat.can_undo) {
            const undo = element('button', 'Undo last change', 'text-button'); undo.type = 'button';
            undo.addEventListener('click', event => busy(event.currentTarget, () => seatAction(`/api/v1/assignments/${seat.id}/undo`, 'POST')));
            tools.append(undo);
        }
        row.append(tools);
    }
    for (const conflict of state.index.seatConflicts.get(seat.id) || []) {
        // Rule breaks are red; warnings (positioning, missing document records) keep the amber warning colour.
        const line = element('div', null, 'seat-issue'); if (!conflict.acknowledged && conflict.severity === 'danger') line.dataset.tone = 'danger';
        line.append(icon(conflict.acknowledged ? 'lock' : 'alert', 'icon-sm'), element('span', `${conflict.message}${conflict.acknowledged ? ' (override recorded)' : ''}`));
        row.append(line);
    }
    const why = rationale(seat);
    if (why) row.append(element('p', why, 'seat-why small muted'));
    return row;
}

/**
 * "Change plane" for a trip that can still change: loads the planes from the server (the flight plan's own
 * first, each with its status and any other trip it is already flying then), and saves crew control's choice,
 * or hands the choice back to the roster build. The trip then follows the plane's type; the roster reloads to
 * show added or removed cabin seats and any rating conflicts.
 */
function planeTools(trip) {
    const tools = element('div', null, 'plane-tools');
    const open = element('button', 'Change trip plane', 'button button-sm button-secondary'); open.type = 'button';
    tools.append(element('p', 'Applies to this dated trip only. To change preferred planes for the route, edit Flight routes.', 'small muted'));
    open.addEventListener('click', event => busy(event.currentTarget, async () => {
        let choices;
        try { choices = (await api(`/api/v1/trips/${trip.id}/aircraft-options`)).data; } catch (error) { showError(error); return; }
        const label = element('label', null, 'plane-pick'); label.append(element('span', 'Plane for this trip', 'small'));
        const select = element('select'); select.name = 'aircraft_id';
        const auto = element('option', 'Let the roster choose (from the flight plan\'s planes)'); auto.value = ''; select.append(auto);
        for (const choice of choices) {
            const notes = [choice.listed ? null : 'not on this flight plan', choice.status === 'available' ? null : choice.status_label.charAt(0).toLowerCase() + choice.status_label.slice(1), choice.busy_with ? `also on ${choice.busy_with}` : null].filter(Boolean);
            const option = element('option', `${choice.label}${notes.length ? ` — ${notes.join(', ')}` : ''}`); option.value = String(choice.id);
            option.selected = choice.current && trip.aircraft_source === 'manual';
            select.append(option);
        }
        label.append(select);
        const save = element('button', 'Save plane', 'button button-sm'); save.type = 'button';
        save.addEventListener('click', ev => busy(ev.currentTarget, () => seatAction(`/api/v1/trips/${trip.id}/aircraft`, 'PUT', { aircraft_id: select.value ? Number(select.value) : null })));
        tools.replaceChildren(label, save);
        select.focus();
    }));
    tools.append(open);
    return tools;
}

/**
 * Captain section tools for a captain seat: change captain after a chosen stop (any stop inside the seat's
 * section, base or outstation), and join the section with the next captain section. First officers and cabin
 * crew always fly the whole trip, so they never get these tools. The server checks every choice.
 */
function captainTools(trip, seat) {
    const tools = element('div', null, 'captain-tools');
    const from = seat.from_leg ?? 1; const to = seat.to_leg ?? trip.legs.length;
    if (to > from) {
        const label = element('label', null, 'captain-split');
        label.append(element('span', 'Change captain after', 'small'));
        const select = element('select'); select.name = 'after_leg';
        // Each stop inside the section: after leg n the aircraft is at that leg's destination.
        for (const leg of trip.legs.filter(item => item.number >= from && item.number < to)) {
            const option = element('option', `${leg.from_airport}→${leg.to_airport}, at ${leg.to_airport} ${leg.arrives_local} LT`); option.value = String(leg.number);
            select.append(option);
        }
        label.append(select);
        const split = element('button', 'Split', 'button button-sm button-secondary'); split.type = 'button';
        split.addEventListener('click', event => busy(event.currentTarget, () => seatAction(`/api/v1/assignments/${seat.id}/split`, 'POST', { after_leg: Number(select.value) })));
        tools.append(label, split);
    }
    const next = trip.assignments.find(item => item.rank === 'CPT' && item.seat_number === seat.seat_number + 1);
    if (next) {
        const join = element('button', 'Join with next section', 'text-button'); join.type = 'button';
        join.addEventListener('click', async () => {
            const holders = [seat.crew?.name, next.crew?.name].filter(Boolean);
            if (holders.length) {
                const confirmed = await confirmAction({ title: 'Join these captain sections?', message: `${holders.join(' and ')} ${holders.length === 1 ? 'is' : 'are'} released and the joined captain seat is open until you assign a captain or rebuild.`, confirmLabel: 'Join sections' });
                if (!confirmed) return;
            }
            await seatAction(`/api/v1/assignments/${seat.id}/join`, 'POST');
        });
        tools.append(join);
    }
    return tools;
}

/** Plain-language explanation from the seat's decision log (staff only). */
function rationale(seat) {
    const log = seat.decision_log;
    if (!staff || !log) return null;
    // The flight pattern's legs changed, so the generator joined the captain sections back into one seat.
    if (log.action === 'sections_reset') return log.reason;
    if (log.by === 'manual') {
        if (log.action === 'cleared') return `Cleared by ${log.user}; the next build can fill it.`;
        if (log.action === 'split') return `${log.reason} Set by ${log.user}; assign a captain or rebuild to fill this section.`;
        if (log.action === 'joined') return `Captain sections joined by ${log.user}${log.released?.length ? `, releasing ${log.released.join(' and ')}` : ''}; assign a captain or rebuild.`;
        if (log.positioning) return `Set by ${log.user}${log.reason ? ` — “${log.reason}”` : ''}. Kept on rebuild. ${log.positioning}`;
        if (log.action === 'excluded') return `Opened by ${log.user}, who excluded the previous crew member from this trip.`;
        const standby = log.cleared_standby?.length ? ` Standby on ${log.cleared_standby.map(shortDate).join(', ')} was cleared.` : '';
        return `Set by ${log.user}${log.reason ? ` — “${log.reason}”` : ''}. Kept on rebuild.${standby}`;
    }
    if (!seat.crew_member_id) {
        const reasons = Object.entries(log.reason_counts || {}).slice(0, 4).map(([code, count]) => `${count} ${REASONS[code] || code}`).join(', ');
        return `No legal crew among ${plural(log.considered, 'candidate')}${reasons ? `: ${reasons}` : ''}.`;
    }
    const alternatives = (log.alternatives || []).map(item => item.name).join(', ');
    if (log.rebalanced_from) return `Moved by the generator from ${log.rebalanced_from.name} to even out weekly working hours.`;
    return `Chosen by the generator from ${log.legal} legal of ${plural(log.considered, 'candidate')}: lowest fairness score ${log.score} (week ${formatMinutes(log.week_minutes)} of ${formatMinutes(log.capacity_minutes)}).${alternatives ? ` Next best: ${alternatives}.` : ''}`;
}

/** Run a seat action (undo, allow again, split, join) and reload the week; the server message pops up. */
async function seatAction(path, method, body = undefined) {
    try { await api(path, { method, body }); await Promise.all([loadTimeline(), loadWeek()]); }
    catch (error) { showError(error); }
}

/** Exclude the seat's holder from this trip after confirming; the seat opens for someone else. */
async function excludeSeat(trip, seat) {
    const confirmed = await confirmAction({ title: `Never put ${seat.crew.name} on ${trip.code}?`, message: `${seat.crew.name} is removed from ${trip.code} on ${formatDate(trip.start_date)} and will not be assigned to this trip again, by you or by a rebuild, until you allow it. You can undo this.`, confirmLabel: 'Exclude' });
    if (confirmed) await seatAction(`/api/v1/assignments/${seat.id}/exclude`, 'POST');
}

/* ---------------------------------------------------------------- Seat editor */

const dialog = document.querySelector('#seat-dialog');
const seatForm = document.querySelector('#seat-form');
const candidateList = document.querySelector('#candidate-list');
const overrideField = document.querySelector('#override-field');
const exchangeField = document.querySelector('#exchange-field');
const exchangeSelect = document.querySelector('#exchange-seat');
const seatStatus = dialog?.querySelector('[data-form-status]');
let editing = null;

/** Open the editor for a seat and load its candidates from the server. */
async function openSeat(trip, seat, preselectCrewId = null) {
    editing = { trip, seat, candidates: [], preselect: preselectCrewId };
    seatForm.reset(); status('', false, seatStatus); overrideField.hidden = true; exchangeField.hidden = true; exchangeSelect.replaceChildren();
    document.querySelector('#seat-dialog-title').textContent = `${trip.code} · ${formatDate(trip.start_date)} · ${seatName(seat)}`;
    document.querySelector('#seat-dialog-subtitle').textContent = `Currently ${seat.crew?.name || 'open'}. ${trip.route || ''} ${tripTimes(trip)}`;
    document.querySelector('#clear-seat').hidden = !seat.crew_member_id;
    candidateList.setAttribute('aria-busy', 'true'); candidateList.replaceChildren(element('p', 'Checking crew…', 'loading-block'));
    dialog.showModal();
    try {
        const { data } = await api(`/api/v1/assignments/${seat.id}/candidates`);
        editing.candidates = data; renderCandidates();
    } catch (error) { candidateList.replaceChildren(emptyState('Candidates could not be loaded', error.message || 'Close and try again.', 'alert')); showError(error); }
}

/** Candidate radio cards: legal first; others show why, and hard problems cannot be chosen. */
function renderCandidates() {
    candidateList.setAttribute('aria-busy', 'false');
    if (!editing.candidates.length) return candidateList.replaceChildren(emptyState('No active crew for this position', 'Add or activate crew on the Crew page.', 'users'));
    candidateList.replaceChildren(...editing.candidates.map(candidate => {
        const label = element('label', null, 'candidate'); label.dataset.legal = String(candidate.legal);
        const input = element('input'); input.type = 'radio'; input.name = 'crew_member_id'; input.value = candidate.crew_member_id;
        // A crew member chosen by dragging starts selected, so only the override reason is left to fill in.
        input.checked = editing.preselect ? candidate.crew_member_id === editing.preselect : candidate.current;
        input.disabled = !candidate.overridable && !candidate.exchanges?.length;
        const body = element('span', null, 'candidate-body');
        const top = element('span', null, 'candidate-top');
        top.append(element('strong', candidate.name), element('span', candidate.base_airport, 'chip mono'));
        if (candidate.current) top.append(chip('Current', 'brand'));
        top.append(candidate.legal ? chip('Legal', 'success', { iconName: 'check' }) : chip(candidate.overridable ? plural(candidate.issues.length, 'problem') : 'Not allowed', 'danger'));
        if (candidate.exchanges?.length) top.append(chip('Exchange available', 'info'));
        const hours = `Week ${formatMinutes(candidate.week_minutes)} of ${candidate.weekly_hours ? `${candidate.weekly_hours}h working hours` : `${formatMinutes(candidate.capacity_minutes)} limit`}${candidate.score !== null ? ` · score ${candidate.score}` : ''}`;
        body.append(top, element('span', hours, 'small muted mono'));
        if (candidate.issues.length) {
            const issues = element('ul', null, 'candidate-issues');
            for (const issue of candidate.issues) issues.append(element('li', issue.message));
            body.append(issues);
        }
        // Positioning for a captain section: allowed without a reason, crew control arranges the travel.
        for (const warning of candidate.warnings || []) body.append(chip(warning.message, 'warning', { iconName: 'alert' }));
        label.append(input, body);
        return label;
    }));
    updateExchange();
}

/** The checked candidate id (a lone radio is not a RadioNodeList, so query the checked input directly). */
function checkedCrew() { return seatForm.querySelector('input[name="crew_member_id"]:checked')?.value ?? null; }
/** Present the server-checked exchange choices for the selected candidate without choosing a swap implicitly. */
function updateExchange() {
    const chosen = editing?.candidates.find(candidate => String(candidate.crew_member_id) === checkedCrew());
    const direct = element('option', 'Assign only — keep their other duties'); direct.value = '';
    exchangeSelect.replaceChildren(direct);
    for (const choice of chosen?.exchanges || []) {
        const option = element('option', `Exchange with ${choice.label}`); option.value = choice.assignment_id; exchangeSelect.append(option);
    }
    exchangeField.hidden = !chosen?.exchanges?.length;
    updateOverride();
}

/** Exchanges must be legal on both sides; the normal assignment retains its explicit override field. */
function updateOverride() {
    const chosen = editing?.candidates.find(candidate => String(candidate.crew_member_id) === checkedCrew());
    overrideField.hidden = !chosen || chosen.legal || Boolean(exchangeSelect.value);
    seatForm.elements.override_reason.required = !overrideField.hidden;
    seatForm.querySelector('[type="submit"]').textContent = exchangeSelect.value ? 'Exchange crew' : 'Assign';
}

/** Save a seat (or clear it with null), then reload the week and keep the trip selected. */
async function saveSeat(crewId) {
    const reason = seatForm.elements.override_reason.value.trim();
    const exchangeId = crewId !== null ? Number(exchangeSelect.value) || null : null;
    if (exchangeId) {
        const candidate = editing.candidates.find(item => item.crew_member_id === crewId);
        const choice = candidate?.exchanges?.find(item => item.assignment_id === exchangeId);
        const confirmed = await confirmAction({ title: 'Exchange crew duties?',
            message: `${candidate?.name} takes ${editing.trip.code} on ${formatDate(editing.trip.start_date)}. ${editing.seat.crew?.name} takes ${choice?.label}. Both duties are checked again before saving.`, confirmLabel: 'Exchange crew' });
        if (!confirmed) return;
    }
    try {
        const body = exchangeId
            ? { crew_member_id: crewId, exchange_assignment_id: exchangeId, expected_crew_member_id: editing.seat.crew_member_id }
            : { crew_member_id: crewId, override_reason: reason || null };
        await api(`/api/v1/assignments/${editing.seat.id}`, { method: 'PUT', body });
        dialog.close();
        await Promise.all([loadTimeline(), loadWeek()]);
    } catch (error) {
        showError(error, seatStatus);
        if (error.errors?.override_reason) { overrideField.hidden = false; seatForm.elements.override_reason.focus(); }
    }
}

if (dialog) {
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    candidateList.addEventListener('change', updateExchange);
    exchangeSelect.addEventListener('change', updateOverride);
    seatForm.addEventListener('submit', async event => {
        event.preventDefault();
        const value = checkedCrew();
        if (!value) return status('Choose a crew member, or use Clear seat.', true, seatStatus);
        if (!overrideField.hidden && !seatForm.elements.override_reason.value.trim()) {
            seatForm.elements.override_reason.setAttribute('aria-invalid', 'true');
            return status('Give a reason for overriding the rule checks shown for this crew member.', true, seatStatus);
        }
        seatForm.elements.override_reason.removeAttribute('aria-invalid');
        await busy(seatForm.querySelector('[type="submit"]'), () => saveSeat(Number(value)));
    });
    document.querySelector('#clear-seat').addEventListener('click', async event => {
        const confirmed = await confirmAction({ title: `Clear ${seatName(editing.seat)} seat?`, message: `${editing.seat.crew?.name} is removed from ${editing.trip.code} on ${formatDate(editing.trip.start_date)}. The seat stays open until you fill it or rebuild the week.`, confirmLabel: 'Clear seat' });
        if (confirmed) await busy(event.currentTarget, () => saveSeat(null));
    });
}

/* ---------------------------------------------------------------- Day planning */

const planDialog = document.querySelector('#plan-dialog');
const planForm = document.querySelector('#plan-form');
const planStatus = planDialog?.querySelector('[data-form-status]');
let planning = null;

/** Open day planning for a crew member, defaulting the dates to the selected week (or today onwards). */
function openPlan(crew, date = null) {
    planning = crew;
    planForm.reset(); status('', false, planStatus);
    document.querySelector('#plan-dialog-title').textContent = `Day planning · ${crew.name}`;
    document.querySelector('#plan-dialog-subtitle').textContent = `${RANKS[crew.rank]} · ${crew.base_airport}${crew.weekly_hours ? ` · ${crew.weekly_hours}h working hours a week` : ''}. Times are base local.`;
    const first = date ?? (state.slot.start < today && state.slot.end >= today ? today : state.slot.start);
    planForm.elements.date_from.value = first; planForm.elements.date_to.value = first;
    renderPlanTimes(); renderPlanExisting();
    planDialog.showModal();
}

/** Times only apply to SIM and standby. */
function renderPlanTimes() {
    const timed = ['sim', 'standby'].includes(planForm.elements.type.value);
    for (const field of planForm.querySelectorAll('[data-timed]')) field.hidden = !timed;
    if (!timed) { planForm.elements.starts_local.value = ''; planForm.elements.ends_local.value = ''; }
}

/** The crew member's items in the selected week, each with a remove button. */
function renderPlanExisting() {
    const box = document.querySelector('#plan-existing');
    const crew = state.week?.crew.find(item => item.id === planning.id);
    const items = crew?.activities ?? [];
    if (!items.length) return box.replaceChildren(element('p', 'Nothing planned for this crew member this week.', 'small muted'));
    box.replaceChildren(element('h3', 'This week', 'label-caps muted'), ...items.map(activity => {
        const row = element('div', null, 'plan-item');
        row.append(activityTag(activity), element('span', `${WEEKDAYS[(new Date(activity.date + 'T00:00:00Z').getUTCDay() + 6) % 7]} ${formatDate(activity.date)}${activity.note ? ` · ${activity.note}` : ''}`, 'small'));
        const remove = iconButton('trash', `Remove ${ACTIVITIES[activity.type].long} on ${formatDate(activity.date)}`, async event => {
            await busy(event.currentTarget, async () => {
                try { await api(`/api/v1/crew-activities/${activity.id}`, { method: 'DELETE' }); await loadWeek(); renderPlanExisting(); }
                catch (error) { showError(error, planStatus); }
            });
        }, 'danger');
        row.append(remove);
        return row;
    }));
}

if (planDialog) {
    planDialog.querySelector('[data-close]').addEventListener('click', () => planDialog.close());
    planForm.elements.type.addEventListener('change', renderPlanTimes);
    planForm.addEventListener('submit', async event => {
        event.preventDefault();
        const body = { crew_member_id: planning.id, type: planForm.elements.type.value, date_from: planForm.elements.date_from.value, date_to: planForm.elements.date_to.value,
            starts_local: normalizeClock(planForm.elements.starts_local.value) || null, ends_local: normalizeClock(planForm.elements.ends_local.value) || null, note: planForm.elements.note.value || null };
        await busy(planForm.querySelector('[type="submit"]'), async () => {
            try {
                await api('/api/v1/crew-activities', { method: 'POST', body });
                status('', false, planStatus);
                if (state.week) { await loadWeek(); renderPlanExisting(); }
            } catch (error) { showError(error, planStatus); }
        });
    });
    // One crew member's printable page for the selected week.
    document.querySelector('#plan-pdf').addEventListener('click', event => {
        if (!state.week) return status('Create the week first; PDF pages cover one roster week.', true, planStatus);
        busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/roster.pdf?layout=crew&crew_member_ids[]=${planning.id}`, 'roster.pdf').catch(() => {}));
    });
    // Staff can download any crew member's calendar file for the selected week.
    document.querySelector('#plan-calendar').addEventListener('click', event => {
        if (!state.week) return status('Create the week first; calendar files cover one roster week.', true, planStatus);
        busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/calendar.ics?crew_member_id=${planning.id}`, 'roster.ics').catch(() => {}));
    });
}

/* ---------------------------------------------------------------- Exports and email */

document.querySelector('#download-csv').addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/export.csv`, 'roster.csv').catch(() => {})));
// PDF: crew get their own page; staff get the full grid, or one page per crew member with duties this week.
document.querySelector('#download-pdf').addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/roster.pdf`, 'roster.pdf').catch(() => {})));
document.querySelector('#download-crew-pdf')?.addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/roster.pdf?layout=crew`, 'roster-crew.pdf').catch(() => {})));
document.querySelector('#download-calendar')?.addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/roster-periods/${state.week.id}/calendar.ics`, 'roster.ics').catch(() => {})));
// Printing uses the print stylesheet (roster.css): the selected view only, without navigation or tools.
document.querySelector('#print-week').addEventListener('click', () => window.print());

const emailDialog = document.querySelector('#email-dialog');
/** Fill the email status table for the selected week. */
async function loadEmailLog() {
    const rows = document.querySelector('#email-rows');
    rows.replaceChildren(element('tr', null, 'loading-row'));
    const { data } = await api(`/api/v1/roster-periods/${state.week.id}/email-logs`);
    if (!data.length) {
        const row = element('tr'); const td = element('td', 'No roster emails have been sent for this week.', 'muted'); td.colSpan = 4; row.append(td);
        return rows.replaceChildren(row);
    }
    const tones = { queued: 'info', sent: 'success', failed: 'danger' };
    rows.replaceChildren(...data.map(log => {
        const row = element('tr');
        const status = element('td'); status.append(chip(log.status === 'queued' ? 'Waiting to send' : log.status === 'sent' ? 'Sent' : 'Failed', tones[log.status]));
        if (log.error) status.append(element('div', log.error, 'small muted'));
        row.append(element('td', log.name), element('td', log.email, 'small mono'), status, element('td', log.sent_at ? formatInstant(log.sent_at) : '—', 'small mono'));
        return row;
    }));
}
/**
 * Email only the crew whose duties changed since they were last told (after the published roster was edited, or
 * published again after flights were disabled or edited). Lists who and what changed, asks first, then the
 * server sends; its message pops up. Emailed crew then count as up to date.
 */
async function emailChangedCrew() {
    const changed = state.week?.changed_crew || [];
    if (!changed.length) return;
    const names = changed.map(item => item.name);
    const confirmed = await confirmAction({ title: `Email ${plural(changed.length, 'crew member')} about the changes?`,
        message: `${names.slice(0, 8).join(', ')}${names.length > 8 ? ` and ${names.length - 8} more` : ''} get their updated roster by email, with a list of what changed since they were last told.`, confirmLabel: 'Send emails', tone: 'info' });
    if (!confirmed) return;
    try { await api(`/api/v1/roster-periods/${state.week.id}/email`, { method: 'POST', body: { only: 'changed' } }); }
    catch (error) { showError(error); }
}

if (staff) {
    buttons.email.addEventListener('click', event => busy(event.currentTarget, async () => {
        const confirmed = await confirmAction({ title: `Email ${state.week.label} to crew?`, message: 'Each crew member with a seat in this roster is emailed their roster (with the crew they fly with) and a calendar file at the address on their crew profile. Sending can take a minute for a large roster.', confirmLabel: 'Send emails', tone: 'info' });
        if (!confirmed) return;
        try { await api(`/api/v1/roster-periods/${state.week.id}/email`, { method: 'POST' }); }
        catch (error) { showError(error); }
    }));
    document.querySelector('#email-log').addEventListener('click', () => { emailDialog.showModal(); loadEmailLog().catch(showError); });
    document.querySelector('#email-refresh').addEventListener('click', event => busy(event.currentTarget, () => loadEmailLog().catch(showError)));
}

/* ---------------------------------------------------------------- My hours (crew) */

/** Crew accounts: accumulated block and duty hours per window from GET /api/v1/my-hours. */
async function loadMyHours() {
    const box = document.querySelector('#my-hours'); if (!box) return;
    const { data } = await api('/api/v1/my-hours');
    box.setAttribute('aria-busy', 'false');
    const pilot = data.crew.rank !== 'CC';
    box.replaceChildren(...data.windows.map(window => {
        const hours = data.hours[window.key];
        const card = element('div', null, 'my-hours-card');
        const primary = pilot ? hours.block_minutes : hours.duty_minutes;
        const scheduled = pilot ? hours.scheduled_block_minutes : hours.scheduled_duty_minutes;
        card.append(element('span', window.label, 'label-caps muted'), element('strong', formatMinutes(primary), 'mono'),
            element('span', `${pilot ? 'block' : 'duty'} flown · ${pilot ? 'duty' : 'block'} ${formatMinutes(pilot ? hours.duty_minutes : hours.block_minutes)}`, 'small muted'),
            element('span', scheduled ? `+${formatMinutes(scheduled)} scheduled · ${plural(hours.trips, 'trip')}` : plural(hours.trips, 'trip'), 'small mono'));
        return card;
    }));
}

/* ---------------------------------------------------------------- Week actions */

/**
 * Run the generator. A first build runs straight away. A rebuild asks for confirmation, or, when flight routes
 * changed since the last build, opens the route-changes review instead; its confirm button calls back here with
 * the reviewed set's fingerprint so the server applies exactly what was shown. The server message reports
 * filled and open seats; skipped patterns, applied route changes and balancing are listed as pop-ups.
 *
 * @param {string|null} routeChanges fingerprint of the route changes the scheduler confirmed
 */
async function build(routeChanges = null) {
    const week = state.week;
    if (week.built_at && !routeChanges) {
        if (week.route_changes?.count) { openChanges(); return; }
        const confirmed = await confirmAction({ title: `Rebuild ${week.label}?`, message: 'Automatic seats from today onwards are planned again from current crew data. Locked (manually set) seats and trips that have already operated stay as they are.', confirmLabel: 'Rebuild', tone: 'info' });
        if (!confirmed) return;
    }
    let data;
    try {
        ({ data } = await api(`/api/v1/roster-periods/${week.id}/build`, { method: 'POST', body: routeChanges ? { route_changes: routeChanges } : {} }));
    } catch (error) {
        // Routes changed again after the review (the server's message has popped up): show the new list.
        if (!error.errors?.route_changes) throw error;
        await loadWeek();
        if (state.week?.route_changes?.count) openChanges();
        return;
    }
    applyWeek(data);
    for (const skipped of data.summary.build?.skipped || []) toast(skipped, { type: 'warning', title: 'Flight skipped' });
    const applied = data.summary.build?.route_changes;
    if (applied) toast(`${routeChangeSummary(applied, { done: true })}${applied.released_seats ? `; ${plural(applied.released_seats, 'crew seat')} released` : ''}.`, { type: 'info', title: 'Route changes applied' });
    const extra = [data.summary.build?.rebalanced ? `${plural(data.summary.build.rebalanced, 'seat')} moved to even out working hours` : null, data.summary.build?.standby ? `${plural(data.summary.build.standby, 'standby day')} planned to fill workload gaps` : null].filter(Boolean);
    if (extra.length) toast(`${extra.join('; ')}.`, { type: 'info', title: 'Balancing' });
}

/* ---------------------------------------------------------------- Route changes */

// Review of what a rebuild would change after flights were disabled, enabled or edited (staff only).
const changesDialog = document.querySelector('#changes-dialog');

/** "Mon 05 Oct" for a base-local calendar date, without the browser timezone. */
function dayLabel(date) { return `${WEEKDAYS[(parseDay(date).getUTCDay() + 6) % 7]} ${shortDate(date)}`; }

/**
 * "2 trips to remove, 1 trip to add" (pending) or "2 trips removed, 1 trip added" (applied). Accepts the
 * window's route_changes (lists) or a build summary's route_changes (counts).
 */
function routeChangeSummary(changes, { done = false } = {}) {
    const count = value => (Array.isArray(value) ? value.length : value || 0);
    return [
        count(changes.removed) && `${plural(count(changes.removed), 'trip')} ${done ? 'removed' : 'to remove'}`,
        count(changes.added) && `${plural(count(changes.added), 'trip')} ${done ? 'added' : 'to add'}`,
        count(changes.changed) && `${plural(count(changes.changed), 'trip')} ${done ? 'updated' : 'with a new schedule'}`,
    ].filter(Boolean).join(', ');
}

/** Chip for a trip card or the inspector when the next rebuild will remove or reschedule the trip. */
function routeChangeChip(trip) {
    const pending = staff ? state.index?.routeChanges.get(trip.id) : null;
    if (pending === 'removed') return chip('Removed on rebuild', 'danger', { iconName: 'route' });
    if (pending === 'changed') return chip('New schedule on rebuild', 'warning', { iconName: 'route' });
    return null;
}

/** Group change rows that share a key (flight and reason) so each flight reads as one line with its dates. */
function groupBy(rows, keyOf) {
    const groups = new Map();
    for (const row of rows) groups.set(keyOf(row), [...(groups.get(keyOf(row)) || []), row]);
    return [...groups.values()];
}

/** One section of the review: a titled list of rows. */
function changeSection(title, rows) {
    const section = element('section', null, 'change-section');
    const list = element('ul', null, 'change-list'); list.append(...rows);
    section.append(element('h3', title, 'change-section-title label-caps'), list);
    return section;
}

/** One row of the review: flight number, a text chip for the kind of change, then detail lines. */
function changeRow(kind, code, label, tone, lines) {
    const row = element('li', null, 'change-row'); row.dataset.kind = kind;
    const head = element('div', null, 'change-row-head'); head.append(element('span', code, 'code-tag'), chip(label, tone));
    row.append(head, ...lines);
    return row;
}

/**
 * Open the route-changes review for the selected roster: trips to remove (with everyone released, locked seats
 * marked), trips to add and trips whose schedule changed, plus patterns the roster's rules reject. A draft can
 * be rebuilt from here with exactly this set; a published roster explains it must be reopened first.
 */
function openChanges() {
    const week = state.week; const changes = week?.route_changes;
    if (!changesDialog || !changes) return;
    const draft = week.editable;
    document.querySelector('#changes-dialog-subtitle').textContent = `${week.label}: ${routeChangeSummary(changes) || 'nothing to change'}. `
        + (draft ? 'Nothing changes until you confirm. The rebuild then plans the open seats again; other locked seats and trips that have operated are kept.' : 'This roster is published: reopen it as a draft to apply these changes.');
    const sections = [];
    if (changes.locked_seats) {
        const alert = element('div', null, 'alert-row'); alert.dataset.tone = 'danger';
        const text = element('div', null, 'alert-row-text');
        text.append(element('span', `${plural(changes.locked_seats, 'locked seat')} will be released`, 'alert-row-title'), element('span', 'These crew were placed by hand on trips that are being removed. They lose that duty when you confirm.', 'alert-row-meta'));
        alert.append(icon('lock', 'alert-row-icon'), text);
        sections.push(alert);
    }
    if (changes.removed.length) {
        sections.push(changeSection('Trips to remove', groupBy(changes.removed, trip => `${trip.code}|${trip.reason}`).map(trips => {
            const lines = [element('span', `${trips.map(trip => dayLabel(trip.date)).join(', ')}${trips[0].route ? ` · ${trips[0].route}` : ''}`, 'small mono')];
            for (const trip of trips.filter(item => item.seats.length)) {
                const released = element('span', null, 'change-released'); released.append(element('span', `${dayLabel(trip.date)} releases:`));
                for (const seat of trip.seats) released.append(chip(`${seatName(seat)} ${seat.name}${seat.section ? ` (${seat.section})` : ''}${seat.locked ? ' (locked)' : ''}`, seat.locked ? 'warning' : undefined, seat.locked ? { iconName: 'lock' } : {}));
                lines.push(released);
            }
            return changeRow('removed', trips[0].code, trips[0].reason_label, 'danger', lines);
        })));
    }
    if (changes.added.length) {
        sections.push(changeSection('Trips to add', groupBy(changes.added, trip => trip.code).map(trips => changeRow('added', trips[0].code, 'New trips', 'success',
            [element('span', `${trips.map(trip => dayLabel(trip.date)).join(', ')} · ${trips[0].route}`, 'small mono')]))));
    }
    if (changes.changed.length) {
        sections.push(changeSection('New schedule', changes.changed.map(trip => changeRow('changed', trip.code, 'Schedule changed', 'warning',
            [element('span', `${dayLabel(trip.date)} · ${trip.route}`, 'small mono'), ...trip.details.map(detail => element('span', detail, 'small'))]))));
    }
    if (changes.skipped.length) {
        sections.push(changeSection('Not planned (fails this roster\'s duty rules)', changes.skipped.map(message => element('li', message, 'change-row small'))));
    }
    if (!sections.length) sections.push(emptyState('No route changes', 'This roster already matches Flight routes.', 'route'));
    document.querySelector('#changes-body').replaceChildren(...sections);
    document.querySelector('#changes-confirm').hidden = !draft || !changes.count;
    changesDialog.showModal();
}

if (staff) {
    // Confirming the review rebuilds with exactly the reviewed set (the server refuses a stale one).
    document.querySelector('#changes-confirm').addEventListener('click', event => busy(event.currentTarget, async () => {
        const fingerprint = state.week?.route_changes?.fingerprint;
        changesDialog.close();
        await build(fingerprint).catch(showError);
    }));
    // "New roster": 1 week, 2 weeks or a calendar month, created and built from the dialog.
    document.querySelector('#new-roster').addEventListener('click', openCreateDialog);
    document.querySelector('#create-dialog [data-close]').addEventListener('click', () => document.querySelector('#create-dialog').close());
    buttons.build.addEventListener('click', event => busy(event.currentTarget, () => build().catch(showError)));
    buttons.publish.addEventListener('click', event => busy(event.currentTarget, async () => {
        const confirmed = await confirmAction({ title: `Publish ${state.week.label}?`, message: `Crew will see their duties for ${state.week.label}.${state.week.summary.open ? ` ${plural(state.week.summary.open, 'seat')} will show as open time.` : ''} To change it later, reopen it as a draft.`, confirmLabel: 'Publish', tone: 'info' });
        if (!confirmed) return;
        try { applyWeek((await api(`/api/v1/roster-periods/${state.week.id}/publish`, { method: 'POST' })).data); } catch (error) { showError(error); return; }
        // Published again with changes: offer to tell the crew whose duties changed.
        if (state.week.changed_crew?.length) await emailChangedCrew();
    }));
    buttons.reopen.addEventListener('click', event => busy(event.currentTarget, async () => {
        const confirmed = await confirmAction({ title: `Reopen ${state.week.label} as a draft?`, message: 'Crew stop seeing this week until you publish it again.', confirmLabel: 'Reopen', tone: 'info' });
        if (!confirmed) return;
        try { applyWeek((await api(`/api/v1/roster-periods/${state.week.id}/reopen`, { method: 'POST' })).data); } catch (error) { showError(error); }
    }));
    segmented(document.querySelector('#view-switch'), value => { state.view = value; if (state.week) renderView(); });
    segmented(document.querySelector('#rank-filter'), value => { state.rank = value; if (state.week) renderView(); });
    document.querySelector('#crew-search').addEventListener('input', event => { state.search = event.target.value; if (state.week) renderView(); });
    document.querySelector('#duties-only').addEventListener('change', event => { state.dutiesOnly = event.target.checked; if (state.week) renderView(); });
}

// Timeline navigation: shift the strip by four weeks, or jump back to this week.
document.querySelector('#timeline-earlier').addEventListener('click', () => { state.windowStart = addDays(state.windowStart, -28); loadTimeline().catch(showError); });
document.querySelector('#timeline-later').addEventListener('click', () => { state.windowStart = addDays(state.windowStart, 28); loadTimeline().catch(showError); });
document.querySelector('#timeline-today').addEventListener('click', () => selectSlot(today).catch(showError));

// Initial load: the timeline first (it tells us which roster covers the requested date), then that roster.
(async () => {
    await loadTimeline();
    await selectSlot(requested, { tripId: Number(query.get('trip')) || null });
    if (!staff) await loadMyHours().catch(error => { document.querySelector('#my-hours')?.replaceChildren(emptyState('Hours could not be loaded', error.message || 'Reload the page to try again.', 'alert')); });
})().catch(error => {
    view.setAttribute('aria-busy', 'false'); strip.setAttribute('aria-busy', 'false');
    view.replaceChildren(emptyState('Roster could not be loaded', error.message || 'Reload the page to try again.', 'alert'));
    showError(error);
});
