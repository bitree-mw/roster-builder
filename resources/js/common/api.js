export class ApiError extends Error {
    constructor(message, status, errors = {}) { super(message); this.status = status; this.errors = errors; }
}
function xsrfToken() {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}
export async function api(path, { method = 'GET', body, ...options } = {}) {
    const response = await fetch(path, {
        ...options, method, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            ...(method !== 'GET' ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    if (response.status === 204) return null;
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401 && !path.endsWith('/login')) window.location.assign('/login');
        throw new ApiError(response.status === 419 ? 'Your session has expired. Reload the page and try again.' : data.message || 'The request could not be completed.', response.status, data.errors);
    }
    return data;
}
export async function csrf() { await api('/sanctum/csrf-cookie'); }
export async function allPages(path) {
    let next = path; const rows = [];
    while (next) {
        const result = await api(next);
        rows.push(...result.data);
        next = result.links?.next ? new URL(result.links.next).pathname + new URL(result.links.next).search : null;
    }
    return rows;
}
