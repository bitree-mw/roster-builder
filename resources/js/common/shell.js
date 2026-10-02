import { overview } from './overview';
import { plural } from './ui';

function tabCount(key, text, tone) {
    const target = document.querySelector(`[data-tab-count="${key}"]`); if (!target) return;
    target.textContent = text; target.hidden = text === '';
    if (tone) target.dataset.tone = tone; else delete target.dataset.tone;
}
function alertPill(key, text, visible) {
    const pill = document.querySelector(`[data-alert-pill="${key}"]`); if (!pill) return;
    pill.querySelector('[data-alert-pill-text]').textContent = text; pill.hidden = !visible;
}

/** Update navigation counts and header alert pills from the staff overview. */
export async function refreshShell(options) {
    if (!document.querySelector('[data-tab-count]')) return null;
    const data = await overview(options);
    const maintenanceAlerts = data.maintenance.overdue + data.maintenance.due_soon;
    const documentAlerts = data.crew.documents_expired + data.crew.documents_due_soon;
    tabCount('flights', `${data.flights.enabled} on`);
    tabCount('aircraft', `${data.fleet.available}/${data.fleet.total}`, data.fleet.grounded ? 'danger' : undefined);
    tabCount('maintenance', maintenanceAlerts ? String(maintenanceAlerts) : '', data.maintenance.overdue ? 'danger' : 'warning');
    tabCount('crew', String(data.crew.active), documentAlerts ? 'warning' : undefined);
    alertPill('maintenance', data.maintenance.overdue ? `${data.maintenance.overdue} overdue` : `${plural(data.maintenance.due_soon, 'check')} due`, maintenanceAlerts > 0);
    alertPill('documents', plural(documentAlerts, 'document alert'), documentAlerts > 0);
    return data;
}
