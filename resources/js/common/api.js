import { flash, toast } from './toast';

export class ApiError extends Error {
    constructor(message, status, errors = {}, code = null) { super(message); this.status = status; this.errors = errors; this.code = code; this.notified = false; }
}
/*
 * Always send the Referer on same-origin calls. Sanctum recognises the browser session by matching the
 * Referer/Origin to this host; a server-wide "Referrer-Policy: no-referrer" header would otherwise strip it
 * and every API call would look signed out.
 */
const REFERRER_POLICY = 'same-origin';
const REDIRECT_KEY = 'roster.auth-redirect-at';

/**
 * The API says the caller is not signed in. Normally the session really ended: go to sign in. But when we
 * were sent here from the sign-in page moments ago, the page itself was served to a signed-in user, so the
 * API is rejecting a valid session (a server configuration problem). Stop instead of looping between pages.
 */
function signedOut(message, error) {
    let recent = false;
    try {
        recent = Date.now() - Number(sessionStorage.getItem(REDIRECT_KEY) || 0) < 15000;
        sessionStorage.setItem(REDIRECT_KEY, String(Date.now()));
    } catch { /* storage unavailable: fall back to a normal redirect */ }
    if (recent) {
        toast('You are signed in, but the server rejected the session for data requests. Ask your administrator to run "php artisan roster:doctor" on the server.', { type: 'error', title: 'Session not accepted' });
        error.notified = true;
        return;
    }
    flash(message, { type: 'warning', title: 'Signed out' });
    window.location.assign('/login');
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
            ...options, method, credentials: 'same-origin', referrerPolicy: REFERRER_POLICY,
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
        // Server errors carry a reference that matches the entry in storage/logs/laravel.log.
        const base = data.message || (response.status === 419 ? 'Your session has expired. Reload the page and try again.' : 'The request could not be completed.');
        const message = data.reference ? `${base} Reference: ${data.reference}.` : base;
        const error = new ApiError(message, response.status, data.errors, data.code ?? null);
        if (response.status === 401 && !path.endsWith('/login')) {
            signedOut(message, error);
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

/**
 * POST a multipart form (file uploads) with the CSRF header. Responses, errors and pop-ups behave like
 * api(): the server's message pops up on success when notify is true, errors always pop up.
 */
export async function upload(path, formData, { notify = true } = {}) {
    let response;
    try {
        response = await fetch(path, { method: 'POST', credentials: 'same-origin', referrerPolicy: REFERRER_POLICY, body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() } });
    } catch {
        const error = new ApiError('The server could not be reached. Check your connection and try again.', 0, {}, 'network_error');
        toast(error.message, { type: 'error', title: 'Connection problem' }); error.notified = true;
        throw error;
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new ApiError(data.message || (response.status === 413 ? 'The file is too large.' : 'The file could not be uploaded.'), response.status, data.errors, data.code ?? null);
        toast(error.message, { type: 'error', title: response.status === 422 ? 'Not completed' : undefined }); error.notified = true;
        throw error;
    }
    if (notify && data.message) toast(data.message, { type: 'success' });
    return data;
}
export async function allPages(path) {
    let next = path; const rows = [];
    while (next) {
        const result = await api(next);
        rows.push(...result.data);
        next = result.links?.next ? new URL(result.links.next).pathname + new URL(result.links.next).search : null;
    }
    return rows;
}

/**
 * Download a file from the API (CSV, calendar) with the session cookie, saving it under the server's
 * filename. Errors are shown as pop-ups like any other API call, and rethrown.
 */
export async function download(path, fallbackName = 'download') {
    let response;
    try {
        response = await fetch(path, { credentials: 'same-origin', referrerPolicy: REFERRER_POLICY, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    } catch {
        const error = new ApiError('The server could not be reached. Check your connection and try again.', 0, {}, 'network_error');
        toast(error.message, { type: 'error', title: 'Connection problem' }); error.notified = true;
        throw error;
    }
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const error = new ApiError(data.message || 'The file could not be downloaded.', response.status, data.errors, data.code ?? null);
        toast(error.message, { type: 'error' }); error.notified = true;
        throw error;
    }
    const name = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') || '')?.[1] || fallbackName;
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a'); link.href = url; link.download = name;
    document.body.append(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
