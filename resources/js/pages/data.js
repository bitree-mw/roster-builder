/**
 * Import & backup page (pages/data.blade.php).
 *
 * Import: choose the kind and mode, preview the CSV (every row is checked by the server and nothing is
 * saved), then import the previewed rows. Templates show the expected headings.
 * Backup (administrators): download a JSON backup; restore by checking a file, comparing record counts and
 * typing RESTORE. The server enforces every permission and rule; this script only drives the steps.
 */
import { api, download, upload } from '../common/api';
import { confirmAction } from '../common/confirm';
import { refreshShell } from '../common/shell';
import { busy, chip, element, showError, status, toast } from '../common/ui';

const admin = document.querySelector('#data-root').dataset.admin === 'true';
const form = document.querySelector('#import-form');
const preview = document.querySelector('#import-preview');
const commitButton = document.querySelector('#commit-import');
const ACTIONS = {
    create: ['New', 'success'], update: ['Update', 'info'], unchanged: ['No change', undefined], error: ['Error', 'danger'],
    deactivate: ['Deactivate', 'warning'], disable: ['Disable', 'warning'], delete: ['Remove', 'warning'],
};
const REPLACE_HELP = {
    crew: 'Also deactivate crew that are not in the file (their history is kept).',
    flights: 'Also disable flight patterns that are not in the file (kept on file, not planned).',
    activities: 'Also remove each listed crew member\'s existing planning in the dates the file covers for them.',
};
let token = null;

/** Show the options that apply to the chosen kind. */
function renderKind() {
    const kind = form.elements.kind.value;
    document.querySelector('#times-field').hidden = kind !== 'flights';
    form.querySelector('[data-replace-help]').textContent = REPLACE_HELP[kind];
    resetPreview();
}

/** Forget a preview when the choices change. */
function resetPreview() { token = null; preview.hidden = true; commitButton.disabled = true; }

/** Draw the previewed rows and the summary; the Import button is enabled only without errors. */
function renderPreview(data) {
    token = data.token;
    preview.hidden = false;
    const summary = Object.entries(data.summary).map(([action, count]) => chip(`${count} ${ACTIONS[action][0].toLowerCase()}`, ACTIONS[action][1]));
    document.querySelector('#import-summary').replaceChildren(...summary);
    const errors = data.summary.error ?? 0;
    const changes = ['create', 'update', 'deactivate', 'disable', 'delete'].reduce((total, action) => total + (data.summary[action] ?? 0), 0);
    commitButton.disabled = errors > 0 || changes === 0;
    commitButton.textContent = errors ? `Fix ${errors} error${errors === 1 ? '' : 's'} first` : changes ? `Import ${changes} change${changes === 1 ? '' : 's'}` : 'Nothing to import';
    // Errors first so they are seen; then the file order.
    const rows = [...data.rows].sort((a, b) => Number(b.action === 'error') - Number(a.action === 'error') || a.line - b.line);
    document.querySelector('#import-rows').replaceChildren(...rows.map(row => {
        const tr = element('tr'); if (row.action === 'error') tr.dataset.error = 'true';
        const result = element('td'); result.append(chip(ACTIONS[row.action][0], ACTIONS[row.action][1]));
        const notes = element('td'); for (const message of row.messages) notes.append(element('div', message, 'small'));
        tr.append(element('td', row.line ? String(row.line) : '—', 'mono small'), result, element('td', row.label), notes);
        return tr;
    }));
}

form.elements.kind.addEventListener('change', renderKind);
form.addEventListener('change', event => { if (['mode', 'times', 'file'].includes(event.target.name)) resetPreview(); });
form.addEventListener('submit', event => {
    event.preventDefault();
    if (!form.elements.file.files.length) return status('Choose a CSV file first.', true);
    busy(form.querySelector('[type="submit"]'), async () => {
        const data = new FormData();
        for (const name of ['kind', 'mode', 'times']) data.append(name, form.elements[name].value);
        data.append('file', form.elements.file.files[0]);
        // The preview message is a warning when rows need fixing, so it is shown here rather than as a success pop-up.
        try {
            const response = await upload('/api/v1/imports/preview', data, { notify: false });
            renderPreview(response.data);
            toast(response.message, { type: response.data.summary.error ? 'warning' : 'info', title: 'Preview ready' });
        } catch (error) { showError(error); }
    });
});
document.querySelector('#download-template').addEventListener('click', event => busy(event.currentTarget, () => download(`/api/v1/imports/templates/${form.elements.kind.value}`, 'template.csv').catch(() => {})));
document.querySelector('#discard-import').addEventListener('click', resetPreview);
commitButton.addEventListener('click', event => busy(event.currentTarget, async () => {
    try {
        await api('/api/v1/imports/commit', { method: 'POST', body: { token } });
        resetPreview(); form.elements.file.value = '';
        refreshShell({ refresh: true }).catch(() => {});
    } catch (error) { showError(error); }
}));
renderKind();

/* ---------------------------------------------------------------- Backup and restore (administrators) */

if (admin) {
    const restoreForm = document.querySelector('#restore-form');
    const check = document.querySelector('#restore-check');
    const confirmForm = document.querySelector('#restore-confirm');
    let restoreToken = null;

    document.querySelector('#download-backup').addEventListener('click', event => busy(event.currentTarget, () => download('/api/v1/backups/download', 'roster-backup.json').catch(() => {})));

    // Step 1: check the file and compare record counts with the current data.
    restoreForm.addEventListener('submit', event => {
        event.preventDefault();
        if (!restoreForm.elements.file.files.length) return status('Choose a backup file first.', true);
        busy(restoreForm.querySelector('[type="submit"]'), async () => {
            const data = new FormData(); data.append('file', restoreForm.elements.file.files[0]);
            try {
                const { data: result } = await upload('/api/v1/backups/inspect', data);
                restoreToken = result.token;
                document.querySelector('#restore-source').textContent = `Backup made ${result.created_at ? result.created_at.replace('T', ' ').slice(0, 16) + ' UTC' : 'at an unknown time'}${result.created_by ? ` by ${result.created_by}` : ''}.`;
                document.querySelector('#restore-rows').replaceChildren(...result.tables.map(table => {
                    const tr = element('tr');
                    tr.append(element('td', table.table.replaceAll('_', ' ')), element('td', String(table.backup), 'mono'), element('td', String(table.current), 'mono'));
                    if (table.backup !== table.current) tr.dataset.changed = 'true';
                    return tr;
                }));
                check.hidden = false; confirmForm.elements.confirmation.value = ''; confirmForm.elements.confirmation.focus();
            } catch (error) { showError(error); }
        });
    });

    // Step 2: typed confirmation, then a last explicit confirmation dialog.
    confirmForm.addEventListener('submit', event => {
        event.preventDefault();
        if (confirmForm.elements.confirmation.value !== 'RESTORE') return status('Type RESTORE in capitals to confirm.', true);
        busy(confirmForm.querySelector('[type="submit"]'), async () => {
            const confirmed = await confirmAction({ title: 'Replace all operational data?', message: 'Fleet, crew, flights, rules and rosters are replaced by the backup. Changes made since the backup are lost. Accounts are kept.', confirmLabel: 'Restore' });
            if (!confirmed) return;
            try {
                await api('/api/v1/backups/restore', { method: 'POST', body: { token: restoreToken, confirmation: 'RESTORE' } });
                check.hidden = true; restoreForm.reset(); restoreToken = null;
                refreshShell({ refresh: true }).catch(() => {});
            } catch (error) { showError(error); }
        });
    });
}
