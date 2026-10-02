/**
 * "Choose a new password" page: sends the token, email and new password; on success the confirmation is
 * carried to the sign-in page as a pop-up.
 */
import { api, csrf } from '../common/api';
import { flash } from '../common/toast';
import { showError, status } from '../common/ui';

const form = document.querySelector('#reset-form');
const target = document.querySelector('#reset-status');
const submit = form.querySelector('[type="submit"]');

form.addEventListener('submit', async event => {
    event.preventDefault();
    const { password, password_confirmation: confirmation } = form.elements;
    if (password.value.length < 12) { password.setAttribute('aria-invalid', 'true'); return status('Use at least 12 characters.', true, target); }
    if (password.value !== confirmation.value) { confirmation.setAttribute('aria-invalid', 'true'); return status('The passwords do not match.', true, target); }
    for (const input of form.querySelectorAll('[aria-invalid]')) input.removeAttribute('aria-invalid');
    submit.disabled = true;
    try {
        await csrf();
        const response = await api('/reset-password', { method: 'POST', notify: false, body: Object.fromEntries(new FormData(form)) });
        flash(response.message, { type: 'success', title: 'Password changed' });
        window.location.assign('/login');
    } catch (error) {
        showError(error, target);
        submit.disabled = false;
    }
});
form.elements.password.focus();
