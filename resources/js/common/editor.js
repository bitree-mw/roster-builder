import { api } from './api';
import { confirmAction } from './confirm';
import { busy, showError, status } from './ui';

/**
 * Create/edit dialog controller. Success and failure pop-ups come from the API's configured messages;
 * validation details are also shown inline next to the form fields.
 */
export function editor({ form, dialog, endpoint, read, fill, refresh, label, title = record => record?.name || record?.code || record?.registration || record?.title }) {
    let id = null;
    const errorTarget = dialog.querySelector('[data-form-status]');
    function open(record = null, { copy = false } = {}) {
        id = copy ? null : record?.id ?? null; form.reset(); status('', false, errorTarget);
        for (const input of form.querySelectorAll('[aria-invalid]')) input.removeAttribute('aria-invalid');
        dialog.querySelector('[data-dialog-title]').textContent = copy ? `Copy ${label.toLowerCase()}` : record?.id ? `Edit ${label.toLowerCase()}` : `Add ${label.toLowerCase()}`;
        fill(record, { copy }); dialog.showModal();
    }
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        await busy(form.querySelector('[type="submit"]'), async () => {
            for (const input of form.querySelectorAll('[aria-invalid]')) input.removeAttribute('aria-invalid');
            try {
                await api(id ? `${endpoint}/${id}` : endpoint, { method: id ? 'PUT' : 'POST', body: read() });
                dialog.close(); await refresh();
            } catch (error) {
                showError(error, errorTarget);
                for (const field of Object.keys(error.errors || {})) form.elements[field.split('.')[0]]?.setAttribute?.('aria-invalid', 'true');
            }
        });
    });
    async function remove(record) {
        const name = title(record);
        const confirmed = await confirmAction({ title: `Remove ${name}?`, message: `This permanently removes the ${label.toLowerCase()} and cannot be undone.`, confirmLabel: 'Remove' });
        if (!confirmed) return;
        try { await api(`${endpoint}/${record.id}`, { method: 'DELETE' }); await refresh(); }
        catch (error) { showError(error); }
    }
    return { open, remove };
}
