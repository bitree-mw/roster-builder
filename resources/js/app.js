import { api, csrf } from './common/api';
import { refreshShell } from './common/shell';
import { showError } from './common/ui';

document.querySelector('#logout')?.addEventListener('click', async event => {
    const button = event.currentTarget; button.disabled = true;
    try { await csrf(); await api('/logout', { method: 'POST' }); window.location.assign('/login'); }
    catch (error) { showError(error); button.disabled = false; }
});

document.addEventListener('click', event => {
    event.target.closest('[data-close-secondary]')?.closest('dialog')?.close();
});

refreshShell().catch(() => {});
