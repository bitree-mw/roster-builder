/**
 * "My account" dialog (layouts/app.blade.php): every signed-in user can change their own password from the
 * user chip. The server checks the current password and signs out the account's other sessions.
 */
import { api } from './api';
import { busy, showError, status } from './ui';

/** Wire the user chip button and the password form. */
export function initAccountDialog() {
    const dialog = document.querySelector('#account-dialog');
    const button = document.querySelector('#my-account');
    if (!dialog || !button) return;
    const form = dialog.querySelector('#account-form');
    const target = dialog.querySelector('[data-form-status]');

    button.addEventListener('click', () => {
        form.reset(); status('', false, target);
        for (const input of form.querySelectorAll('[aria-invalid]')) input.removeAttribute('aria-invalid');
        dialog.showModal(); form.elements.current_password.focus();
    });
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        // Catch the common mistakes locally; the server repeats every check.
        if (form.elements.password.value !== form.elements.password_confirmation.value) {
            form.elements.password_confirmation.setAttribute('aria-invalid', 'true');
            return status('The new passwords do not match.', true, target);
        }
        await busy(form.querySelector('[type="submit"]'), async () => {
            try {
                await api('/api/v1/me/password', { method: 'PUT', body: Object.fromEntries(new FormData(form)) });
                dialog.close();
            } catch (error) {
                showError(error, target);
                for (const field of Object.keys(error.errors || {})) form.elements[field]?.setAttribute('aria-invalid', 'true');
            }
        });
    });
}
