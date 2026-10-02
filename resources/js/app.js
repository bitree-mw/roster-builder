/**
 * Shared script loaded on every signed-in page (layouts/app.blade.php): sign-out, dialog cancel buttons,
 * connection notices and the navigation counts / header alerts.
 */
import { api, csrf } from './common/api';
import { refreshShell } from './common/shell';
import { flash, toast } from './common/toast';
import { showError } from './common/ui';

// Sign out, carry the server's message to the login page as a pop-up, then leave.
document.querySelector('#logout')?.addEventListener('click', async event => {
    const button = event.currentTarget; button.disabled = true;
    try {
        await csrf();
        const response = await api('/logout', { method: 'POST', notify: false });
        flash(response?.message || 'You have signed out.', { type: 'info', title: 'Signed out' });
        window.location.assign('/login');
    } catch (error) { showError(error); button.disabled = false; }
});

// Any [data-close-secondary] button (e.g. "Cancel") closes the dialog it sits in.
document.addEventListener('click', event => {
    event.target.closest('[data-close-secondary]')?.closest('dialog')?.close();
});

// Tell people when the connection drops or returns, since saves will fail while offline.
window.addEventListener('offline', () => toast('You are offline. Changes cannot be saved until the connection returns.', { type: 'warning', title: 'Connection lost' }));
window.addEventListener('online', () => toast('Connection restored.', { type: 'info', title: 'Back online' }));

// Fill navigation badges and header alert pills for staff (a no-op for crew accounts).
refreshShell().catch(() => {});
