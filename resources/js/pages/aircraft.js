/**
 * Fleet page: airframe cards with availability status and next maintenance, the status dialog,
 * and the aircraft types table. All data comes from /api/v1/aircraft and /api/v1/aircraft-types.
 */
import { api, allPages } from '../common/api';
import { editor } from '../common/editor';
import { refreshShell } from '../common/shell';
import {
    actions, aircraftStatusTone, busy, cell, chip, dueSummary, dueTone, element, emptyRow, emptyState, formatInstant, formatNumber,
    icon, iconButton, options, plural, segmented, setKpi, showError, status,
} from '../common/ui';

const cards = document.querySelector('#aircraft-cards');
const typeRows = document.querySelector('#type-rows');
const aircraftForm = document.querySelector('#aircraft-form');
const typeForm = document.querySelector('#type-form');
const statusDialog = document.querySelector('#status-dialog');
const statusForm = document.querySelector('#status-form');
// Page state: loaded records, the active status filter and the airframe whose status is being changed.
let aircraft = [];
let types = [];
let statusFilter = '';
let statusTarget = null;

// Create/edit dialog for airframes (status is changed separately through the status dialog).
const aircraftEditor = editor({
    form: aircraftForm, dialog: document.querySelector('#aircraft-dialog'), endpoint: '/api/v1/aircraft', label: 'Aircraft', refresh,
    read: () => ({
        registration: aircraftForm.elements.registration.value, aircraft_type_id: Number(aircraftForm.elements.aircraft_type_id.value),
        airframe_hours: Number(aircraftForm.elements.airframe_hours.value), notes: aircraftForm.elements.notes.value || null,
    }),
    fill: record => {
        if (!record) return;
        aircraftForm.elements.registration.value = record.registration;
        aircraftForm.elements.aircraft_type_id.value = record.aircraft_type_id;
        aircraftForm.elements.airframe_hours.value = record.airframe_hours;
        aircraftForm.elements.notes.value = record.notes || '';
    },
});
// Create/edit dialog for aircraft types.
const typeEditor = editor({
    form: typeForm, dialog: document.querySelector('#type-dialog'), endpoint: '/api/v1/aircraft-types', label: 'Aircraft type', refresh,
    read: () => ({ code: typeForm.elements.code.value, cabin_crew_required: Number(typeForm.elements.cabin_crew_required.value), palette: typeForm.elements.palette.value }),
    fill: record => { if (record) for (const key of ['code', 'cabin_crew_required', 'palette']) typeForm.elements[key].value = record[key]; },
});

/** Open the status dialog pre-selected with the airframe's current status and reason. */
function openStatus(record) {
    statusTarget = record; statusForm.reset(); status('', false, statusForm.querySelector('[data-form-status]'));
    document.querySelector('#status-dialog-subtitle').textContent = `${record.registration} · ${record.aircraft_type?.code ?? ''} · currently ${record.status_label.toLowerCase()}`;
    // Older statuses (in maintenance, grounded) are all "not available" in this simplified choice.
    statusForm.querySelector(`[name="status"][value="${record.status === 'available' ? 'available' : 'unavailable'}"]`).checked = true;
    statusForm.elements.reason.value = record.status_reason || '';
    statusDialog.showModal();
}
// Submit a status change; the API's message pops up and inline errors stay in the dialog.
statusDialog.querySelector('[data-close]').addEventListener('click', () => statusDialog.close());
statusForm.addEventListener('submit', async event => {
    event.preventDefault();
    await busy(statusForm.querySelector('[type="submit"]'), async () => {
        try {
            await api(`/api/v1/aircraft/${statusTarget.id}/status`, { method: 'PATCH', body: { status: statusForm.elements.status.value, reason: statusForm.elements.reason.value || null } });
            statusDialog.close(); await refresh();
        } catch (error) { showError(error, statusForm.querySelector('[data-form-status]')); }
    });
});

/** "Next maintenance" section of a card: the two most urgent due items, or a note when none are recorded. */
function dueBlock(record) {
    const block = element('div', null, 'airframe-due');
    block.append(element('span', 'Next maintenance', 'label-caps muted'));
    const items = record.maintenance_due || [];
    if (!items.length) { block.append(element('span', 'No due check recorded', 'small muted')); return block; }
    for (const item of items.slice(0, 2)) {
        const line = element('span', null, 'airframe-due-line');
        line.append(chip(item.state === 'ok' ? 'On track' : item.state === 'overdue' ? 'Overdue' : 'Due soon', dueTone[item.state], { dot: true }), `${item.record.kind_label} · ${dueSummary(item)}`);
        block.append(line);
    }
    if (items.length > 2) block.append(element('span', `+ ${plural(items.length - 2, 'more item')}`, 'small muted'));
    return block;
}

/** Build one airframe card (registration, type, status, reason, hours, due items and actions). */
function airframeCard(record) {
    const card = element('article', null, 'airframe-card'); card.dataset.status = record.status;
    const head = element('header', null, 'airframe-head');
    const identity = element('div'); identity.append(element('span', record.registration, 'airframe-reg'));
    const type = element('div', null, 'airframe-type'); type.append(element('span', record.aircraft_type?.code ?? 'Unknown type', `chip palette-${record.aircraft_type?.palette ?? 'sky'}`)); identity.append(type);
    head.append(identity, chip(record.status_label, aircraftStatusTone[record.status], { dot: true }));
    card.append(head);
    if (record.status_reason) card.append(element('p', record.status_reason, 'airframe-reason'));
    const details = element('dl', null, 'detail-list');
    details.append(element('dt', 'Airframe hours'), element('dd', `${formatNumber(record.airframe_hours)} h`, 'mono'), element('dt', 'Status since'), element('dd', formatInstant(record.status_changed_at), 'mono small'));
    card.append(details, dueBlock(record));
    const footer = element('footer', null, 'airframe-actions');
    const change = element('button', null, 'button button-sm button-secondary'); change.type = 'button'; change.setAttribute('aria-label', `Change status of ${record.registration}`); change.append(icon('power', 'icon-sm'), 'Availability'); change.addEventListener('click', () => openStatus(record));
    const log = element('a', null, 'button button-sm button-quiet'); log.href = `/maintenance?aircraft=${record.id}`; log.setAttribute('aria-label', `Maintenance log for ${record.registration}`); log.append(icon('wrench', 'icon-sm'), 'Log');
    const tools = element('div', null, 'row-actions');
    tools.append(iconButton('pencil', `Edit ${record.registration}`, () => aircraftEditor.open(record)), iconButton('trash', `Remove ${record.registration}`, () => aircraftEditor.remove(record), 'danger'));
    footer.append(change, log, tools); card.append(footer);
    return card;
}

/** Render the cards for the selected status filter, or an empty state. */
function renderAircraft() {
    const visible = aircraft.filter(record => !statusFilter || (statusFilter === 'available') === (record.status === 'available'));
    document.querySelector('#aircraft-count').textContent = plural(aircraft.length, 'airframe');
    cards.setAttribute('aria-busy', 'false');
    if (!visible.length) {
        cards.replaceChildren(emptyState(aircraft.length ? 'No aircraft with this status' : 'No aircraft registered yet', aircraft.length ? 'Choose another status filter to see the rest of the fleet.' : 'Register each airframe by its tail registration to track availability and maintenance.', 'plane'));
        return;
    }
    cards.replaceChildren(...visible.map(airframeCard));
}

/** Render the aircraft types table with available/total airframe counts. */
function renderTypes() {
    document.querySelector('#type-count').textContent = plural(types.length, 'type');
    if (!types.length) return emptyRow(typeRows, 5, 'No aircraft types yet. Add a type before registering aircraft or flight patterns.', 'grid');
    typeRows.replaceChildren(...types.map(record => {
        const row = element('tr'); cell(row, record.code); cell(row, plural(record.cabin_crew_required, 'seat'), 'mono');
        cell(row).append(element('span', record.palette, `chip palette-${record.palette}`));
        const availability = cell(row);
        if (!record.aircraft_count) availability.append(element('span', 'No airframes registered', 'small muted'));
        else availability.append(chip(`${record.available_aircraft_count} of ${record.aircraft_count} available`, record.available_aircraft_count ? 'success' : 'danger', { dot: true }));
        actions(row, () => typeEditor.open(record), () => typeEditor.remove(record));
        return row;
    }));
}

/** Availability counts for the KPI strip, from the loaded airframes. */
function renderKpis() {
    const available = aircraft.filter(record => record.status === 'available').length;
    setKpi('fleet.total', aircraft.length, `${plural(types.length, 'aircraft type')}`);
    setKpi('fleet.available', available);
    setKpi('fleet.not_available', aircraft.length - available);
}

/** Reload airframes and types, re-render everything and refresh the navigation counts. */
async function refresh() {
    [aircraft, types] = await Promise.all([allPages('/api/v1/aircraft'), allPages('/api/v1/aircraft-types')]);
    options(aircraftForm.elements.aircraft_type_id, types);
    document.querySelector('#add-aircraft').disabled = !types.length;
    document.querySelector('#add-aircraft').title = types.length ? '' : 'Add an aircraft type first';
    renderAircraft(); renderTypes(); renderKpis();
    refreshShell({ refresh: true }).catch(() => {});
}

// Wire up filters and buttons, then load the page.
segmented(document.querySelector('#status-filter'), value => { statusFilter = value; renderAircraft(); });
document.querySelector('#add-aircraft').addEventListener('click', () => aircraftEditor.open());
document.querySelector('#add-type').addEventListener('click', () => typeEditor.open());
document.querySelector('#add-type').disabled = false;
refresh().catch(error => { cards.replaceChildren(emptyState('Fleet could not be loaded', error.message || 'Reload the page to try again.', 'alert')); showError(error); });
