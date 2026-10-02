import { api, csrf } from './common/api';
import { refreshShell } from './common/shell';
import { flash, toast } from './common/toast';
import { showError } from './common/ui';

document.querySelector('#logout')?.addEventListener('click', async event => {
    const button = event.currentTarget; button.disabled = true;
    try {
        await csrf();
        const response = await api('/logout', { method: 'POST', notify: false });
        flash(response?.message || 'You have signed out.', { type: 'info', title: 'Signed out' });
        window.location.assign('/login');
    } catch (error) { showError(error); button.disabled = false; }
});

document.addEventListener('click', event => {
    event.target.closest('[data-close-secondary]')?.closest('dialog')?.close();
});

window.addEventListener('offline', () => toast('You are offline. Changes cannot be saved until the connection returns.', { type: 'warning', title: 'Connection lost' }));
window.addEventListener('online', () => toast('Connection restored.', { type: 'info', title: 'Back online' }));

refreshShell().catch(() => {});
