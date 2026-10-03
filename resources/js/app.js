/**
 * Shared script loaded on every signed-in page (layouts/app.blade.php): sign-out, "My account", the theme switch, dialog cancel buttons,
 * 24-hour clock fields, connection notices and the navigation counts / header alerts.
 */
import { initAccountDialog } from './common/account';
import { api, csrf } from './common/api';
import { refreshShell } from './common/shell';
import { initThemeToggle } from './common/theme';
import { flash, toast } from './common/toast';
import { normalizeClock, showError } from './common/ui';

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

// 24-hour clock fields ([data-clock], see clockInput in ui.js): "730" becomes "07:30" when the field is left.
document.addEventListener('focusout', event => {
    if (event.target.matches?.('input[data-clock]')) event.target.value = normalizeClock(event.target.value);
});

// Tell people when the connection drops or returns, since saves will fail while offline.
window.addEventListener('offline', () => toast('You are offline. Changes cannot be saved until the connection returns.', { type: 'warning', title: 'Connection lost' }));
window.addEventListener('online', () => toast('Connection restored.', { type: 'info', title: 'Back online' }));

// Fill navigation badges and header alert pills for staff (a no-op for crew accounts).
refreshShell().catch(() => {});

// "My account": change your own password from the user chip.
initAccountDialog();
// Light / dark theme switch in the top bar.
initThemeToggle();
