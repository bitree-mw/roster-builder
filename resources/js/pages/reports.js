/**
 * Reports page (pages/reports.blade.php): choose a report, set the period (and crew group for hours), view
 * it as a table with summary figures, and download the same report as CSV or PDF. All figures come from
 * the server (ReportService); this script only lays them out.
 */
import { api, download } from '../common/api';
import { addDays } from '../common/roster';
import { busy, element, emptyRow, loadingRow, plural, showError, status } from '../common/ui';

const filters = document.querySelector('#report-filters');
const head = document.querySelector('#report-head');
const tbody = document.querySelector('#report-rows');
const csvButton = document.querySelector('#report-csv');
const pdfButton = document.querySelector('#report-pdf');
// Reports where the period does not apply (they describe the state as of today).
const POINT_IN_TIME = ['maintenance'];
let types = [];
let selected = new URLSearchParams(window.location.search).get('type') || 'coverage';

/** Query string for the current filters. */
function query() {
    const params = new URLSearchParams({ from: filters.elements.from.value, to: filters.elements.to.value });
    if (selected === 'hours' && filters.elements.group.value) params.set('group', filters.elements.group.value);
    return params.toString();
}

/** Report chooser buttons; the selected one is pressed. */
function renderTypes() {
    const container = document.querySelector('#report-types'); container.setAttribute('aria-busy', 'false');
    container.replaceChildren(...types.map(type => {
        const button = element('button', type.title, 'report-type'); button.type = 'button';
        button.setAttribute('aria-pressed', String(type.key === selected));
        button.addEventListener('click', () => { selected = type.key; renderTypes(); run().catch(showError); });
        return button;
    }));
    document.querySelector('#group-field').hidden = selected !== 'hours';
}

/** Load the selected report and draw it. */
async function run() {
    if (filters.elements.from.value > filters.elements.to.value) return status('The end date must be on or after the start date.', true);
    const url = new URL(window.location.href); url.searchParams.set('type', selected); window.history.replaceState(null, '', url);
    csvButton.disabled = true; pdfButton.disabled = true;
    loadingRow(tbody, Math.max(head.childElementCount, 1), 'Running report…');
    const { data } = await api(`/api/v1/reports/${selected}?${query()}`);
    document.querySelector('#report-title').textContent = data.title;
    document.querySelector('#report-period').textContent = POINT_IN_TIME.includes(selected) ? 'As of today' : data.period;
    document.querySelector('#report-description').textContent = data.description;
    document.querySelector('#report-notes').textContent = data.notes || 'Dates are base local.';
    const summary = document.querySelector('#report-summary');
    summary.replaceChildren(...Object.entries(data.summary).map(([label, value]) => {
        const item = element('div', null, 'report-figure'); item.append(element('span', label, 'label-caps muted'), element('strong', String(value), 'mono')); return item;
    }));
    head.replaceChildren(...data.columns.map(column => { const th = element('th', column.label); th.scope = 'col'; if (column.align === 'right') th.dataset.align = 'right'; return th; }));
    if (!data.rows.length) emptyRow(tbody, data.columns.length, 'Nothing to report for this period.', 'clipboard');
    else {
        tbody.replaceChildren(...data.rows.map(row => {
            const tr = element('tr');
            for (const column of data.columns) {
                const td = element('td', row[column.key] ?? '');
                if (column.align === 'right') td.dataset.align = 'right';
                // Tone is shown as a coloured word, never colour alone: the cell text already says the state.
                if (row._tone?.[column.key]) td.dataset.tone = row._tone[column.key];
                tr.append(td);
            }
            return tr;
        }));
    }
    document.querySelector('#report-title').append(element('span', ` · ${plural(data.rows.length, 'row')}`, 'small muted'));
    csvButton.disabled = false; pdfButton.disabled = false;
}

filters.addEventListener('submit', event => { event.preventDefault(); busy(filters.querySelector('[type="submit"]'), () => run().catch(showError)); });
filters.elements.group.addEventListener('change', () => run().catch(showError));
csvButton.addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/reports/${selected}/export.csv?${query()}`, 'report.csv').catch(() => {})));
pdfButton.addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/reports/${selected}/export.pdf?${query()}`, 'report.pdf').catch(() => {})));

// Load the report list and default to the current calendar month at base.
(async () => {
    const { data } = await api('/api/v1/reports');
    types = data.types;
    if (!types.some(type => type.key === selected)) selected = 'coverage';
    const [year, month] = data.today.split('-');
    filters.elements.from.value = `${year}-${month}-01`;
    filters.elements.to.value = addDays(`${month === '12' ? Number(year) + 1 : year}-${month === '12' ? '01' : String(Number(month) + 1).padStart(2, '0')}-01`, -1);
    renderTypes();
    await run();
})().catch(error => { emptyRow(tbody, 1, 'Reports could not be loaded. Reload the page to try again.', 'alert'); showError(error); });
