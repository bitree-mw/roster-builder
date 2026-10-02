import { api, allPages } from '../common/api';
import { editor } from '../common/editor';
import { refreshShell } from '../common/shell';
import { actions, cell, chip, dueSummary, dueTone, element, emptyRow, emptyState, formatDate, formatNumber, icon, loadingRow, options, plural, setKpi, showError } from '../common/ui';

const form = document.querySelector('#record-form');
const rows = document.querySelector('#record-rows');
const alertList = document.querySelector('#alert-list');
const filter = document.querySelector('#aircraft-filter');
const requested = new URLSearchParams(window.location.search).get('aircraft');
let aircraft = [];
const numberOrNull = input => input.value === '' ? null : Number(input.value);

const controller = editor({
    form, dialog: document.querySelector('#record-dialog'), endpoint: '/api/v1/maintenance-records', label: 'Maintenance record', refresh,
    title: record => `${record.title} on ${record.aircraft?.registration ?? 'this aircraft'}`,
    read: () => ({
        aircraft_id: Number(form.elements.aircraft_id.value), kind: form.elements.kind.value, title: form.elements.title.value,
        performed_on: form.elements.performed_on.value, airframe_hours_at: numberOrNull(form.elements.airframe_hours_at),
        next_due_on: form.elements.next_due_on.value || null, next_due_hours: numberOrNull(form.elements.next_due_hours), notes: form.elements.notes.value || null,
    }),
    fill: record => {
        if (filter.value) form.elements.aircraft_id.value = filter.value;
        if (!record) return;
        for (const key of ['aircraft_id', 'kind', 'title', 'performed_on', 'airframe_hours_at', 'next_due_on', 'next_due_hours', 'notes']) form.elements[key].value = record[key] ?? '';
    },
});

function renderAlerts(items) {
    alertList.setAttribute('aria-busy', 'false');
    document.querySelector('#alert-count').textContent = plural(items.length, 'alert');
    if (!items.length) { alertList.replaceChildren(emptyState('Nothing overdue or due soon', 'Every recorded check is outside the alert window. Keep airframe hours current so hour limits are evaluated accurately.', 'shield')); return; }
    alertList.replaceChildren(...items.map(item => {
        const record = item.record;
        const row = element('div', null, 'alert-row'); if (item.state === 'overdue') row.dataset.tone = 'danger';
        const text = element('div', null, 'alert-row-text');
        text.append(element('span', `${item.state === 'overdue' ? 'Overdue' : 'Due soon'} · ${record.aircraft.registration} · ${record.kind_label}`, 'alert-row-title'));
        const due = [record.next_due_on ? `due ${formatDate(record.next_due_on)}` : null, record.next_due_hours !== null ? `at ${formatNumber(record.next_due_hours)} h (now ${formatNumber(record.aircraft.airframe_hours)} h)` : null].filter(Boolean).join(' · ');
        text.append(element('span', `${record.title} — ${dueSummary(item)}`), element('span', `${record.aircraft.type ?? ''} · ${due} · last done ${formatDate(record.performed_on)}`, 'alert-row-meta'));
        const complete = element('button', null, 'button button-sm button-secondary'); complete.type = 'button';
        complete.append(icon('check', 'icon-sm'), 'Record completion');
        complete.addEventListener('click', () => controller.open({ aircraft_id: record.aircraft_id, kind: record.kind, title: record.title, airframe_hours_at: record.aircraft.airframe_hours }));
        const severity = icon(item.state === 'overdue' ? 'alert' : 'clock'); severity.classList.add('alert-row-icon');
        row.append(severity, text, complete);
        return row;
    }));
}

function nextDue(record) {
    const wrap = element('div', null, 'next-due');
    if (!record.next_due_on && record.next_due_hours === null) { wrap.append(element('span', 'No repeat interval', 'small muted')); return wrap; }
    if (record.next_due_on) wrap.append(element('span', formatDate(record.next_due_on), 'mono'));
    if (record.next_due_hours !== null) wrap.append(element('span', `${formatNumber(record.next_due_hours)} h`, 'mono small muted'));
    return wrap;
}

function renderRecords(records, current) {
    document.querySelector('#record-count').textContent = plural(records.length, 'record');
    if (!records.length) return emptyRow(rows, 7, filter.value ? 'No maintenance recorded for this aircraft yet.' : 'No maintenance recorded yet. Record the last completed check for each aircraft to start receiving alerts.', 'clipboard');
    rows.replaceChildren(...records.map(record => {
        const row = element('tr'); cell(row, formatDate(record.performed_on));
        const plane = cell(row); plane.append(element('strong', record.aircraft.registration, 'mono'), element('div', record.aircraft.type ?? '', 'small muted'));
        const work = cell(row); work.append(element('strong', record.title), element('div', record.kind_label, 'small muted'));
        if (record.notes) work.append(element('div', record.notes, 'small muted'));
        cell(row, record.airframe_hours_at === null ? '—' : `${formatNumber(record.airframe_hours_at)} h`, 'mono');
        const dueCell = cell(row); dueCell.append(nextDue(record));
        const live = current.get(record.id); if (live) dueCell.append(chip(live.state === 'ok' ? 'Current' : live.state === 'overdue' ? 'Overdue' : 'Due soon', dueTone[live.state], { dot: true }));
        cell(row, record.recorded_by ?? '—', 'small');
        actions(row, () => controller.open(record), () => controller.remove(record));
        return row;
    }));
}

async function refresh() {
    loadingRow(rows, 7, 'Loading maintenance log…');
    const query = filter.value ? `?aircraft_id=${filter.value}` : '';
    const [records, alerts, fleet] = await Promise.all([allPages('/api/v1/maintenance-records' + query), api('/api/v1/maintenance-alerts'), allPages('/api/v1/aircraft')]);
    const current = new Map(fleet.flatMap(record => record.maintenance_due.map(item => [item.record.id, item])));
    renderAlerts(alerts.data); renderRecords(records, current);
    const data = await refreshShell({ refresh: true });
    if (data) {
        setKpi('maintenance.overdue', data.maintenance.overdue); setKpi('maintenance.due_soon', data.maintenance.due_soon);
        setKpi('fleet.maintenance', data.fleet.maintenance); setKpi('fleet.grounded', data.fleet.grounded);
    }
}

filter.addEventListener('change', () => {
    const url = new URL(window.location.href);
    if (filter.value) url.searchParams.set('aircraft', filter.value); else url.searchParams.delete('aircraft');
    window.history.replaceState(null, '', url);
    refresh().catch(showError);
});
document.querySelector('#add-record').addEventListener('click', () => controller.open());

(async () => {
    aircraft = await allPages('/api/v1/aircraft');
    options(form.elements.aircraft_id, aircraft, 'id', record => `${record.registration} · ${record.aircraft_type?.code ?? ''}`);
    for (const record of aircraft) { const option = element('option', record.registration); option.value = record.id; filter.append(option); }
    if (requested && aircraft.some(record => String(record.id) === requested)) filter.value = requested;
    const add = document.querySelector('#add-record');
    add.disabled = !aircraft.length; add.title = aircraft.length ? '' : 'Register an aircraft on the Fleet page first';
    await refresh();
})().catch(error => { alertList.replaceChildren(emptyState('Maintenance data could not be loaded', error.message || 'Reload the page to try again.', 'alert')); showError(error); });
