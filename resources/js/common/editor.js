import { api } from './api';
import { busy, showError, status } from './ui';
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
            try {
                await api(id ? `${endpoint}/${id}` : endpoint, { method: id ? 'PUT' : 'POST', body: read() });
                dialog.close(); await refresh(); status(`${label} saved.`);
            } catch (error) {
                showError(error, errorTarget);
                for (const field of Object.keys(error.errors || {})) form.elements[field.split('.')[0]]?.setAttribute?.('aria-invalid', 'true');
            }
        });
    });
    async function remove(record) {
        if (!window.confirm(`Remove ${title(record)}? This cannot be undone.`)) return;
        try { await api(`${endpoint}/${record.id}`, { method: 'DELETE' }); await refresh(); status(`${label} removed.`); }
        catch (error) { showError(error); }
    }
    return { open, remove };
}
