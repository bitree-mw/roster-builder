import { api, csrf } from '../common/api';
import { showError, status } from '../common/ui';

const form = document.querySelector('#login-form');
const target = document.querySelector('#login-status');
const password = form.elements.password;
const toggle = document.querySelector('#toggle-password');
const submit = form.querySelector('[type="submit"]');

toggle.addEventListener('click', () => {
    const visible = password.type === 'password';
    password.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(visible));
    toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    toggle.querySelector('[data-eye]').toggleAttribute('hidden', visible);
    toggle.querySelector('[data-eye-off]').toggleAttribute('hidden', !visible);
    password.focus();
});

for (const type of ['keydown', 'keyup']) {
    password.addEventListener(type, event => {
        if (typeof event.getModifierState === 'function') document.querySelector('#caps-warning').hidden = !event.getModifierState('CapsLock');
    });
}

function markInvalid(fields) {
    for (const input of [form.elements.login, password]) input.setAttribute('aria-invalid', String(fields.includes(input.name)));
}

form.addEventListener('submit', async event => {
    event.preventDefault();
    const missing = [form.elements.login, password].filter(input => !input.value.trim() || !input.checkValidity()).map(input => input.name);
    if (missing.length) {
        markInvalid(missing);
        status(missing.includes('login') ? 'Enter your email address or username and your password.' : 'Enter your password.', true, target);
        form.elements[missing[0]].focus();
        return;
    }
    markInvalid([]);
    submit.disabled = true;
    submit.querySelector('[data-submit-label]').textContent = 'Signing in…';
    status('', false, target);
    try {
        await csrf();
        await api('/login', { method: 'POST', body: { login: form.elements.login.value.trim(), password: password.value } });
        submit.querySelector('[data-submit-label]').textContent = 'Opening workspace…';
        window.location.assign('/roster');
    } catch (error) {
        markInvalid(['login', 'password']);
        showError(error.status === 429 ? { message: 'Too many sign-in attempts. Wait a minute and try again.' } : error, target);
        password.select();
        submit.disabled = false;
        submit.querySelector('[data-submit-label]').textContent = 'Sign in';
    }
});

form.elements.login.focus();
