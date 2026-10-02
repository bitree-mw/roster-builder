/**
 * "Forgot your password?" page: asks for a reset link by email or username. The server gives the same
 * answer whether or not an account exists, so the message is shown as-is.
 */
import { api, csrf } from '../common/api';
import { showError, status } from '../common/ui';

const form = document.querySelector('#forgot-form');
const target = document.querySelector('#forgot-status');
const submit = form.querySelector('[type="submit"]');

form.addEventListener('submit', async event => {
    event.preventDefault();
    const login = form.elements.login.value.trim();
    if (!login) { form.elements.login.setAttribute('aria-invalid', 'true'); return status('Enter your email address or username.', true, target); }
    form.elements.login.removeAttribute('aria-invalid');
    submit.disabled = true;
    try {
        await csrf();
        const response = await api('/forgot-password', { method: 'POST', notify: false, body: { login } });
        status(response.message, false, target);
    } catch (error) {
        showError(error.status === 429 ? { message: 'Too many requests. Wait a minute and try again.' } : error, target);
    } finally { submit.disabled = false; }
});
form.elements.login.focus();
