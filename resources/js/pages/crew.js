/**
 * Crew directory page: searchable, filterable crew table with rating chips and document expiry states
 * (states are calculated by the server), plus the create/edit dialog.
 */
import { api, allPages } from '../common/api';
import { editor } from '../common/editor';
import { overview } from '../common/overview';
import { refreshShell } from '../common/shell';
import { actions, cell, chip, element, emptyRow, formatDate, loadingRow, options, plural, segmented, setKpi, showError } from '../common/ui';

const form = document.querySelector('#crew-form');
const tbody = document.querySelector('#crew-rows');
const RANKS = { CPT: 'Captain', FO: 'First officer', CC: 'Cabin crew' };
const KINDS = ['licence', 'medical', 'recurrent'];
// Page state: loaded crew, the rank filter (applied by the API) and the search text (applied locally).
let crew = [];
let rank = '';
let search = '';

// Create/edit dialog. Documents left blank are simply not sent ("not recorded").
const controller = editor({
    form, dialog: document.querySelector('#crew-dialog'), endpoint: '/api/v1/crew-members', label: 'Crew member', refresh,
    read: () => ({
        name: form.elements.name.value, email: form.elements.email.value || null, rank: form.elements.rank.value,
        base_airport: form.elements.base_airport.value, active: form.elements.active.checked, all_aircraft: form.elements.all_aircraft.checked,
        rating_ids: [...form.elements.rating_ids.selectedOptions].map(option => Number(option.value)),
        documents: KINDS.filter(kind => form.elements[kind].value).map(kind => ({ kind, expires_on: form.elements[kind].value })),
    }),
    fill: record => {
        if (!record) return;
        for (const key of ['name', 'email', 'rank', 'base_airport']) form.elements[key].value = record[key] || '';
        for (const key of ['active', 'all_aircraft']) form.elements[key].checked = record[key];
        for (const option of form.elements.rating_ids.options) option.selected = record.rating_ids.includes(Number(option.value));
        for (const document of record.documents) form.elements[document.kind].value = document.expires_on;
    },
});

/** Up to two initials for the avatar. */
function initials(name) { return name.split(' ').filter(Boolean).slice(0, 2).map(part => part[0].toUpperCase()).join(''); }
/** A document column: expiry date plus an expired / days-left chip from the server-calculated state. */
function documentCell(record, kind) {
    const wrap = element('div', null, 'document-cell');
    const document = record.documents.find(item => item.kind === kind);
    if (!document) { wrap.append(chip('Not recorded', undefined)); return wrap; }
    wrap.append(element('span', formatDate(document.expires_on), 'mono small'));
    if (document.state === 'expired') wrap.append(chip(`Expired ${-document.days_remaining}d ago`, 'danger', { iconName: 'alert' }));
    else if (document.state === 'due_soon') wrap.append(chip(document.days_remaining === 0 ? 'Expires today' : `${document.days_remaining}d left`, 'warning', { iconName: 'clock' }));
    return wrap;
}
const hasAlert = record => record.documents.some(document => document.state !== 'valid');

/** Render the table for the current search text and "document alerts only" option. */
function render() {
    const term = search.trim().toLowerCase();
    const alertsOnly = document.querySelector('#alerts-only').checked;
    const visible = crew.filter(record => (!term || [record.name, record.email, record.base_airport].some(value => value?.toLowerCase().includes(term))) && (!alertsOnly || hasAlert(record)));
    document.querySelector('#crew-count').textContent = `${visible.length} of ${plural(crew.length, 'member')}`;
    if (!visible.length) return emptyRow(tbody, 9, crew.length ? 'No crew members match this view.' : 'No crew members yet. Add your first crew member.', 'users');
    tbody.replaceChildren(...visible.map(record => {
        const row = element('tr'); row.dataset.active = String(record.active);
        const identity = element('div', null, 'crew-identity'); const text = element('div');
        text.append(element('strong', record.name), element('span', record.email || 'No email recorded', 'small muted'));
        identity.append(element('span', initials(record.name), 'avatar avatar-sm'), text); cell(row).append(identity);
        cell(row).append(chip(record.rank, record.rank === 'CC' ? 'info' : 'brand'), element('div', RANKS[record.rank], 'small muted'));
        cell(row, record.base_airport, 'mono');
        const ratings = element('div', null, 'chip-list');
        if (record.all_aircraft) ratings.append(chip('All aircraft', 'info'));
        else if (!record.ratings.length) ratings.append(chip('No ratings', 'warning'));
        else ratings.append(...record.ratings.map(rating => element('span', rating.code, `chip palette-${rating.palette}`)));
        cell(row).append(ratings);
        for (const kind of KINDS) cell(row).append(documentCell(record, kind));
        cell(row).append(chip(record.active ? 'Active' : 'Inactive', record.active ? 'success' : undefined, { dot: true }));
        actions(row, () => controller.open(record), () => controller.remove(record));
        return row;
    }));
}

/** Reload crew for the selected rank and update the KPI strip from the overview counts. */
async function refresh() {
    loadingRow(tbody, 9, 'Loading crew…');
    crew = await allPages('/api/v1/crew-members' + (rank ? `?rank=${rank}` : ''));
    render();
    const data = await refreshShell({ refresh: true }) ?? await overview();
    for (const key of ['active', 'captains', 'first_officers', 'cabin', 'documents_expired', 'documents_due_soon']) setKpi(`crew.${key}`, data.crew[key]);
}

// Wire up filters and buttons, load dropdown lookups, then load the crew.
segmented(document.querySelector('#rank-filter'), value => { rank = value; refresh().catch(showError); });
document.querySelector('#crew-search').addEventListener('input', event => { search = event.target.value; render(); });
document.querySelector('#alerts-only').addEventListener('change', render);
document.querySelector('#add-crew').addEventListener('click', () => controller.open());
(async () => {
    const { data } = await api('/api/v1/lookups');
    options(form.elements.base_airport, data.airports.filter(airport => airport.is_base), 'code', 'code');
    options(form.elements.rating_ids, data.aircraft_types);
    document.querySelector('#add-crew').disabled = false;
    await refresh();
})().catch(error => { emptyRow(tbody, 9, 'Crew could not be loaded. Reload the page to try again.', 'alert'); showError(error); });
