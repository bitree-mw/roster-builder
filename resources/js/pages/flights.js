/**
 * Flights & routes page: pattern table with enable/disable switches, a rotation breakdown side panel,
 * and the create/edit/copy dialog with its leg editor. Times are shown and entered in GMT; the server converts
 * them to base-local storage (time_zone: "utc"). A new leg starts at the previous leg's destination; when that
 * is an outstation the page asks whether the crew night-stop there. Night stops become trip days for the API
 * (trip day 2 after the first night stop, and so on). The same crew fly the whole rotation and change at base.
 */
import { api, allPages } from '../common/api';
import { confirmAction } from '../common/confirm';
import { editor } from '../common/editor';
import { refreshShell } from '../common/shell';
import { actions, cell, chip, element, emptyRow, formatMinutes, icon, iconButton, options, plural, segmented, setKpi, showError } from '../common/ui';

const form = document.querySelector('#flight-form');
const tbody = document.querySelector('#flight-rows');
const legs = document.querySelector('#leg-editor');
const detail = document.querySelector('#flight-detail');
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
// Longest rotation in days (config roster.max_trip_days, printed on the leg editor by Blade).
const MAX_TRIP_DAYS = Number(legs.dataset.maxTripDays) || 14;
// Page state: lookup airports, loaded flights, the selected row and the active filters.
let airports = [];
let flights = [];
let selectedId = null;
let stateFilter = '';
let search = '';

/** True when the airport code is a crew base (night stops are only offered at outstations). */
function isBase(code) { return airports.some(airport => airport.code === code && airport.is_base); }

/**
 * Keep the leg badges (1, 2, 3...) and the "Day N" labels in order after a leg is added, removed or its night
 * stop changes. A leg's day is 1 plus the nights stopped before it, which is what read() sends as trip_day.
 */
function renumberLegs() {
    let day = 1;
    [...legs.children].forEach((row, index) => {
        row.querySelector('.leg-number').textContent = index + 1;
        row.querySelector('.leg-day-label').textContent = `Day ${day}`;
        row.dataset.tripDay = String(day);
        const stopping = row.querySelector('[data-field="night_stop"]').checked;
        const nights = row.querySelector('[data-field="nights"]');
        nights.closest('label').hidden = !stopping;
        if (stopping) day += Math.max(1, Number(nights.value) || 1);
    });
}

/**
 * Enable the night-stop box only when the leg lands at an outstation: the trip ends back at base, where crew
 * change, so a night at base is a new route rather than a night stop.
 */
function syncNightStop(row) {
    const box = row.querySelector('[data-field="night_stop"]');
    const destination = row.querySelector('[data-field="to_airport"]').value;
    box.disabled = isBase(destination);
    if (box.disabled) box.checked = false;
    row.querySelector('.night-stop-text').textContent = `Night stop at ${destination}`;
    row.querySelector('[data-field="nights"]').setAttribute('aria-label', `Nights at ${destination}`);
}

/**
 * Append an editable leg row (from, to, departs GMT, arrives GMT, night stop at the destination). A new leg
 * without a record starts where the previous leg landed (its "from" is the previous "to", and it goes back to
 * where that leg came from). nights > 0 ticks the night-stop box with that many nights (when loading a saved
 * pattern, from the gap between trip days).
 */
function addLeg(record = null, nights = 0) {
    const previous = legs.lastElementChild;
    if (!record && previous) {
        const field = name => previous.querySelector(`[data-field="${name}"]`).value;
        record = { from_airport: field('to_airport'), to_airport: field('from_airport') };
    }
    // The first leg of a new flight departs a crew base (the server requires it) for the first other airport.
    if (!record && !previous) {
        const base = airports.find(airport => airport.is_base)?.code;
        record = { from_airport: base, to_airport: airports.find(airport => airport.code !== base)?.code };
    }
    record ??= {};
    const row = element('div', null, 'leg-row');
    const badge = element('div', null, 'leg-badge');
    badge.append(element('span', '', 'leg-number'), element('span', '', 'leg-day-label label-caps muted'));
    row.append(badge);
    for (const [key, label, type] of [['from_airport', 'From', 'select'], ['to_airport', 'To', 'select'], ['departs_utc', 'Departs (GMT)', 'time'], ['arrives_utc', 'Arrives (GMT)', 'time']]) {
        const wrapper = element('label', label); const input = element(type === 'select' ? 'select' : 'input');
        input.dataset.field = key; input.required = true; input.classList.add('mono');
        if (type === 'select') options(input, airports, 'code', airport => `${airport.code}${airport.is_base ? ' · base' : ''}`); else input.type = type;
        input.value = record[key] ?? (type === 'select' ? airports[0]?.code : '');
        wrapper.append(input); row.append(wrapper);
    }
    // Night stop at this leg's destination for one or more nights: the next leg flies that many days later,
    // with the same crew (they change only once the rotation is back at base).
    const stopping = element('div', null, 'night-stop');
    const stop = element('label', null, 'check-label');
    const box = element('input'); box.type = 'checkbox'; box.dataset.field = 'night_stop'; box.checked = nights > 0;
    box.addEventListener('change', renumberLegs);
    stop.append(box, element('span', '', 'night-stop-text'));
    const count = element('label', 'Nights', 'night-count');
    const nightsInput = element('input', null, 'mono'); nightsInput.type = 'number'; nightsInput.dataset.field = 'nights';
    nightsInput.min = '1'; nightsInput.max = String(MAX_TRIP_DAYS - 1); nightsInput.value = String(Math.max(1, nights));
    nightsInput.addEventListener('input', renumberLegs);
    count.append(nightsInput);
    stopping.append(stop, count);
    row.append(stopping);
    // Changing where a leg lands moves the start of the next leg with it, and re-checks the night-stop option.
    row.querySelector('[data-field="to_airport"]').addEventListener('change', event => {
        const next = row.nextElementSibling;
        if (next) next.querySelector('[data-field="from_airport"]').value = event.target.value;
        syncNightStop(row); renumberLegs();
    });
    row.append(iconButton('trash', 'Remove leg', () => { row.remove(); renumberLegs(); }, 'danger'));
    legs.append(row); syncNightStop(row); renumberLegs();
    return row;
}

/**
 * "Add leg" button: when the last leg lands at an outstation, ask whether the crew night-stop there before the
 * next leg (answering no keeps the next leg on the same day). The answer can be changed on the leg afterwards.
 */
async function addLegWithPrompt() {
    const previous = legs.lastElementChild;
    const destination = previous?.querySelector('[data-field="to_airport"]').value;
    if (previous && destination && !isBase(destination) && !previous.querySelector('[data-field="night_stop"]').checked) {
        const nightStop = await confirmAction({
            title: `Night stop at ${destination}?`,
            message: `Should the crew stay overnight at ${destination} and fly the next leg the following day? The same crew stay with the trip until it is back at base, where crew change.`,
            confirmLabel: 'Yes, night stop', cancelLabel: 'No, same day', tone: 'info',
        });
        previous.querySelector('[data-field="night_stop"]').checked = nightStop;
    }
    addLeg().querySelector('[data-field="departs_utc"]').focus();
}

// Create/edit dialog. "Copy" opens it pre-filled with an empty code so a new pattern is created.
const controller = editor({
    form, dialog: document.querySelector('#flight-dialog'), endpoint: '/api/v1/flights', label: 'Flight pattern', refresh,
    read: () => ({
        code: form.elements.code.value, aircraft_type_id: Number(form.elements.aircraft_type_id.value), active: form.elements.active.checked,
        weekdays: [...form.querySelectorAll('[name="weekdays"]:checked')].map(input => Number(input.value)),
        // Leg times are GMT; the field names below are the API's, with time_zone telling it to convert.
        time_zone: 'utc',
        // trip_day comes from the night stops before each leg (see renumberLegs).
        legs: [...legs.children].map(row => {
            const value = name => row.querySelector(`[data-field="${name}"]`).value;
            return { trip_day: Number(row.dataset.tripDay), from_airport: value('from_airport'), to_airport: value('to_airport'), departs_local: value('departs_utc'), arrives_local: value('arrives_utc') };
        }),
    }),
    fill: (record, { copy }) => {
        legs.replaceChildren();
        if (!record) { addLeg(); addLeg(); return; }
        form.elements.code.value = copy ? '' : record.code;
        form.elements.aircraft_type_id.value = record.aircraft_type_id; form.elements.active.checked = record.active;
        for (const input of form.querySelectorAll('[name="weekdays"]')) input.checked = record.weekdays.includes(Number(input.value));
        // A leg is followed by a night stop when the next leg is on a later trip day.
        record.legs.forEach((leg, index) => addLeg(leg, (record.legs[index + 1]?.trip_day ?? leg.trip_day) - leg.trip_day));
    },
});

/** "LLW → BLZ → LLW" with a note for multi-day rotations. */
function routeChain(record) {
    const chain = element('div', null, 'route-chain');
    record.legs.forEach((leg, index) => {
        if (index === 0) chain.append(element('span', leg.from_airport));
        chain.append(icon('arrow-right'), element('span', leg.to_airport));
    });
    const days = Math.max(...record.legs.map(leg => leg.trip_day));
    const stops = record.legs.filter((leg, index) => (record.legs[index + 1]?.trip_day ?? leg.trip_day) > leg.trip_day).map(leg => leg.to_airport);
    chain.append(element('span', `${plural(record.legs.length, 'leg')}${days > 1 ? ` · ${days}-day rotation, night stop ${stops.join(', ')}` : ''}`, 'route-meta'));
    return chain;
}
/** Seven day boxes with operating days highlighted (Monday first). */
function weekStrip(weekdays) {
    const strip = element('span', null, 'week-strip'); strip.setAttribute('aria-label', weekdays.map(day => DAYS[day]).join(', ') || 'No operating days');
    DAYS.forEach((day, index) => { const box = element('span', day[0]); box.dataset.on = String(weekdays.includes(index)); box.setAttribute('aria-hidden', 'true'); strip.append(box); });
    return strip;
}
/** True when airframes of the type are registered but none is currently available. */
function noAvailableAircraft(record) { return record.aircraft?.aircraft_count > 0 && record.aircraft.available_aircraft_count === 0; }
/** Aircraft type chip plus an availability warning when needed. */
function aircraftCell(record) {
    const wrap = element('div', null, 'aircraft-cell');
    wrap.append(element('span', record.aircraft.code, `chip palette-${record.aircraft.palette}`));
    if (noAvailableAircraft(record)) wrap.append(chip('No available airframe', 'warning', { iconName: 'alert' }));
    return wrap;
}
/** Enable/disable switch; calls PATCH /flights/{id}/status and the server's message pops up. */
function statusSwitch(record) {
    const button = element('button', null, 'switch'); button.type = 'button'; button.setAttribute('role', 'switch');
    button.setAttribute('aria-checked', String(record.active)); button.setAttribute('aria-label', `${record.code} enabled for planning`);
    button.append(element('span', null, 'switch-track'), element('span', record.active ? 'Enabled' : 'Disabled'));
    button.addEventListener('click', async event => {
        event.stopPropagation(); button.disabled = true;
        try {
            const { data } = await api(`/api/v1/flights/${record.id}/status`, { method: 'PATCH', body: { active: !record.active } });
            Object.assign(record, data); render(); refreshShell({ refresh: true }).catch(() => {});
        } catch (error) { showError(error); button.disabled = false; }
    });
    return button;
}
/** First departure – last arrival, with "+Nd" when the rotation ends on a later day. */
function firstLastTimes(record) {
    const first = record.legs[0]; const last = record.legs[record.legs.length - 1];
    return `${first.departs_utc} – ${last.arrives_utc}${last.trip_day > 1 ? ` (+${last.trip_day - 1}d)` : ''}`;
}

/** Fill the rotation breakdown panel for the selected flight. */
function renderDetail() {
    const record = flights.find(flight => flight.id === selectedId);
    if (!record) { detail.replaceChildren(element('p', 'Select a flight to see its legs, block time and status.', 'muted small')); return; }
    const head = element('div', null, 'detail-head');
    const title = element('div'); title.append(element('div', record.code, 'detail-code'), element('span', record.aircraft.code, `chip palette-${record.aircraft.palette}`));
    head.append(title, chip(record.active ? 'Enabled' : 'Disabled', record.active ? 'success' : undefined, { dot: true }));
    const summary = element('div', null, 'detail-summary');
    for (const [label, value] of [['Block', formatMinutes(record.block_minutes)], ['Legs', String(record.legs.length)], ['Days', record.weekdays.length === 7 ? 'Daily' : String(record.weekdays.length)]]) {
        const box = element('div'); box.append(element('span', label, 'label-caps muted'), element('strong', value)); summary.append(box);
    }
    const nodes = [head, summary];
    if (noAvailableAircraft(record)) {
        const warning = element('div', null, 'alert-row detail-warning'); const warnIcon = icon('alert'); warnIcon.classList.add('alert-row-icon');
        const text = element('div', null, 'alert-row-text'); text.append(element('span', 'Aircraft availability', 'alert-row-title'), element('span', `All ${record.aircraft.aircraft_count} registered ${record.aircraft.code} airframes are unavailable. Check the Fleet page before planning this flight.`));
        warning.append(warnIcon, text); nodes.push(warning);
    }
    let currentDay = 0; let list = null;
    for (const leg of record.legs) {
        if (leg.trip_day !== currentDay) {
            // After a night stop: where the crew stayed and for how many nights (the gap between trip days).
            const stopAt = list ? record.legs[record.legs.indexOf(leg) - 1].to_airport : null;
            const nights = leg.trip_day - currentDay;
            currentDay = leg.trip_day; nodes.push(element('span', `Trip day ${currentDay}${stopAt ? ` · after ${plural(nights, 'night')} at ${stopAt}` : ''}`, 'label-caps muted leg-day'));
            list = element('ol', null, 'leg-list'); nodes.push(list);
        }
        const item = element('li', null, 'strip leg-item'); item.classList.add(`accent-${record.aircraft.palette}`);
        const route = element('div'); route.append(element('span', `${leg.from_airport} → ${leg.to_airport}`, 'leg-route'), element('div', `Leg ${leg.sequence}`, 'small muted'));
        const times = element('div', null, 'leg-times'); times.append(element('span', `${leg.departs_utc} → ${leg.arrives_utc} GMT`), element('span', `Block ${formatMinutes(leg.block_minutes)}`, 'leg-block'));
        item.append(route, times); list.append(item);
    }
    const tools = element('div', null, 'toolbar');
    const edit = element('button', null, 'button button-sm'); edit.type = 'button'; edit.append(icon('pencil', 'icon-sm'), 'Edit pattern'); edit.addEventListener('click', () => controller.open(record));
    const copy = element('button', null, 'button button-sm button-secondary'); copy.type = 'button'; copy.append(icon('copy', 'icon-sm'), 'Copy'); copy.addEventListener('click', () => controller.open(record, { copy: true }));
    tools.append(edit, copy); nodes.push(tools);
    detail.replaceChildren(...nodes);
}

/** Select a flight row and show its breakdown. */
function select(id) { selectedId = id; render(); }
/** Render the table for the current filters, the side panel and the KPI strip. */
function render() {
    const term = search.trim().toUpperCase();
    const visible = flights.filter(record => (!stateFilter || (stateFilter === 'enabled') === record.active)
        && (!term || record.code.includes(term) || record.legs.some(leg => leg.from_airport.includes(term) || leg.to_airport.includes(term))));
    document.querySelector('#flight-count').textContent = `${visible.length} of ${plural(flights.length, 'line')}`;
    if (!visible.length) emptyRow(tbody, 8, flights.length ? 'No flights match these filters.' : 'No flight patterns yet. Add a connected route to get started.', 'route');
    else tbody.replaceChildren(...visible.map(record => {
        const row = element('tr'); row.dataset.active = String(record.active); row.setAttribute('aria-selected', String(record.id === selectedId));
        row.classList.add(`accent-${record.aircraft.palette}`);
        const code = element('button', null, 'code-tag'); code.type = 'button'; code.textContent = record.code; code.setAttribute('aria-label', `Inspect ${record.code}`);
        cell(row).append(code); cell(row).append(aircraftCell(record)); cell(row).append(routeChain(record));
        cell(row, firstLastTimes(record), 'mono'); cell(row, formatMinutes(record.block_minutes), 'mono'); cell(row).append(weekStrip(record.weekdays)); cell(row).append(statusSwitch(record));
        const tools = actions(row, () => controller.open(record), () => controller.remove(record), [iconButton('copy', `Copy ${record.code}`, () => controller.open(record, { copy: true }))]);
        tools.addEventListener('click', event => event.stopPropagation());
        row.addEventListener('click', () => select(record.id));
        return row;
    }));
    renderDetail();
    setKpi('flights.total', flights.length); setKpi('flights.enabled', flights.filter(record => record.active).length);
    setKpi('flights.disabled', flights.filter(record => !record.active).length);
    setKpi('flights.night', flights.filter(record => record.legs.some(leg => leg.trip_day > 1)).length);
    setKpi('flights.aircraft', flights.filter(record => record.active && noAvailableAircraft(record)).length);
}

/** Reload flights (keeping the selection when possible) and refresh navigation counts. */
async function refresh() {
    flights = await allPages('/api/v1/flights');
    if (!flights.some(record => record.id === selectedId)) selectedId = flights[0]?.id ?? null;
    render(); refreshShell({ refresh: true }).catch(() => {});
}

// Wire up filters and buttons, load lookups, then load flights.
segmented(document.querySelector('#state-filter'), value => { stateFilter = value; render(); });
document.querySelector('#flight-search').addEventListener('input', event => { search = event.target.value; render(); });
document.querySelector('#add-leg').addEventListener('click', () => addLegWithPrompt().catch(showError));
document.querySelector('#add-flight').addEventListener('click', () => controller.open());
(async () => {
    const { data } = await api('/api/v1/lookups'); airports = data.airports;
    options(form.elements.aircraft_type_id, data.aircraft_types);
    document.querySelector('#add-flight').disabled = false;
    await refresh();
})().catch(error => { emptyRow(tbody, 8, 'Flight patterns could not be loaded. Reload the page to try again.', 'alert'); showError(error); });
