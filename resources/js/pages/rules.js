import { api } from '../common/api';
import { busy, showError, status } from '../common/ui';

const form = document.querySelector('#rules-form');
const formStatus = form.querySelector('[data-form-status]');
const canEdit = document.body.dataset.role === 'scheduler';
const inputs = [...form.querySelectorAll('input')];
for (const input of inputs) input.disabled = true;

api('/api/v1/rules').then(({ data }) => {
    for (const input of inputs) { input.value = data[input.name]; input.disabled = !canEdit; }
}).catch(error => showError(error, formStatus));

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
