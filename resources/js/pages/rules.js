/**
 * Duty rules page. Everyone on staff can view; only schedulers can edit (the API enforces this too).
 */
import { api } from '../common/api';
import { busy, showError, status } from '../common/ui';

const form = document.querySelector('#rules-form');
const formStatus = form.querySelector('[data-form-status]');
const canEdit = ['admin', 'scheduler'].includes(document.body.dataset.role);
const inputs = [...form.querySelectorAll('input')];
// Inputs stay disabled until the current values load, then only schedulers can edit them.
for (const input of inputs) input.disabled = true;

api('/api/v1/rules').then(({ data }) => {
    for (const input of inputs) { input.value = data[input.name]; input.disabled = !canEdit; }
}).catch(error => showError(error, formStatus));

// Save all values; success pops up from the server message, field errors are shown inline.
form.addEventListener('submit', async event => {
    event.preventDefault();
    await busy(form.querySelector('[type="submit"]'), async () => {
        const body = Object.fromEntries(inputs.map(input => [input.name, input.type === 'number' ? Number(input.value) : input.value]));
        for (const input of inputs) input.removeAttribute('aria-invalid');
        try { status('', false, formStatus); await api('/api/v1/rules', { method: 'PUT', body }); }
        catch (error) {
            showError(error, formStatus);
            for (const field of Object.keys(error.errors || {})) form.elements[field]?.setAttribute('aria-invalid', 'true');
        }
    });
});
