/**
 * Crew hours page (pages/hours.blade.php): accumulated hours per pilot or cabin crew member for this week,
 * this month, the last 28 days, this year and the last 12 months. Pilots lead with block (flying) hours,
 * cabin crew with duty hours; both show flown and still-scheduled time. All sums come from the server.
 */
import { api } from '../common/api';
import { chip, element, emptyRow, formatDate, formatMinutes, loadingRow, plural, segmented, setKpi, setKpiTone, showError } from '../common/ui';

const tbody = document.querySelector('#hours-rows');
const head = document.querySelector('#hours-head');
const foot = document.querySelector('#hours-totals');
let group = 'pilots';
let search = '';
let report = null;

/** Primary and secondary measures for the current group. */
function measures() {
    return group === 'pilots' ? { primary: 'block', secondary: 'duty', label: 'Block' } : { primary: 'duty', secondary: 'block', label: 'Duty' };
}

/**
 * One hours cell: flown primary hours (bold), the secondary measure, scheduled time still to come, and for
 * the month a bar against the monthly block limit.
 */
function hoursCell(hours, window) {
    const { primary, secondary } = measures();
    const td = element('td', null, 'hours-cell');
    td.append(element('strong', formatMinutes(hours[`${primary}_minutes`]), 'mono'));
    const secondaryScheduled = hours[`scheduled_${secondary}_minutes`];
    td.append(element('span', `${secondary} ${formatMinutes(hours[`${secondary}_minutes`])}${secondaryScheduled ? ` (+${formatMinutes(secondaryScheduled)})` : ''}`, 'small muted mono'));
    const scheduled = hours[`scheduled_${primary}_minutes`];
    if (scheduled) td.append(element('span', `+${formatMinutes(scheduled)} scheduled`, 'small mono hours-scheduled'));
    td.append(element('span', plural(hours.trips, 'trip'), 'small muted'));
    if (window === 'month') {
        const limit = report.max_block_month_h * 60;
        const total = hours.block_minutes + hours.scheduled_block_minutes;
        const bar = element('progress', null, 'coverage-bar hours-limit'); bar.max = limit; bar.value = Math.min(total, limit);
        bar.setAttribute('aria-label', `${formatMinutes(total)} block of the ${report.max_block_month_h}h monthly limit`);
        if (total >= 0.8 * limit) bar.dataset.near = 'true';
        td.append(bar);
    }
    return td;
}

/** Table header with each window's label and date range. */
function renderHead() {
    const cells = [element('th', 'Crew member')];
    for (const window of report.windows) {
        const th = element('th'); th.scope = 'col';
        th.append(element('span', window.label), element('span', `${formatDate(window.from)} – ${formatDate(window.to)}`, 'hours-range'));
        cells.push(th);
    }
    head.replaceChildren(...cells);
}

/** Crew rows (filtered by search) and the group totals row. */
function render() {
    const term = search.trim().toLowerCase();
    const rows = report.rows.filter(row => !term || row.crew.name.toLowerCase().includes(term) || row.crew.base_airport.toLowerCase().includes(term));
    document.querySelector('#hours-count').textContent = plural(rows.length, group === 'pilots' ? 'pilot' : 'cabin crew member', group === 'pilots' ? 'pilots' : 'cabin crew members');
    if (!rows.length) { emptyRow(tbody, report.windows.length + 1, report.rows.length ? 'No crew match this search.' : 'No crew in this group yet.', 'users'); foot.replaceChildren(); return; }
    tbody.replaceChildren(...rows.map(row => {
        const tr = element('tr');
        const who = element('th', null, 'hours-crew'); who.scope = 'row';
        who.append(element('strong', row.crew.name), element('span', `${row.crew.rank} · ${row.crew.base_airport}${row.crew.weekly_hours ? ` · ${row.crew.weekly_hours}h/week` : ''}`, 'small muted mono'));
        if (!row.crew.active) who.append(chip('Inactive', 'warning'));
        tr.append(who, ...report.windows.map(window => hoursCell(row.hours[window.key], window.key)));
        return tr;
    }));
    const total = element('tr'); const label = element('th', 'Group total'); label.scope = 'row';
    total.append(label, ...report.windows.map(window => hoursCell(report.totals[window.key], window.key === 'month' ? 'total' : window.key)));
    foot.replaceChildren(total);
}

/** Month KPIs for the group: totals, average, highest and how many are near the monthly limit. */
function renderKpis() {
    const month = report.totals.month;
    const people = Math.max(report.rows.length, 1);
    const limit = report.max_block_month_h * 60;
    setKpi('hours.block', formatMinutes(month.block_minutes), `+${formatMinutes(month.scheduled_block_minutes)} scheduled`);
    setKpi('hours.duty', formatMinutes(month.duty_minutes), `+${formatMinutes(month.scheduled_duty_minutes)} scheduled`);
    setKpi('hours.average', formatMinutes(Math.round(month.block_minutes / people)), `Block per ${group === 'pilots' ? 'pilot' : 'cabin crew member'}`);
    const ranked = [...report.rows].sort((a, b) => (b.hours.month.block_minutes + b.hours.month.scheduled_block_minutes) - (a.hours.month.block_minutes + a.hours.month.scheduled_block_minutes));
    const top = ranked[0];
    setKpi('hours.highest', top ? formatMinutes(top.hours.month.block_minutes + top.hours.month.scheduled_block_minutes) : '—', top ? top.crew.name : 'No hours yet');
    const near = report.rows.filter(row => row.hours.month.block_minutes + row.hours.month.scheduled_block_minutes >= 0.8 * limit).length;
    setKpi('hours.near', near, `Limit ${report.max_block_month_h}h block per month`);
    setKpiTone('hours.near', near ? 'warning' : 'success');
}

/** Load the report for the selected group. */
async function load() {
    loadingRow(tbody, 6, 'Loading hours…');
    report = (await api(`/api/v1/crew-hours?group=${group}`)).data;
    document.querySelector('#hours-note').textContent = `Only published weeks count. ${measures().label} hours lead for ${group === 'pilots' ? 'pilots' : 'cabin crew'}. Dates are base local; a duty counts on its report date.`;
    renderHead(); render(); renderKpis();
}

segmented(document.querySelector('#group-switch'), value => { group = value; load().catch(showError); });
document.querySelector('#hours-search').addEventListener('input', event => { search = event.target.value; if (report) render(); });
load().catch(error => { emptyRow(tbody, 6, 'Hours could not be loaded. Reload the page to try again.', 'alert'); showError(error); });
