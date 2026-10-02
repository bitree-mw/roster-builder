/**
 * Weekly roster window (pages/roster.blade.php).
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
import { ACTIVITIES, RANKS, WEEKDAYS, addDays, conflictRow, isoWeek, mondayOf, shortDate, weekRange, weekState } from '../common/roster';
import { busy, chip, element, emptyState, formatDate, formatInstant, formatMinutes, icon, iconButton, plural, segmented, setKpi, setKpiTone, showError, status, toast } from '../common/ui';

const staff = ['admin', 'scheduler', 'crew_control'].includes(document.body.dataset.role);
const today = document.querySelector('#roster-root').dataset.today;
const thisMonday = mondayOf(today);
const STRIP_WEEKS = 9;
// Plain-language labels for the generator's rejection codes (shown for open seats).
const REASONS = {
    inactive: 'inactive', rank: 'other position', base: 'other base', rating: 'not rated', document_missing: 'document missing',
    document_expired: 'document expires', excluded: 'excluded', already_on_trip: 'already on this trip', unavailable: 'leave, day off, SIM or standby',
    overlap: 'already on duty', rest: 'not enough rest', duty_7d: '7-day duty limit', block_month: 'monthly block limit',
    consecutive_days: 'consecutive duty days', days_off_month: 'monthly days off', weekly_hours: 'weekly working hours',
};

// Page state. "week" is the full roster window for the selected week (null when the week does not exist).
const query = new URLSearchParams(window.location.search);
const requestedWeek = /^\d{4}-\d{2}-\d{2}$/.test(query.get('week') || '') ? mondayOf(query.get('week')) : thisMonday;
const state = {
    selected: requestedWeek,
    anchor: addDays(requestedWeek, -7 * 3),
    periods: new Map(),
    week: null,
    view: 'grid',
    rank: '',
    search: '',
    dutiesOnly: false,
    tripId: Number(query.get('trip')) || null,
    index: null,
};

const view = document.querySelector('#roster-view');
const strip = document.querySelector('#week-strip');
const inspector = document.querySelector('#inspector');
const notice = document.querySelector('#week-notice');
const buttons = {
    create: document.querySelector('#create-week'),
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
/** Whether the week has ended (its Sunday is before today at base). */
function ended(monday) { return addDays(monday, 6) < today; }
/** Whether seats on a trip can still be changed by the scheduler. */
function changeable(trip) { return staff && state.week?.editable && !trip.operated; }
/** The duty periods of a trip that touch a date (a night stop has one per day). */
function dutiesOn(trip, date) { return trip.duties.filter(duty => duty.dates.includes(date)); }
/** First report and last release, base local. */
function tripTimes(trip) {
    if (!trip.duties.length) return '';
    return `${trip.duties[0].report_local}–${trip.duties[trip.duties.length - 1].release_local} LT`;
}

/* ---------------------------------------------------------------- Week timeline */

/** Load the weeks shown in the timeline (anchor + STRIP_WEEKS) and render the strip. */
async function loadTimeline() {
    const to = addDays(state.anchor, 7 * (STRIP_WEEKS - 1));
    const { data } = await api(`/api/v1/roster-periods?from=${state.anchor}&to=${to}`);
    state.periods = new Map(data.map(period => [period.starts_on, period]));
    renderTimeline();
}

/** One button per week: ISO week, dates, status, and for staff the open seats. The selected week is pressed. */
function renderTimeline() {
    strip.setAttribute('aria-busy', 'false');
    const items = [];
    for (let index = 0; index < STRIP_WEEKS; index++) {
        const monday = addDays(state.anchor, 7 * index);
        const period = state.periods.get(monday);
        const status = viewerState(period);
        const item = element('div', null, 'week-item'); item.setAttribute('role', 'listitem');
        const button = element('button', null, 'week-chip'); button.type = 'button';
        button.dataset.state = status.key; button.setAttribute('aria-pressed', String(monday === state.selected));
        if (monday === thisMonday) button.dataset.current = 'true';
        if (ended(monday)) button.dataset.past = 'true';
        const top = element('span', null, 'week-chip-top');
        top.append(element('span', `WK ${isoWeek(monday)}`, 'label-caps'), element('span', monday === thisMonday ? 'This week' : ended(monday) ? 'Past' : 'Upcoming', 'week-chip-when'));
        button.append(top, element('span', weekRange(monday), 'week-chip-range mono'));
        const chips = element('span', null, 'chip-list'); chips.append(chip(status.label, status.tone, { dot: true }));
        if (staff && period?.open_seats_count) chips.append(chip(`${period.open_seats_count} open`, 'warning'));
        button.append(chips);
        button.addEventListener('click', () => selectWeek(monday));
        item.append(button); items.push(item);
    }
    strip.replaceChildren(...items);
    strip.querySelector('[aria-pressed="true"]')?.scrollIntoView({ block: 'nearest', inline: 'center' });
}

/** Select a week, keep it visible in the strip, record it in the URL and load it. */
async function selectWeek(monday, { tripId = null } = {}) {
    state.selected = monday; state.tripId = tripId;
    const last = addDays(state.anchor, 7 * (STRIP_WEEKS - 1));
    if (monday < state.anchor || monday > last) { state.anchor = addDays(monday, -7 * 3); await loadTimeline(); } else renderTimeline();
    const url = new URL(window.location.href); url.searchParams.set('week', monday);
    if (tripId) url.searchParams.set('trip', tripId); else url.searchParams.delete('trip');
    window.history.replaceState(null, '', url);
    await loadWeek();
}

/* ---------------------------------------------------------------- Selected week */

/** Load the roster window for the selected week, or show the "not created" state. */
async function loadWeek() {
    view.setAttribute('aria-busy', 'true');
    const period = state.periods.get(state.selected);
    if (!period) { state.week = null; state.index = null; renderMissing(); return; }
    const { data } = await api(`/api/v1/roster-periods/${period.id}`);
    applyWeek(data);
}

/**
 * Store a week returned by the API (after loading, building, publishing or reopening) and redraw
 * everything that depends on it.
 */
function applyWeek(week) {
    state.week = week;
    state.periods.set(week.starts_on, { ...state.periods.get(week.starts_on), ...week, open_seats_count: week.summary.open });
    state.index = buildIndex(week);
    if (state.tripId && !week.trips.some(trip => trip.id === state.tripId)) state.tripId = null;
    renderTimeline(); renderHeader(); renderNotice(); renderKpis(); renderActions(); renderView(); renderInspector();
}

/** Lookups used by every view: seats per crew member, conflicts per seat and per trip. */
function buildIndex(week) {
    const seatsByCrew = new Map(); const seatConflicts = new Map(); const tripConflicts = new Map();
    for (const trip of week.trips) {
        for (const seat of trip.assignments) {
            if (seat.crew_member_id) seatsByCrew.set(seat.crew_member_id, [...(seatsByCrew.get(seat.crew_member_id) || []), { trip, seat }]);
        }
    }
    for (const conflict of week.conflicts) {
        if (conflict.assignment_id && conflict.code !== 'open_seat') seatConflicts.set(conflict.assignment_id, [...(seatConflicts.get(conflict.assignment_id) || []), conflict]);
        if (!conflict.assignment_id) tripConflicts.set(conflict.trip_id, [...(tripConflicts.get(conflict.trip_id) || []), conflict]);
    }
    return { seatsByCrew, seatConflicts, tripConflicts };
}

/** Week title and status chip. */
function renderHeader() {
    const monday = state.selected;
    document.querySelector('#week-title').textContent = `Week ${isoWeek(monday)} · ${weekRange(monday)}`;
    const status = viewerState(state.week);
    const target = document.querySelector('#week-state');
    target.textContent = ended(monday) && state.week ? `${status.label} · ended` : status.label;
    if (status.tone) target.dataset.tone = status.tone; else delete target.dataset.tone;
}

/** One-line explanation of what can be done with the selected week. */
function renderNotice() {
    const week = state.week;
    let message = null; let tone = 'info';
    if (week && ended(state.selected)) message = 'This week has ended. It is kept as read-only history.';
    else if (week?.status === 'published') message = staff ? 'Published to crew. Reopen it as a draft to rebuild or change seats.' : null;
    else if (week && staff && !week.built_at) { message = 'This draft has not been built yet. Build it to fill every seat automatically from crew working hours and the duty rules.'; tone = 'warning'; }
    else if (week && staff && week.summary.blocking) { message = `${plural(week.summary.blocking, 'rule conflict')} must be changed or accepted with an override reason before this week can be published.`; tone = 'danger'; }
    notice.hidden = !message;
    if (message) { notice.dataset.tone = tone; notice.replaceChildren(icon(tone === 'danger' ? 'shield' : tone === 'warning' ? 'alert' : 'clipboard'), element('span', message)); }
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
    const minutes = week.crew.reduce((total, crew) => total + crew.week_duty_minutes, 0);
    setKpi('week.hours', formatMinutes(minutes), 'Duty incl. timed SIM / standby');
}

/**
 * Show only the week actions that apply: create, build/rebuild, publish, reopen or email; and enable the
 * downloads once the week exists.
 */
function renderActions() {
    const week = state.week;
    for (const id of ['#download-csv', '#download-pdf', '#download-crew-pdf', '#download-calendar', '#print-week']) {
        const button = document.querySelector(id); if (button) button.disabled = !week;
    }
    if (!staff) return;
    const past = ended(state.selected);
    buttons.email.hidden = week?.status !== 'published';
    document.querySelector('#email-log').hidden = week?.status !== 'published';
    buttons.create.hidden = Boolean(week) || past;
    buttons.build.hidden = !week?.editable;
    buttons.build.querySelector('[data-label]').textContent = week?.built_at ? 'Rebuild roster' : 'Build roster';
    buttons.publish.hidden = !(week?.editable && week.built_at);
    buttons.publish.disabled = Boolean(week?.summary.blocking);
    buttons.publish.title = week?.summary.blocking ? 'Resolve the rule conflicts first' : '';
    buttons.reopen.hidden = !(week?.status === 'published' && !past);
}

/** The selected week does not exist yet (or, for crew, is not published). */
function renderMissing() {
    view.setAttribute('aria-busy', 'false');
    renderHeader(); renderNotice(); renderKpis(); renderActions(); renderInspector();
    document.querySelector('#conflict-tab-count')?.replaceChildren();
    const past = ended(state.selected);
    const empty = !staff
        ? emptyState('No published roster for this week', 'Your duties appear here once crew control publishes the week.', 'calendar')
        : past
            ? emptyState('No roster was made for this week', 'This week has ended, so a roster can no longer be created for it.', 'calendar')
            : emptyState(`No roster for week ${isoWeek(state.selected)} yet`, 'Create the week to freeze the current duty rules for it, then the generator fills every seat from enabled flights, crew working hours, leave and documents. You can change any seat afterwards.', 'calendar');
    view.replaceChildren(empty);
}

/* ---------------------------------------------------------------- Views */

/** Draw the active view: crew grid, trips or conflicts (crew accounts always see their trips). */
function renderView() {
    view.setAttribute('aria-busy', 'false');
    const counter = document.querySelector('#conflict-tab-count');
    if (counter) counter.textContent = state.week.summary.conflicts ? `(${state.week.summary.blocking}/${state.week.summary.conflicts})` : '';
    if (!state.week.trips.length) {
        return view.replaceChildren(emptyState(staff ? 'No trips this week' : 'No duties this week', staff ? (state.week.built_at ? 'No enabled flight pattern operates this week. Enable flights on the Flights & routes page, then rebuild.' : 'Build the roster to create this week\'s trips from the enabled flight patterns.') : 'You have no rostered duties in this published week.', 'calendar'));
    }
    if (!staff || state.view === 'trips') return view.replaceChildren(tripsView());
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
 * Crew × day grid (like the uibuilder roster matrix): an "Open time" row for unfilled seats, then one row per
 * crew member with duties and activities per day, and their week hours against their working hours.
 */
function gridView() {
    const days = WEEKDAYS.map((name, index) => ({ name, date: addDays(state.selected, index) }));
    const table = element('table', null, 'roster-grid');
    const head = element('tr'); head.append(element('th', 'Crew member'));
    for (const day of days) {
        const th = element('th', null, 'roster-day'); th.scope = 'col';
        if (day.date === today) th.dataset.today = 'true';
        th.append(element('span', day.name), element('strong', shortDate(day.date), 'mono'));
        head.append(th);
    }
    head.append(element('th', 'Week hours'));
    const thead = element('thead'); thead.append(head);
    const body = element('tbody');

    // Open time: unfilled seats per day (filtered by position too).
    const openRow = element('tr', null, 'open-row');
    const openHead = element('th', null, 'roster-crew'); openHead.scope = 'row';
    openHead.append(element('strong', 'Open time'), element('span', 'Seats with no legal crew', 'small muted'));
    openRow.append(openHead);
    let openTotal = 0;
    for (const day of days) {
        const td = element('td', null, 'roster-cell');
        for (const trip of state.week.trips.filter(item => item.start_date === day.date)) {
            for (const seat of trip.assignments.filter(item => !item.crew_member_id && (!state.rank || item.rank === state.rank))) {
                openTotal++;
                const button = element('button', null, 'open-seat'); button.type = 'button';
                button.append(element('span', trip.code, 'mono'), element('span', seat.rank === 'CC' ? `CC${seat.seat_number}` : seat.rank));
                button.setAttribute('aria-label', `Open ${seatName(seat)} seat on ${trip.code}, ${formatDate(trip.start_date)}`);
                button.addEventListener('click', () => (changeable(trip) ? openSeat(trip, seat) : selectTrip(trip.id)));
                td.append(button);
            }
        }
        openRow.append(td);
    }
    openRow.append(element('td', openTotal ? plural(openTotal, 'open seat') : 'None', 'roster-total small'));
    body.append(openRow);

    const crews = visibleCrew();
    for (const crew of crews) {
        const row = element('tr');
        const name = element('th', null, 'roster-crew'); name.scope = 'row';
        // Staff open day planning (leave, day off, SIM, standby) from the crew member's name.
        if (staff) {
            const open = element('button', null, 'crew-plan-button'); open.type = 'button';
            open.append(element('strong', crew.name)); open.title = `Plan leave, days off, SIM or standby for ${crew.name}`;
            open.addEventListener('click', () => openPlan(crew));
            name.append(open);
        } else name.append(element('strong', crew.name));
        const meta = element('span', `${crew.rank} · ${crew.base_airport}`, 'small muted mono'); name.append(meta);
        if (!crew.active) name.append(chip('Inactive', 'danger'));
        row.append(name);
        const seats = state.index.seatsByCrew.get(crew.id) || [];
        for (const day of days) {
            const td = element('td', null, 'roster-cell');
            for (const { trip, seat } of seats) {
                for (const duty of dutiesOn(trip, day.date)) td.append(dutyButton(trip, seat, duty, day.date));
            }
            for (const activity of crew.activities.filter(item => item.date === day.date)) td.append(activityTag(activity));
            row.append(td);
        }
        row.append(hoursCell(crew));
        body.append(row);
    }
    if (!crews.length) {
        const row = element('tr'); const td = element('td', null, 'roster-cell'); td.colSpan = 9;
        td.append(emptyState('No crew match this view', 'Change the position filter or search.', 'users')); row.append(td); body.append(row);
    }
    table.append(thead, body);
    const wrap = element('div', null, 'table-wrap roster-grid-wrap'); wrap.append(table);
    return wrap;
}

/** A duty in the grid: flight code, local times, lock for manual seats and a conflict marker. */
function dutyButton(trip, seat, duty, date) {
    const conflicts = state.index.seatConflicts.get(seat.id) || [];
    const button = element('button', null, `duty accent-${trip.palette || 'sky'}`); button.type = 'button';
    if (trip.id === state.tripId) button.dataset.selected = 'true';
    if (conflicts.some(conflict => !conflict.acknowledged)) button.dataset.conflict = 'danger';
    else if (conflicts.length) button.dataset.conflict = 'accepted';
    if (seat.source === 'manual') button.dataset.manual = 'true';
    const top = element('span', null, 'duty-top'); top.append(element('span', trip.code, 'duty-code'));
    if (seat.source === 'manual') top.append(icon('lock', 'icon-sm'));
    if (conflicts.length) top.append(icon('alert', 'icon-sm'));
    const times = duty.date === date ? `${duty.report_local}–${duty.release_local}` : `→ ${duty.release_local}`;
    button.append(top, element('span', times, 'duty-times mono'), element('span', trip.duties.length > 1 && duty.date === date && duty.trip_day > 1 ? 'Return' : trip.route || '', 'duty-route'));
    button.setAttribute('aria-label', `${trip.code} ${formatDate(trip.start_date)}, ${seatName(seat)}, ${times} local${seat.source === 'manual' ? ', locked' : ''}${conflicts.length ? `, ${plural(conflicts.length, 'conflict')}` : ''}`);
    button.addEventListener('click', () => selectTrip(trip.id));
    return button;
}

/** Leave, day off, SIM or standby in a grid cell (timed activities show local times). */
function activityTag(activity) {
    const label = ACTIVITIES[activity.type];
    const tag = element('span', null, 'activity'); tag.dataset.kind = label.tone;
    // Standby the generator planned (replaced on rebuild) is marked so it is not mistaken for a manual plan.
    if (activity.generated) tag.dataset.generated = 'true';
    tag.append(element('strong', activity.generated ? `${label.short} · auto` : label.short));
    if (activity.starts_at && activity.ends_at) tag.append(element('span', `${localClock(activity.starts_at, state.week.utc_offset_minutes)}–${localClock(activity.ends_at, state.week.utc_offset_minutes)}`, 'mono'));
    tag.title = [label.long, activity.generated ? 'planned by the generator to fill a workload gap' : null, activity.note].filter(Boolean).join(' — ');
    return tag;
}

/** Week duty hours against the crew member's weekly working hours (or the 7-day duty limit). */
function hoursCell(crew) {
    const td = element('td', null, 'roster-total');
    const capacity = crew.weekly_hours ? crew.weekly_hours * 60 : Math.round((state.week.rules?.max_duty_7d_h ?? 60) * 60);
    const value = element('strong', formatMinutes(crew.week_duty_minutes), 'mono');
    if (crew.week_duty_minutes > capacity) value.dataset.tone = 'danger';
    const bar = element('progress', null, 'coverage-bar hours-bar'); bar.max = capacity; bar.value = Math.min(crew.week_duty_minutes, capacity);
    bar.setAttribute('aria-label', `${formatMinutes(crew.week_duty_minutes)} of ${crew.weekly_hours ? `${crew.weekly_hours}h working hours` : '7-day duty limit'}`);
    td.append(value, element('span', crew.weekly_hours ? `of ${crew.weekly_hours}h` : 'no hours set', 'small muted'), bar);
    return td;
}

/**
 * Trips grouped by day, each as a flight strip with its seats. Crew accounts also see their own leave,
 * days off, SIM and standby on the day they fall.
 */
function tripsView() {
    const wrap = element('div', null, 'trip-days');
    const term = state.search.trim().toLowerCase();
    const own = staff ? null : state.week.crew[0];
    for (let index = 0; index < 7; index++) {
        const date = addDays(state.selected, index);
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
    if (trip.id === state.tripId) card.dataset.selected = 'true';
    const head = element('span', null, 'trip-strip-head'); head.append(element('span', trip.code, 'code-tag'), element('span', trip.aircraft_type || '', 'small muted mono'));
    card.append(head, element('span', `${trip.route || ''} · ${tripTimes(trip)}`, 'small mono'));
    const seats = element('span', null, 'trip-seats');
    for (const seat of trip.assignments.filter(item => !state.rank || item.rank === state.rank)) {
        const line = element('span', null, 'trip-seat'); if (!seat.crew) line.dataset.open = 'true';
        line.append(element('span', seat.rank === 'CC' ? `CC${seat.seat_number}` : seat.rank, 'mono'), element('span', seat.crew?.name || 'Open'));
        if (seat.source === 'manual') line.append(icon('lock', 'icon-sm'));
        if ((state.index.seatConflicts.get(seat.id) || []).some(conflict => !conflict.acknowledged)) line.append(icon('alert', 'icon-sm'));
        seats.append(line);
    }
    card.append(seats);
    const chips = element('span', null, 'chip-list');
    const open = trip.assignments.filter(seat => !seat.crew_member_id).length;
    if (open) chips.append(chip(`${open} open`, 'warning'));
    if (trip.operated) chips.append(chip('Operated', undefined));
    if (!trip.flight_active) chips.append(chip('Flight disabled', 'danger'));
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

/** Select a trip for the inspector and highlight it in the current view. */
function selectTrip(tripId) {
    state.tripId = tripId;
    const url = new URL(window.location.href); url.searchParams.set('trip', tripId); window.history.replaceState(null, '', url);
    for (const node of view.querySelectorAll('[data-selected]')) delete node.dataset.selected;
    renderView(); renderInspector();
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
    const title = element('div', null, 'trip-strip-head'); title.append(element('span', trip.code, 'code-tag'), chip(trip.aircraft_type || 'Aircraft', 'brand'));
    head.append(title, element('span', `${formatDate(trip.start_date)} · ${trip.route || ''}`, 'small mono'));
    const chips = element('div', null, 'chip-list');
    if (trip.operated) chips.append(chip('Operated · read-only', undefined, { iconName: 'lock' }));
    if (!trip.flight_active) chips.append(chip('Flight disabled', 'danger'));
    if (chips.childElementCount) head.append(chips);
    nodes.push(head);

    const details = element('dl', null, 'detail-list');
    const add = (term, value) => details.append(element('dt', term), element('dd', value, 'mono'));
    add('Duty (LT)', tripTimes(trip) || '—');
    if (trip.report) add('Duty (UTC)', utcRange(trip.report, trip.release));
    add('Block', formatMinutes(trip.block_minutes));
    add('Duty time', formatMinutes(trip.duty_minutes));
    nodes.push(details);

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
        const line = element('div', null, 'seat-issue'); if (!conflict.acknowledged) line.dataset.tone = 'danger';
        line.append(icon(conflict.acknowledged ? 'lock' : 'alert', 'icon-sm'), element('span', `${conflict.message}${conflict.acknowledged ? ' (override recorded)' : ''}`));
        row.append(line);
    }
    const why = rationale(seat);
    if (why) row.append(element('p', why, 'seat-why small muted'));
    return row;
}

/** Plain-language explanation from the seat's decision log (staff only). */
function rationale(seat) {
    const log = seat.decision_log;
    if (!staff || !log) return null;
    if (log.by === 'manual') {
        if (log.action === 'cleared') return `Cleared by ${log.user}; the next build can fill it.`;
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

/** Run a seat action (undo, allow again) and reload the week; the server message pops up. */
async function seatAction(path, method) {
    try { await api(path, { method }); await Promise.all([loadTimeline(), loadWeek()]); }
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
const seatStatus = dialog?.querySelector('[data-form-status]');
let editing = null;

/** Open the editor for a seat and load its candidates from the server. */
async function openSeat(trip, seat) {
    editing = { trip, seat, candidates: [] };
    seatForm.reset(); status('', false, seatStatus); overrideField.hidden = true;
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
        input.checked = candidate.current; input.disabled = !candidate.overridable;
        const body = element('span', null, 'candidate-body');
        const top = element('span', null, 'candidate-top');
        top.append(element('strong', candidate.name), element('span', candidate.base_airport, 'chip mono'));
        if (candidate.current) top.append(chip('Current', 'brand'));
        top.append(candidate.legal ? chip('Legal', 'success', { iconName: 'check' }) : chip(candidate.overridable ? plural(candidate.issues.length, 'problem') : 'Not allowed', 'danger'));
        const hours = `Week ${formatMinutes(candidate.week_minutes)} of ${candidate.weekly_hours ? `${candidate.weekly_hours}h working hours` : `${formatMinutes(candidate.capacity_minutes)} limit`}${candidate.score !== null ? ` · score ${candidate.score}` : ''}`;
        body.append(top, element('span', hours, 'small muted mono'));
        if (candidate.issues.length) {
            const issues = element('ul', null, 'candidate-issues');
            for (const issue of candidate.issues) issues.append(element('li', issue.message));
            body.append(issues);
        }
        label.append(input, body);
        return label;
    }));
    updateOverride();
}

/** The checked candidate id (a lone radio is not a RadioNodeList, so query the checked input directly). */
function checkedCrew() { return seatForm.querySelector('input[name="crew_member_id"]:checked')?.value ?? null; }
/** Show the override reason only when the chosen candidate breaks a rule. */
function updateOverride() {
    const chosen = editing?.candidates.find(candidate => String(candidate.crew_member_id) === checkedCrew());
    overrideField.hidden = !chosen || chosen.legal;
    seatForm.elements.override_reason.required = !overrideField.hidden;
}

/** Save a seat (or clear it with null), then reload the week and keep the trip selected. */
async function saveSeat(crewId) {
    const reason = seatForm.elements.override_reason.value.trim();
    try {
        await api(`/api/v1/assignments/${editing.seat.id}`, { method: 'PUT', body: { crew_member_id: crewId, override_reason: reason || null } });
        dialog.close();
        await Promise.all([loadTimeline(), loadWeek()]);
    } catch (error) {
        showError(error, seatStatus);
        if (error.errors?.override_reason) { overrideField.hidden = false; seatForm.elements.override_reason.focus(); }
    }
}

if (dialog) {
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    candidateList.addEventListener('change', updateOverride);
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
function openPlan(crew) {
    planning = crew;
    planForm.reset(); status('', false, planStatus);
    document.querySelector('#plan-dialog-title').textContent = `Day planning · ${crew.name}`;
    document.querySelector('#plan-dialog-subtitle').textContent = `${RANKS[crew.rank]} · ${crew.base_airport}${crew.weekly_hours ? ` · ${crew.weekly_hours}h working hours a week` : ''}. Times are base local.`;
    const first = state.selected < today && addDays(state.selected, 6) >= today ? today : state.selected;
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
            starts_local: planForm.elements.starts_local.value || null, ends_local: planForm.elements.ends_local.value || null, note: planForm.elements.note.value || null };
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
        const status = element('td'); status.append(chip(log.status === 'queued' ? 'Waiting for queue' : log.status === 'sent' ? 'Sent' : 'Failed', tones[log.status]));
        if (log.error) status.append(element('div', log.error, 'small muted'));
        row.append(element('td', log.name), element('td', log.email, 'small mono'), status, element('td', log.sent_at ? formatInstant(log.sent_at) : '—', 'small mono'));
        return row;
    }));
}
if (staff) {
    buttons.email.addEventListener('click', event => busy(event.currentTarget, async () => {
        const confirmed = await confirmAction({ title: `Email week ${state.week.iso_week} to crew?`, message: 'Each crew member with a seat this week receives their duties and a calendar file at the email address on their crew profile.', confirmLabel: 'Send emails', tone: 'info' });
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

/** Run the generator; the server message reports filled and open seats, and skipped patterns are listed. */
async function build() {
    const week = state.week;
    if (week.built_at) {
        const confirmed = await confirmAction({ title: `Rebuild week ${week.iso_week}?`, message: 'Automatic seats from today onwards are planned again from current crew data. Locked (manually set) seats and trips that have already operated stay as they are.', confirmLabel: 'Rebuild', tone: 'info' });
        if (!confirmed) return;
    }
    const { data } = await api(`/api/v1/roster-periods/${week.id}/build`, { method: 'POST' });
    applyWeek(data);
    for (const skipped of data.summary.build?.skipped || []) toast(skipped, { type: 'warning', title: 'Flight skipped' });
    const extra = [data.summary.build?.rebalanced ? `${plural(data.summary.build.rebalanced, 'seat')} moved to even out working hours` : null, data.summary.build?.standby ? `${plural(data.summary.build.standby, 'standby day')} planned to fill workload gaps` : null].filter(Boolean);
    if (extra.length) toast(`${extra.join('; ')}.`, { type: 'info', title: 'Balancing' });
}

if (staff) {
    // Create the week (freezing the current rules) and build it straight away.
    buttons.create.addEventListener('click', event => busy(event.currentTarget, async () => {
        try {
            const { data } = await api('/api/v1/roster-periods', { method: 'POST', body: { week_start: state.selected }, notify: false });
            state.periods.set(data.starts_on, data); state.week = { ...data, built_at: null };
            await build();
        } catch (error) { showError(error); }
        await loadTimeline().catch(showError);
    }));
    buttons.build.addEventListener('click', event => busy(event.currentTarget, () => build().catch(showError)));
    buttons.publish.addEventListener('click', event => busy(event.currentTarget, async () => {
        const confirmed = await confirmAction({ title: `Publish week ${state.week.iso_week}?`, message: `Crew will see their duties for ${weekRange(state.selected)}.${state.week.summary.open ? ` ${plural(state.week.summary.open, 'seat')} will show as open time.` : ''} To change it later, reopen it as a draft.`, confirmLabel: 'Publish', tone: 'info' });
        if (!confirmed) return;
        try { applyWeek((await api(`/api/v1/roster-periods/${state.week.id}/publish`, { method: 'POST' })).data); } catch (error) { showError(error); }
    }));
    buttons.reopen.addEventListener('click', event => busy(event.currentTarget, async () => {
        const confirmed = await confirmAction({ title: `Reopen week ${state.week.iso_week} as a draft?`, message: 'Crew stop seeing this week until you publish it again.', confirmLabel: 'Reopen', tone: 'info' });
        if (!confirmed) return;
        try { applyWeek((await api(`/api/v1/roster-periods/${state.week.id}/reopen`, { method: 'POST' })).data); } catch (error) { showError(error); }
    }));
    segmented(document.querySelector('#view-switch'), value => { state.view = value; if (state.week) renderView(); });
    segmented(document.querySelector('#rank-filter'), value => { state.rank = value; if (state.week) renderView(); });
    document.querySelector('#crew-search').addEventListener('input', event => { state.search = event.target.value; if (state.week) renderView(); });
    document.querySelector('#duties-only').addEventListener('change', event => { state.dutiesOnly = event.target.checked; if (state.week) renderView(); });
}

// Timeline navigation: shift the strip by four weeks, or jump back to this week.
document.querySelector('#timeline-earlier').addEventListener('click', () => { state.anchor = addDays(state.anchor, -28); loadTimeline().catch(showError); });
document.querySelector('#timeline-later').addEventListener('click', () => { state.anchor = addDays(state.anchor, 28); loadTimeline().catch(showError); });
document.querySelector('#timeline-today').addEventListener('click', () => { state.anchor = addDays(thisMonday, -21); selectWeek(thisMonday).catch(showError); });

// Initial load: timeline first (it tells us whether the selected week exists), then the week itself.
(async () => {
    await loadTimeline();
    await loadWeek();
    if (!staff) await loadMyHours().catch(error => { document.querySelector('#my-hours')?.replaceChildren(emptyState('Hours could not be loaded', error.message || 'Reload the page to try again.', 'alert')); });
})().catch(error => {
    view.setAttribute('aria-busy', 'false'); strip.setAttribute('aria-busy', 'false');
    view.replaceChildren(emptyState('Roster could not be loaded', error.message || 'Reload the page to try again.', 'alert'));
    showError(error);
});
