/**
 * Flights & routes page: pattern table with enable/disable switches, a rotation breakdown side panel,
 * and the create/edit/copy dialog with its leg editor. Times are base-local throughout.
 */
import { api, allPages } from '../common/api';
import { editor } from '../common/editor';
import { refreshShell } from '../common/shell';
import { actions, cell, chip, element, emptyRow, formatMinutes, icon, iconButton, options, plural, segmented, setKpi, showError } from '../common/ui';

const form = document.querySelector('#flight-form');
const tbody = document.querySelector('#flight-rows');
const legs = document.querySelector('#leg-editor');
const detail = document.querySelector('#flight-detail');
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
// Page state: lookup airports, loaded flights, the selected row and the active filters.
let airports = [];
let flights = [];
let selectedId = null;
let stateFilter = '';
let search = '';

/** Keep the leg badges (1, 2, 3...) in order after adding or removing a leg. */
function renumberLegs() { [...legs.children].forEach((row, index) => { row.querySelector('.leg-number').textContent = index + 1; }); }
/** Append an editable leg row (trip day, from, to, departs, arrives) to the dialog. */
function addLeg(record = {}) {
    const row = element('div', null, 'leg-row');
    row.append(element('span', '', 'leg-number'));
    for (const [key, label, type] of [['trip_day', 'Trip day', 'number'], ['from_airport', 'From', 'select'], ['to_airport', 'To', 'select'], ['departs_local', 'Departs', 'time'], ['arrives_local', 'Arrives', 'time']]) {
        const wrapper = element('label', label); const input = element(type === 'select' ? 'select' : 'input');
        input.dataset.field = key; input.required = true;
        if (type === 'select') options(input, airports, 'code', airport => `${airport.code}${airport.is_base ? ' · base' : ''}`); else input.type = type;
        if (key === 'trip_day') { input.min = '1'; input.max = '4'; }
        if (type !== 'number') input.classList.add('mono');
        input.value = record[key] ?? (key === 'trip_day' ? 1 : type === 'select' ? airports[0]?.code : '');
        wrapper.append(input); row.append(wrapper);
    }
    row.append(iconButton('trash', 'Remove leg', () => { row.remove(); renumberLegs(); }, 'danger'));
    legs.append(row); renumberLegs();
}

// Create/edit dialog. "Copy" opens it pre-filled with an empty code so a new pattern is created.
const controller = editor({
    form, dialog: document.querySelector('#flight-dialog'), endpoint: '/api/v1/flights', label: 'Flight pattern', refresh,
    read: () => ({
        code: form.elements.code.value, aircraft_type_id: Number(form.elements.aircraft_type_id.value), active: form.elements.active.checked,
        weekdays: [...form.querySelectorAll('[name="weekdays"]:checked')].map(input => Number(input.value)),
        legs: [...legs.children].map(row => Object.fromEntries([...row.querySelectorAll('[data-field]')].map(input => [input.dataset.field, input.dataset.field === 'trip_day' ? Number(input.value) : input.value]))),
    }),
    fill: (record, { copy }) => {
        legs.replaceChildren();
        if (!record) { addLeg(); addLeg(); return; }
        form.elements.code.value = copy ? '' : record.code;
        form.elements.aircraft_type_id.value = record.aircraft_type_id; form.elements.active.checked = record.active;
        for (const input of form.querySelectorAll('[name="weekdays"]')) input.checked = record.weekdays.includes(Number(input.value));
        record.legs.forEach(addLeg);
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
    chain.append(element('span', `${plural(record.legs.length, 'leg')}${days > 1 ? ` · ${days}-day rotation with night stop` : ''}`, 'route-meta'));
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
    return `${first.departs_local} – ${last.arrives_local}${last.trip_day > 1 ? ` (+${last.trip_day - 1}d)` : ''}`;
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
            currentDay = leg.trip_day; nodes.push(element('span', `Trip day ${currentDay}${currentDay > 1 ? ' · after night stop' : ''}`, 'label-caps muted leg-day'));
            list = element('ol', null, 'leg-list'); nodes.push(list);
        }
        const item = element('li', null, 'strip leg-item'); item.classList.add(`accent-${record.aircraft.palette}`);
        const route = element('div'); route.append(element('span', `${leg.from_airport} → ${leg.to_airport}`, 'leg-route'), element('div', `Leg ${leg.sequence}`, 'small muted'));
        const times = element('div', null, 'leg-times'); times.append(element('span', `${leg.departs_local} → ${leg.arrives_local}`), element('span', `Block ${formatMinutes(leg.block_minutes)}`, 'leg-block'));
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
document.querySelector('#add-leg').addEventListener('click', () => addLeg());
document.querySelector('#add-flight').addEventListener('click', () => controller.open());
(async () => {
    const { data } = await api('/api/v1/lookups'); airports = data.airports;
    options(form.elements.aircraft_type_id, data.aircraft_types);
    document.querySelector('#add-flight').disabled = false;
    await refresh();
})().catch(error => { emptyRow(tbody, 8, 'Flight patterns could not be loaded. Reload the page to try again.', 'alert'); showError(error); });
