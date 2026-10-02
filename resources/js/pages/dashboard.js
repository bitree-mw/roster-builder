/**
 * Operations dashboard (staff): readiness KPIs, the roster state of last/this/next weeks, live roster
 * conflicts, today's flying and the fleet, maintenance and document items that need attention. Everything
 * comes from GET /api/v1/dashboard in one request.
 */
import { api } from '../common/api';
import { conflictRow, coverageBar, RANKS, shortDate, weekRange, weekState } from '../common/roster';
import { chip, dueSummary, element, emptyState, formatDate, icon, plural, setKpi, setKpiTone, showError } from '../common/ui';

const SLOT_LABELS = { previous: 'Last week', current: 'This week', next: 'Next week', following: 'Week after' };
const DOCUMENT_LABELS = { licence: 'Licence', medical: 'Medical', recurrent: 'Recurrent training' };

/** Link into the roster window at a week (and optionally a trip). */
function rosterLink(weekStartsOn, tripId) {
    return `/roster?week=${weekStartsOn}${tripId ? `&trip=${tripId}` : ''}`;
}
/** A small secondary button-link. */
function linkButton(href, label, iconName = 'chevron-right') {
    const link = element('a', null, 'button button-sm button-secondary'); link.href = href; link.append(label, icon(iconName, 'icon-sm')); return link;
}

/** KPI strip from the overview counts plus this week's roster coverage and conflicts. */
function renderKpis(data) {
    const { overview } = data;
    const current = data.weeks.find(week => week.slot === 'current');
    if (current?.summary) {
        setKpi('roster.coverage', `${current.summary.filled}/${current.summary.seats}`, `${plural(current.summary.open, 'open seat')} · ${weekState(current).label}`);
        setKpiTone('roster.coverage', current.summary.open ? 'warning' : 'success');
    } else {
        setKpi('roster.coverage', '—', 'No roster for this week yet');
        setKpiTone('roster.coverage', 'warning');
    }
    const blocking = data.conflicts.filter(conflict => conflict.blocking).length;
    setKpi('roster.conflicts', data.conflict_total, blocking ? `${blocking} must be resolved before publishing` : 'Nothing blocks publishing');
    setKpiTone('roster.conflicts', blocking ? 'danger' : data.conflict_total ? 'warning' : 'success');
    setKpi('fleet.available', `${overview.fleet.available}/${overview.fleet.total}`, `${overview.fleet.maintenance} in maintenance · ${overview.fleet.grounded} grounded`);
    setKpiTone('fleet.available', overview.fleet.grounded ? 'danger' : 'success');
    setKpi('flights.enabled', overview.flights.enabled, `${overview.flights.disabled} disabled`);
    const maintenance = overview.maintenance.overdue + overview.maintenance.due_soon;
    setKpi('maintenance.alerts', maintenance, `${overview.maintenance.overdue} overdue · ${overview.maintenance.due_soon} due soon`);
    setKpiTone('maintenance.alerts', overview.maintenance.overdue ? 'danger' : maintenance ? 'warning' : 'success');
    const documents = overview.crew.documents_expired + overview.crew.documents_due_soon;
    setKpi('crew.documents', documents, `${overview.crew.documents_expired} expired · ${overview.crew.documents_due_soon} within window`);
    setKpiTone('crew.documents', overview.crew.documents_expired ? 'danger' : documents ? 'warning' : 'success');
}

/** One card per roster week: status, coverage, open seats and conflicts, with the next sensible action. */
function renderWeeks(weeks) {
    const container = document.querySelector('#week-cards'); container.setAttribute('aria-busy', 'false');
    container.replaceChildren(...weeks.map(week => {
        const state = weekState(week);
        const card = element('article', null, 'week-card'); card.dataset.state = state.key; if (week.slot === 'current') card.dataset.current = 'true';
        const head = element('div', null, 'week-card-head');
        const title = element('div'); title.append(element('span', SLOT_LABELS[week.slot], 'label-caps muted'), element('strong', `Week ${week.iso_week}`, 'week-card-title'), element('span', weekRange(week.starts_on), 'small muted mono'));
        head.append(title, chip(state.label, state.tone, { dot: true }));
        card.append(head);
        if (week.summary) {
            card.append(coverageBar(week.summary.filled, week.summary.seats));
            const facts = element('div', null, 'chip-list');
            facts.append(chip(plural(week.summary.trips, 'trip'), 'info'));
            facts.append(chip(plural(week.summary.open, 'open seat'), week.summary.open ? 'warning' : 'success'));
            facts.append(chip(plural(week.summary.blocking, 'conflict'), week.summary.blocking ? 'danger' : 'success'));
            card.append(facts);
        } else {
            card.append(element('p', week.slot === 'previous' ? 'No roster was made for this week.' : 'No roster yet. Create the week and let the generator build it from working hours and duty rules.', 'small muted'));
        }
        const action = week.slot === 'previous' || state.key === 'published' ? 'View roster' : state.key === 'missing' ? 'Create & build' : state.key === 'unbuilt' ? 'Build roster' : 'Review & edit';
        card.append(linkButton(rosterLink(week.starts_on), action));
        return card;
    }));
}

/** Live conflicts with a link straight to the affected trip in the roster window. */
function renderConflicts(data) {
    const list = document.querySelector('#conflict-list'); list.setAttribute('aria-busy', 'false');
    const count = document.querySelector('#conflict-count');
    count.textContent = plural(data.conflict_total, 'conflict');
    if (data.conflicts.some(conflict => conflict.blocking)) count.dataset.tone = 'danger'; else if (data.conflict_total) count.dataset.tone = 'warning'; else count.dataset.tone = 'success';
    if (!data.conflicts.length) return list.replaceChildren(emptyState('No roster conflicts', 'Every filled seat in this and next week passes the duty rules, and no flight or fleet problems were found.', 'shield'));
    const rows = data.conflicts.map(conflict => conflictRow(conflict, linkButton(rosterLink(conflict.week_starts_on, conflict.trip_id), 'Open')));
    if (data.conflict_total > data.conflicts.length) rows.push(element('p', `Showing ${data.conflicts.length} of ${data.conflict_total}. Open the roster window to see them all.`, 'small muted dashboard-more'));
    list.replaceChildren(...rows);
}

/** Today's trips in report order with a seat-fill chip. */
function renderToday(data) {
    document.querySelector('#today-date').textContent = formatDate(data.today);
    const list = document.querySelector('#today-list'); list.setAttribute('aria-busy', 'false');
    if (!data.today_trips.length) return list.replaceChildren(emptyState('No trips today', 'Nothing is rostered for today, or this week has no roster yet.', 'calendar'));
    const current = data.weeks.find(week => week.slot === 'current');
    list.replaceChildren(...data.today_trips.map(trip => {
        const link = element('a', null, `today-trip strip accent-${trip.palette || 'sky'}`); link.href = rosterLink(current.starts_on, trip.trip_id);
        const head = element('div', null, 'today-trip-head'); head.append(element('span', trip.code, 'code-tag'), element('span', trip.aircraft_type || '', 'small muted mono'));
        const times = element('span', `${trip.route || ''} · ${trip.report_local}–${trip.release_local} LT`, 'small mono');
        const seats = chip(`${trip.filled}/${trip.seats} crew`, trip.filled === trip.seats ? 'success' : 'warning', { iconName: 'users' });
        link.append(head, times, seats);
        return link;
    }));
}

/** Grounded/unavailable airframes, maintenance alerts and crew document alerts as attention rows. */
function renderAttention(data) {
    const list = document.querySelector('#attention-list'); list.setAttribute('aria-busy', 'false');
    const rows = [];
    for (const airframe of data.fleet_issues) {
        rows.push(attentionRow(airframe.status === 'grounded' ? 'danger' : 'warning', 'plane', `${airframe.registration} · ${airframe.aircraft_type} · ${airframe.status_label}`, airframe.status_reason || 'No reason recorded', '/aircraft', 'Fleet'));
    }
    for (const item of data.maintenance_alerts) {
        rows.push(attentionRow(item.state === 'overdue' ? 'danger' : 'warning', 'wrench', `${item.record.aircraft.registration} · ${item.record.kind_label} ${item.state === 'overdue' ? 'overdue' : 'due soon'}`, `${item.record.title} — ${dueSummary(item)}`, `/maintenance?aircraft=${item.record.aircraft_id}`, 'Maintenance'));
    }
    for (const document of data.document_alerts) {
        const when = document.state === 'expired' ? `expired ${plural(-document.days_remaining, 'day')} ago` : document.days_remaining === 0 ? 'expires today' : `expires in ${plural(document.days_remaining, 'day')}`;
        rows.push(attentionRow(document.state === 'expired' ? 'danger' : 'warning', 'id-card', `${document.name} · ${RANKS[document.rank]}`, `${DOCUMENT_LABELS[document.kind]} ${when} (${shortDate(document.expires_on)})`, '/crew', 'Crew'));
    }
    list.replaceChildren(...(rows.length ? rows : [emptyState('All clear', 'Every airframe is available, nothing is overdue or due soon, and no crew document is expiring.', 'shield')]));
}
/** One "needs attention" row with a link to the page that resolves it. */
function attentionRow(tone, iconName, title, detail, href, linkLabel) {
    const row = element('div', null, 'alert-row'); if (tone === 'danger') row.dataset.tone = 'danger';
    const severity = icon(iconName); severity.classList.add('alert-row-icon');
    const text = element('div', null, 'alert-row-text'); text.append(element('span', title, 'alert-row-title'), element('span', detail, 'alert-row-meta'));
    row.append(severity, text, linkButton(href, linkLabel));
    return row;
}

/** Load everything and render; on failure every busy region shows an error state. */
async function load() {
    const { data } = await api('/api/v1/dashboard');
    renderKpis(data); renderWeeks(data.weeks); renderConflicts(data); renderToday(data); renderAttention(data);
}
load().catch(error => {
    for (const selector of ['#week-cards', '#conflict-list', '#today-list', '#attention-list']) {
        const target = document.querySelector(selector); target.setAttribute('aria-busy', 'false');
        target.replaceChildren(emptyState('Dashboard could not be loaded', error.message || 'Reload the page to try again.', 'alert'));
    }
    showError(error);
});
