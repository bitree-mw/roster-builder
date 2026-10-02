import { flash, toast } from './toast';

export class ApiError extends Error {
    constructor(message, status, errors = {}, code = null) { super(message); this.status = status; this.errors = errors; this.code = code; this.notified = false; }
}
function xsrfToken() {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}
/**
 * CSRF-aware JSON client for the Laravel API. Responses follow App\Support\Api\ApiResponse:
 * {success, message, data, errors, code}.
 *
 * notify: show the server's message as a pop-up. Defaults to true for changes (POST/PUT/PATCH/DELETE)
 * and false for reads; pass notify: false when the caller presents the outcome itself.
 */
export async function api(path, { method = 'GET', body, notify = method !== 'GET', ...options } = {}) {
    let response;
    try {
        response = await fetch(path, {
            ...options, method, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
                ...(method !== 'GET' ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
            },
            ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
        });
    } catch {
        const error = new ApiError('The server could not be reached. Check your connection and try again.', 0, {}, 'network_error');
        if (notify) { toast(error.message, { type: 'error', title: 'Connection problem' }); error.notified = true; }
        throw error;
    }
    if (response.status === 204) return null;
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = data.message || (response.status === 419 ? 'Your session has expired. Reload the page and try again.' : 'The request could not be completed.');
        const error = new ApiError(message, response.status, data.errors, data.code ?? null);
        if (response.status === 401 && !path.endsWith('/login')) {
            flash(message, { type: 'warning', title: 'Signed out' });
            window.location.assign('/login');
        } else if (notify) {
            toast(message, { type: 'error', title: response.status === 422 ? 'Not completed' : undefined });
            error.notified = true;
        }
        throw error;
    }
    if (notify && data.message) toast(data.message, { type: 'success' });
    return data;
}
export async function csrf() { await api('/sanctum/csrf-cookie', { notify: false }); }
export async function allPages(path) {
    let next = path; const rows = [];
    while (next) {
        const result = await api(next);
        rows.push(...result.data);
        next = result.links?.next ? new URL(result.links.next).pathname + new URL(result.links.next).search : null;
    }
    return rows;
}
