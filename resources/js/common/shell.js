import { overview } from './overview';
import { plural } from './ui';

/** Set (or hide, when empty) the count badge on a navigation tab. */
function tabCount(key, text, tone) {
    const target = document.querySelector(`[data-tab-count="${key}"]`); if (!target) return;
    target.textContent = text; target.hidden = text === '';
    if (tone) target.dataset.tone = tone; else delete target.dataset.tone;
}
/** Show or hide a header alert pill (crew documents). */
function alertPill(key, text, visible) {
    const pill = document.querySelector(`[data-alert-pill="${key}"]`); if (!pill) return;
    pill.querySelector('[data-alert-pill-text]').textContent = text; pill.hidden = !visible;
}

/** Update navigation counts and header alert pills from the staff overview. */
export async function refreshShell(options) {
    if (!document.querySelector('[data-tab-count]')) return null;
    const data = await overview(options);
    const documentAlerts = data.crew.documents_expired + data.crew.documents_due_soon;
    tabCount('flights', `${data.flights.enabled} on`);
    tabCount('aircraft', `${data.fleet.available}/${data.fleet.total}`, data.fleet.available < data.fleet.total ? 'warning' : undefined);
    tabCount('crew', String(data.crew.active), documentAlerts ? 'warning' : undefined);
    alertPill('documents', plural(documentAlerts, 'document alert'), documentAlerts > 0);
    return data;
}
