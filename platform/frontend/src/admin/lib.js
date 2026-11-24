import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError, qs } from '../lib/api';

export { qs, ApiError };

let csrfReady = null;
/** Sanctum sets the XSRF-TOKEN cookie; needed once before login and again after the session changes. */
export function csrf(force = false) {
  if (force || !csrfReady) {
    csrfReady = fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => {
      csrfReady = null;
    });
  }
  return csrfReady;
}

/** Admin API call. Retries once with a fresh CSRF cookie when the token expired (HTTP 419). */
export async function adminApi(path, opts = {}) {
  const method = (opts.method || 'GET').toUpperCase();
  if (method !== 'GET') await csrf();
  try {
    return await api('admin/' + path.replace(/^\//, ''), opts);
  } catch (e) {
    if (e.status === 419 && !opts._retried) {
      await csrf(true);
      return adminApi(path, { ...opts, _retried: true });
    }
    if (e.status === 401 && !path.startsWith('login') && !path.startsWith('me')) window.dispatchEvent(new Event('pm-admin-logout'));
    throw e;
  }
}

/** Load data from the admin API; reloads when `path` changes. */
export function useLoad(path) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const seq = useRef(0);
  const reload = useCallback(() => {
    if (!path) return Promise.resolve(null);
    const n = ++seq.current;
    setState((s) => ({ ...s, loading: true, error: null }));
    return adminApi(path)
      .then((data) => {
        if (n === seq.current) setState({ data, error: null, loading: false });
        return data;
      })
      .catch((error) => {
        if (n === seq.current) setState((s) => ({ ...s, error, loading: false }));
      });
  }, [path]);
  useEffect(() => {
    reload();
  }, [reload]);
  const setData = useCallback((fn) => setState((s) => ({ ...s, data: typeof fn === 'function' ? fn(s.data) : fn })), []);
  return { ...state, reload, setData };
}

/** Builds multipart data. Booleans -> 1/0, null -> '' (Laravel turns '' into null), arrays -> key[]. */
export function toFormData(obj) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(obj)) {
    if (v === undefined) continue;
    if (v instanceof File) fd.append(k, v);
    else if (Array.isArray(v)) v.forEach((x) => fd.append(`${k}[]`, x));
    else if (typeof v === 'boolean') fd.append(k, v ? '1' : '0');
    else fd.append(k, v === null ? '' : String(v));
  }
  return fd;
}

/** Field errors from a 422 response, keyed by field name ("variants.0.price" etc.). */
export const fieldErrors = (e) => {
  const out = {};
  for (const [k, v] of Object.entries(e?.errors || {})) out[k] = Array.isArray(v) ? v[0] : v;
  return out;
};

const dt = new Intl.DateTimeFormat('en-AE', { dateStyle: 'medium', timeStyle: 'short' });
const d = new Intl.DateTimeFormat('en-AE', { dateStyle: 'medium' });
export const fmtDateTime = (iso) => (iso ? dt.format(new Date(iso)) : '—');
export const fmtDate = (iso) => (iso ? d.format(new Date(iso)) : '—');

/** ISO -> value for <input type="datetime-local"> in the browser's time zone. */
export function toLocalInput(iso) {
  if (!iso) return '';
  const t = new Date(iso);
  if (Number.isNaN(t.getTime())) return '';
  const p = (n) => String(n).padStart(2, '0');
  return `${t.getFullYear()}-${p(t.getMonth() + 1)}-${p(t.getDate())}T${p(t.getHours())}:${p(t.getMinutes())}`;
}
/** <input type="datetime-local"> value -> ISO (UTC) for the API. */
export const fromLocalInput = (v) => (v ? new Date(v).toISOString() : null);

/** Number input value -> number or null (keeps 0). */
export const num = (v) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? null : Number(v));

export async function downloadFile(path, filename) {
  const res = await fetch('/api/admin/' + path, { credentials: 'same-origin', headers: { Accept: 'text/csv', 'X-Requested-With': 'XMLHttpRequest' } });
  if (!res.ok) throw new ApiError('Download failed. Please try again.', res.status);
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
