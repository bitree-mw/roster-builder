import { api } from './api';

let request = null;
/** Staff overview counts, fetched once per page and shared by the shell and page scripts. */
export function overview({ refresh = false } = {}) {
    if (!request || refresh) request = api('/api/v1/overview').then(response => response.data);
    return request;
}
