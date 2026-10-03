/**
 * Admin settings / Airports page (pages/airports.blade.php, administrators only): the airports flight routes
 * can use (e.g. Entebbe, EBB) and which are crew bases, with counts, search and the add/edit dialog. The code
 * is fixed once an airport exists; airports in use cannot be removed. The server enforces all of this.
 */
import { api } from '../common/api';
import { editor } from '../common/editor';
import { chip, element, emptyRow, iconButton, loadingRow, plural, setKpi, showError } from '../common/ui';

const form = document.querySelector('#airport-form');
const tbody = document.querySelector('#airport-rows');
// Page state: airports from the API, the search text and the airport being edited (null when adding).
let airports = [];
let search = '';
let editing = null;

/** "UTC+3", "UTC-1", "UTC+5:45" from an offset in minutes. */
function utcLabel(minutes) {
    const sign = minutes < 0 ? '-' : '+'; const value = Math.abs(minutes);
    return `UTC${sign}${Math.floor(value / 60)}${value % 60 ? `:${String(value % 60).padStart(2, '0')}` : ''}`;
}

/** What uses an airport: route legs and crew based there (anything listed blocks removal). */
function usage(airport) {
    return [airport.leg_count ? plural(airport.leg_count, 'route leg') : null, airport.crew_count ? plural(airport.crew_count, 'crew member') : null].filter(Boolean);
}

/**
 * Add/edit dialog. The code is only sent for a new airport: once it exists, flight routes and crew bases refer
 * to it, so it is shown read-only. The offset is entered in hours and sent in minutes.
 */
const controller = editor({
    form, dialog: document.querySelector('#airport-dialog'), endpoint: '/api/v1/airports', label: 'Airport', refresh,
    title: record => `${record.code} (${record.name})`,
    read: () => ({
        ...(editing ? {} : { code: form.elements.code.value }),
        name: form.elements.name.value,
        utc_offset_minutes: Math.round(Number(form.elements.utc_offset_hours.value) * 60),
        is_base: form.elements.is_base.checked,
    }),
    fill: record => {
        editing = record;
        form.elements.code.readOnly = Boolean(record);
        if (!record) return;
        form.elements.code.value = record.code; form.elements.name.value = record.name;
        form.elements.utc_offset_hours.value = String(record.utc_offset_minutes / 60);
        form.elements.is_base.checked = record.is_base;
    },
});

/** Airport table for the search text. Remove is disabled while anything uses the airport. */
function render() {
    const term = search.trim().toLowerCase();
    const rows = airports.filter(airport => !term || airport.code.toLowerCase().includes(term) || airport.name.toLowerCase().includes(term));
    document.querySelector('#airport-count').textContent = `${rows.length} of ${plural(airports.length, 'airport')}`;
    if (!rows.length) return emptyRow(tbody, 6, airports.length ? 'No airports match this search.' : 'No airports yet. Add the first one.', 'route');
    tbody.replaceChildren(...rows.map(airport => {
        const row = element('tr');
        const code = element('td'); code.append(element('strong', airport.code, 'mono'));
        row.append(code, element('td', airport.name), element('td', utcLabel(airport.utc_offset_minutes), 'mono small'));
        const base = element('td'); base.append(airport.is_base ? chip('Crew base', 'info') : element('span', 'Outstation', 'muted small')); row.append(base);
        const uses = usage(airport);
        row.append(element('td', uses.join(' · ') || 'Not used yet', 'small muted'));
        const tools = element('td'); const wrap = element('div', null, 'row-actions');
        const remove = iconButton('trash', `Remove ${airport.code}`, () => controller.remove(airport), 'danger');
        if (uses.length) { remove.disabled = true; remove.title = `${airport.code} is in use and cannot be removed`; }
        wrap.append(iconButton('pencil', `Edit ${airport.code}`, () => controller.open(airport)), remove);
        tools.append(wrap); row.append(tools);
        return row;
    }));
}

/** Counts for the KPI strip. */
function renderKpis() {
    const bases = airports.filter(airport => airport.is_base).length;
    setKpi('airports.total', airports.length);
    setKpi('airports.bases', bases);
    setKpi('airports.outstations', airports.length - bases);
    setKpi('airports.unused', airports.filter(airport => !usage(airport).length).length);
}

/** Reload airports (after any change) and redraw. */
async function refresh() {
    loadingRow(tbody, 6, 'Loading airports…');
    airports = (await api('/api/v1/airports')).data;
    render(); renderKpis();
}

// Search, the add button, then the initial load.
document.querySelector('#airport-search').addEventListener('input', event => { search = event.target.value; render(); });
document.querySelector('#add-airport').addEventListener('click', () => controller.open());
refresh().then(() => { document.querySelector('#add-airport').disabled = false; })
    .catch(error => { emptyRow(tbody, 6, 'Airports could not be loaded. Reload the page to try again.', 'alert'); showError(error); });
