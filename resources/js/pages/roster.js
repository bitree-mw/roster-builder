/**
 * Roster workspace: readiness KPIs and "needs attention" (staff), and the monthly roster period with
 * month navigation. Crew accounts only ever receive their own published roster from the API.
 */
import { api } from '../common/api';
import { overview } from '../common/overview';
import { busy, chip, dueSummary, element, emptyState, formatDate, icon, plural, setKpi, setKpiTone, showError } from '../common/ui';

const staff = ['scheduler', 'crew_control'].includes(document.body.dataset.role);
const month = document.querySelector('#month');
const content = document.querySelector('#roster-content');
const create = document.querySelector('#create-period');
const state = document.querySelector('#period-state');
const today = new Date();

// Month choices: six months back to 24 months ahead (the same window the API accepts).
for (let offset = -6; offset <= 24; offset++) {
    const date = new Date(today.getFullYear(), today.getMonth() + offset, 1);
    const value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    const option = element('option', date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })); option.value = value; option.selected = offset === 0; month.append(option);
}
/** Move the month selector backwards or forwards. */
function step(delta) {
    const index = month.selectedIndex + delta;
    if (index < 0 || index >= month.options.length) return;
    month.selectedIndex = index; refresh().catch(showError);
}
/** Disable the arrows at either end of the month window. */
function updateStepButtons() {
    document.querySelector('#previous-month').disabled = month.selectedIndex === 0;
    document.querySelector('#next-month').disabled = month.selectedIndex === month.options.length - 1;
}

/** Group the period's trips by date and render them as flight strips with open/flagged seat chips. */
function renderTrips(period) {
    const byDate = Map.groupBy ? Map.groupBy(period.trips, trip => trip.start_date) : period.trips.reduce((map, trip) => map.set(trip.start_date, [...(map.get(trip.start_date) || []), trip]), new Map());
    const nodes = [];
    for (const [date, trips] of byDate) {
        const day = element('section', null, 'trip-day'); day.append(element('h3', formatDate(date), 'trip-day-title mono'));
        const strips = element('div', null, 'trip-strips');
        for (const trip of trips) {
            const strip = element('article', null, 'strip');
            const head = element('div', null, 'trip-strip-head'); head.append(element('span', trip.schedule?.code || 'Trip'), element('span', plural(trip.assignments.length, 'seat'), 'small muted'));
            strip.append(head);
            const flagged = trip.assignments.filter(assignment => assignment.flag_reasons?.length).length;
            const open = trip.assignments.filter(assignment => !assignment.crew_member_id).length;
            const chips = element('div', null, 'chip-list');
            if (open) chips.append(chip(`${open} open`, 'warning'));
            if (flagged) chips.append(chip(`${flagged} flagged`, 'danger'));
            if (chips.childElementCount) strip.append(chips);
            strips.append(strip);
        }
        day.append(strips); nodes.push(day);
    }
    content.replaceChildren(...nodes);
}

/** Load the selected month's period and show its trips or an honest empty state (no planner yet). */
async function refresh() {
    updateStepButtons();
    content.setAttribute('aria-busy', 'true'); state.textContent = 'Loading'; delete state.dataset.tone;
    const { data } = await api('/api/v1/roster-periods?month=' + month.value); const period = data[0];
    document.querySelector('#period-title').textContent = month.selectedOptions[0].textContent;
    state.textContent = period ? period.status === 'published' ? 'Published' : 'Draft' : 'Not created';
    if (period) state.dataset.tone = period.status === 'published' ? 'success' : 'info';
    if (create) create.hidden = Boolean(period);
    content.setAttribute('aria-busy', 'false');
    if (period?.trips.length) return renderTrips(period);
    if (!staff) return content.replaceChildren(emptyState('No published roster for this month', 'Your personal roster appears here once crew control publishes it.', 'calendar'));
    const empty = period
        ? emptyState('Draft period saved', 'This period holds a snapshot of the current duty rules. Automatic assignment, manual day planning and publication are the next implementation milestones, so no duties are shown yet.', 'calendar')
        : emptyState('No roster period for this month', 'Prepare flight patterns, fleet and crew, then create a draft period to hold this month’s roster.', 'calendar');
    content.replaceChildren(empty);
}

/** Staff only: fill KPIs and list grounded/unavailable airframes, maintenance alerts and document alerts. */
async function loadReadiness() {
    const [data, alerts, fleet] = await Promise.all([overview(), api('/api/v1/maintenance-alerts'), api('/api/v1/aircraft')]);
    const maintenanceAlerts = data.maintenance.overdue + data.maintenance.due_soon;
    const documentAlerts = data.crew.documents_expired + data.crew.documents_due_soon;
    setKpi('crew.active', data.crew.active, `${data.crew.captains} CPT · ${data.crew.first_officers} FO · ${data.crew.cabin} CC`);
    setKpi('flights.enabled', data.flights.enabled, `${data.flights.disabled} disabled`);
    setKpi('fleet.available', `${data.fleet.available}/${data.fleet.total}`, `${data.fleet.maintenance} in maintenance · ${data.fleet.grounded} grounded`);
    setKpiTone('fleet.available', data.fleet.grounded ? 'danger' : 'success');
    setKpi('maintenance.alerts', maintenanceAlerts, `${data.maintenance.overdue} overdue · ${data.maintenance.due_soon} due soon`);
    setKpiTone('maintenance.alerts', data.maintenance.overdue ? 'danger' : maintenanceAlerts ? 'warning' : 'success');
    setKpi('crew.documents', documentAlerts, `${data.crew.documents_expired} expired · ${data.crew.documents_due_soon} within window`);
    setKpiTone('crew.documents', data.crew.documents_expired ? 'danger' : documentAlerts ? 'warning' : 'success');

    const list = document.querySelector('#attention-list'); list.setAttribute('aria-busy', 'false');
    const rows = [];
    for (const airframe of fleet.data.filter(record => record.status !== 'available')) {
        rows.push(attentionRow(airframe.status === 'grounded' ? 'danger' : 'warning', 'plane', `${airframe.registration} · ${airframe.status_label}`, airframe.status_reason || 'No reason recorded', '/aircraft', 'Fleet'));
    }
    for (const item of alerts.data.slice(0, 6)) {
        rows.push(attentionRow(item.state === 'overdue' ? 'danger' : 'warning', 'wrench', `${item.record.aircraft.registration} · ${item.record.kind_label} ${item.state === 'overdue' ? 'overdue' : 'due soon'}`, `${item.record.title} — ${dueSummary(item)}`, `/maintenance?aircraft=${item.record.aircraft_id}`, 'Maintenance'));
    }
    if (documentAlerts) rows.push(attentionRow(data.crew.documents_expired ? 'danger' : 'warning', 'id-card', `${plural(documentAlerts, 'crew document')} need attention`, `${data.crew.documents_expired} expired · ${data.crew.documents_due_soon} expiring soon`, '/crew', 'Crew'));
    list.replaceChildren(...(rows.length ? rows : [emptyState('All clear', 'Every airframe is available and nothing is overdue or due soon.', 'shield')]));
}
/** One "needs attention" row with a link to the page that resolves it. */
function attentionRow(tone, iconName, title, detail, href, linkLabel) {
    const row = element('div', null, 'alert-row'); if (tone === 'danger') row.dataset.tone = 'danger';
    const severity = icon(iconName); severity.classList.add('alert-row-icon');
    const text = element('div', null, 'alert-row-text'); text.append(element('span', title, 'alert-row-title'), element('span', detail, 'alert-row-meta'));
    const link = element('a', null, 'button button-sm button-secondary'); link.href = href; link.append(linkLabel, icon('chevron-right', 'icon-sm'));
    row.append(severity, text, link); return row;
}

// Month navigation and draft creation (the server confirms creation with a pop-up).
month.addEventListener('change', () => refresh().catch(showError));
document.querySelector('#previous-month').addEventListener('click', () => step(-1));
document.querySelector('#next-month').addEventListener('click', () => step(1));
create?.addEventListener('click', async event => {
    await busy(event.currentTarget, async () => {
        try { await api('/api/v1/roster-periods', { method: 'POST', body: { month: month.value } }); await refresh(); }
        catch (error) { showError(error); }
    });
});
refresh().catch(error => { content.replaceChildren(emptyState('Roster could not be loaded', error.message || 'Reload the page to try again.', 'alert')); showError(error); });
if (staff) loadReadiness().catch(error => { document.querySelector('#attention-list').replaceChildren(emptyState('Readiness could not be loaded', error.message || 'Reload the page to try again.', 'alert')); });
