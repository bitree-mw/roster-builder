import { element, icon } from './ui';

let dialog = null;
let resolver = null;

function build() {
    dialog = element('dialog', null, 'dialog confirm-dialog');
    dialog.setAttribute('aria-labelledby', 'confirm-title');
    dialog.setAttribute('aria-describedby', 'confirm-message');
    const form = element('form'); form.method = 'dialog';
    const head = element('div', null, 'confirm-head');
    const badge = element('span', null, 'confirm-icon'); badge.append(icon('alert'));
    const text = element('div');
    const title = element('h2', '', 'confirm-title'); title.id = 'confirm-title';
    const message = element('p', '', 'confirm-message'); message.id = 'confirm-message';
    text.append(title, message); head.append(badge, text);
    const actions = element('div', null, 'form-actions');
    const cancel = element('button', 'Cancel', 'button button-secondary'); cancel.value = 'cancel'; cancel.dataset.cancel = '';
    const confirm = element('button', 'Confirm', 'button'); confirm.value = 'confirm'; confirm.dataset.confirm = '';
    actions.append(cancel, confirm); form.append(head, actions); dialog.append(form);
    dialog.addEventListener('close', () => { resolver?.(dialog.returnValue === 'confirm'); resolver = null; });
    document.body.append(dialog);
}

/**
 * Accessible replacement for window.confirm(). Resolves true only when the user confirms.
 * Destructive confirmations focus Cancel first so Enter never deletes by accident.
 */
export function confirmAction({ title = 'Are you sure?', message = '', confirmLabel = 'Confirm', tone = 'danger' } = {}) {
    if (!dialog) build();
    if (dialog.open) dialog.close('cancel');
    dialog.dataset.tone = tone;
    dialog.querySelector('.confirm-title').textContent = title;
    dialog.querySelector('.confirm-message').textContent = message;
    const confirm = dialog.querySelector('[data-confirm]');
    confirm.textContent = confirmLabel;
    confirm.className = tone === 'danger' ? 'button button-danger' : 'button';
    dialog.returnValue = '';
    return new Promise(resolve => {
        resolver = resolve;
        dialog.showModal();
        (tone === 'danger' ? dialog.querySelector('[data-cancel]') : confirm).focus();
    });
}
