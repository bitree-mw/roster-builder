/**
 * Accounts page (pages/accounts.blade.php): the sign-in accounts the caller may manage, with counts by type,
 * and the create/edit dialog. Administrators may create every type and delete accounts (never their own);
 * schedulers see and create pilot and cabin crew accounts only. The server enforces all of this; the page
 * only hides what the caller cannot use.
 */
import { allPages } from '../common/api';
import { editor } from '../common/editor';
import { chip, element, emptyRow, formatDate, iconButton, loadingRow, plural, segmented, setKpi, showError } from '../common/ui';

const admin = document.querySelector('#accounts-root').dataset.admin === 'true';
const form = document.querySelector('#account-edit-form');
const tbody = document.querySelector('#account-rows');
const crewSelect = form.elements.crew_member_id;
const TYPES = { admin: 'Administrator', scheduler: 'Scheduler', crew_control: 'Crew control', pilot: 'Pilot', cabin: 'Cabin crew' };
const TONES = { admin: 'brand', scheduler: 'info', crew_control: 'info', pilot: 'success', cabin: 'success' };
const CREW_TYPES = { pilot: ['CPT', 'FO'], cabin: ['CC'] };
// Page state: accounts and crew from the API, the type filter and the search text, and the record being edited.
let accounts = [];
let crews = [];
let typeFilter = '';
let search = '';
let editing = null;

/** The selected account type in the dialog. */
function selectedType() { return form.querySelector('input[name="type"]:checked')?.value || 'pilot'; }

/**
 * Fill the crew member list for pilot/cabin accounts: active crew of that type who have no account yet,
 * plus the crew member already linked to the account being edited. Hidden for staff accounts.
 */
function populateCrew() {
    const type = selectedType();
    const field = document.querySelector('#crew-field');
    field.hidden = !CREW_TYPES[type];
    if (!CREW_TYPES[type]) return;
    const linked = new Set(accounts.filter(account => account.id !== editing?.id).map(account => account.crew_member_id).filter(Boolean));
    const available = crews.filter(crew => CREW_TYPES[type].includes(crew.rank) && (crew.active || crew.id === editing?.crew_member_id) && !linked.has(crew.id));
    const placeholder = element('option', available.length ? 'Choose a crew member' : 'Every crew member of this type already has an account'); placeholder.value = '';
    crewSelect.replaceChildren(placeholder, ...available.map(crew => {
        const option = element('option', `${crew.name} · ${crew.rank} · ${crew.base_airport}`); option.value = crew.id; return option;
    }));
    crewSelect.value = editing?.crew_member_id && available.some(crew => crew.id === editing.crew_member_id) ? String(editing.crew_member_id) : '';
}

// When a crew member is chosen, prefill name and email from their profile (only into empty fields).
crewSelect.addEventListener('change', () => {
    const crew = crews.find(item => String(item.id) === crewSelect.value); if (!crew) return;
    if (!form.elements.name.value) form.elements.name.value = crew.name;
    if (!form.elements.email.value && crew.email) form.elements.email.value = crew.email;
});
form.addEventListener('change', event => { if (event.target.name === 'type') populateCrew(); });

// Create/edit dialog. Pilot and cabin types are sent as role "crew" with the linked crew member.
const controller = editor({
    form, dialog: document.querySelector('#account-edit-dialog'), endpoint: '/api/v1/accounts', label: 'Account', refresh,
    read: () => {
        const type = selectedType();
        const crewType = Boolean(CREW_TYPES[type]);
        return {
            name: form.elements.name.value, email: form.elements.email.value, username: form.elements.username.value.trim() || null,
            role: crewType ? 'crew' : type, crew_member_id: crewType ? Number(crewSelect.value) || null : null,
            password: form.elements.password.value || null, password_confirmation: form.elements.password_confirmation.value || null,
        };
    },
    fill: record => {
        editing = record;
        const type = record?.type ?? 'pilot';
        for (const radio of form.querySelectorAll('input[name="type"]')) radio.checked = radio.value === type;
        if (record) for (const key of ['name', 'email', 'username']) form.elements[key].value = record[key] || '';
        form.querySelector('[data-password-hint]').textContent = record ? 'Leave blank to keep the current password. A new one signs them out everywhere.' : 'At least 12 characters.';
        form.elements.password.required = !record;
        populateCrew();
    },
});

/** Accounts matching the type filter and search text. */
function visible() {
    const term = search.trim().toLowerCase();
    return accounts.filter(account => {
        if (typeFilter === 'staff' && !['admin', 'scheduler', 'crew_control'].includes(account.type)) return false;
        if (typeFilter && typeFilter !== 'staff' && account.type !== typeFilter) return false;
        return !term || [account.name, account.email, account.username].some(value => value?.toLowerCase().includes(term));
    });
}

/** Render the account table. Delete appears for administrators only, never on their own account. */
function render() {
    const rows = visible();
    document.querySelector('#account-count').textContent = `${rows.length} of ${plural(accounts.length, 'account')}`;
    if (!rows.length) return emptyRow(tbody, 6, accounts.length ? 'No accounts match this view.' : 'No accounts yet. Create the first one.', 'lock');
    tbody.replaceChildren(...rows.map(account => {
        const row = element('tr');
        const who = element('td'); who.append(element('strong', account.name), element('div', account.username ? `@${account.username}` : 'No username', 'small muted mono'));
        if (account.is_self) who.append(chip('You', 'brand'));
        row.append(who, element('td', account.email, 'small'));
        const type = element('td'); type.append(chip(TYPES[account.type] || account.role_label, TONES[account.type])); row.append(type);
        const crew = element('td');
        if (account.crew) {
            crew.append(element('span', account.crew.name), element('div', `${account.crew.rank} · ${account.crew.base_airport}`, 'small muted mono'));
            if (!account.crew.active) crew.append(chip('Inactive crew', 'warning'));
        } else crew.append(element('span', '—', 'muted'));
        row.append(crew, element('td', account.created_at ? formatDate(account.created_at.slice(0, 10)) : '—', 'mono small'));
        const tools = element('td'); const wrap = element('div', null, 'row-actions');
        wrap.append(iconButton('pencil', `Edit ${account.name}`, () => controller.open(account)));
        if (admin && !account.is_self) wrap.append(iconButton('trash', `Delete ${account.name}`, () => controller.remove(account), 'danger'));
        tools.append(wrap); row.append(tools);
        return row;
    }));
}

/** Counts by type, plus active crew who still have no sign-in. */
function renderKpis() {
    const count = types => accounts.filter(account => types.includes(account.type)).length;
    setKpi('accounts.total', accounts.length);
    setKpi('accounts.staff', count(['admin', 'scheduler', 'crew_control']), `${count(['admin'])} admin · ${count(['scheduler'])} scheduler · ${count(['crew_control'])} crew control`);
    setKpi('accounts.pilots', count(['pilot']));
    setKpi('accounts.cabin', count(['cabin']));
    const linked = new Set(accounts.map(account => account.crew_member_id).filter(Boolean));
    const missing = crews.filter(crew => crew.active && !linked.has(crew.id));
    setKpi('accounts.missing', missing.length, `${missing.filter(crew => crew.rank !== 'CC').length} pilots · ${missing.filter(crew => crew.rank === 'CC').length} cabin crew`);
}

/** Reload accounts (after any change) and redraw. */
async function refresh() {
    loadingRow(tbody, 6, 'Loading accounts…');
    accounts = await allPages('/api/v1/accounts');
    render(); renderKpis();
}

// Filters, the create button, then the initial load (crew first, for the crew member list).
segmented(document.querySelector('#type-filter'), value => { typeFilter = value; render(); });
document.querySelector('#account-search').addEventListener('input', event => { search = event.target.value; render(); });
document.querySelector('#add-account').addEventListener('click', () => controller.open());
(async () => {
    crews = await allPages('/api/v1/crew-members');
    document.querySelector('#add-account').disabled = false;
    await refresh();
})().catch(error => { emptyRow(tbody, 6, 'Accounts could not be loaded. Reload the page to try again.', 'alert'); showError(error); });
