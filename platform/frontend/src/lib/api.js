// Small fetch wrapper for the Laravel API. Laravel sets the XSRF-TOKEN cookie; we send it back as a header.
function xsrf() {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : null;
}

export class ApiError extends Error {
  constructor(message, status, errors = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

export async function api(path, { method = 'GET', body, signal } = {}) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  const token = xsrf();
  if (token) headers['X-XSRF-TOKEN'] = token;
  let payload;
  if (body instanceof FormData) payload = body;
  else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch('/api/' + path.replace(/^\//, ''), { method, headers, body: payload, credentials: 'same-origin', signal });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError('Network problem. Please check your connection and try again.', 0);
  }
  const data = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) {
    if (res.status === 401 && data?.gate) {
      // Preview lock (key expired or the shop was locked again): reload once to show the key page, never in a loop.
      const last = Number(sessionStorage.getItem('pm_gate_reload')) || 0;
      if (Date.now() - last > 30000) {
        sessionStorage.setItem('pm_gate_reload', String(Date.now()));
        window.location.reload();
      }
    }
    const errors = data?.errors || {};
    const first = Object.values(errors)[0];
    const message = (Array.isArray(first) ? first[0] : first) || data?.message || 'Something went wrong. Please try again.';
    throw new ApiError(res.status === 429 ? 'Too many tries. Please wait a minute and try again.' : message, res.status, errors);
  }
  return data;
}

export const qs = (o) => new URLSearchParams(Object.entries(o).filter(([, v]) => v !== '' && v !== null && v !== undefined)).toString();
