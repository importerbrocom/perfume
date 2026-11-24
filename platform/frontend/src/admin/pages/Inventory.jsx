import { useEffect, useState } from 'react';
import { Link, useBlocker, useSearchParams } from 'react-router-dom';
import { adminApi, fieldErrors, fmtDateTime, num, qs, useLoad } from '../lib';
import { Empty, ErrorBox, Loading, Modal, PageHead, Pager, Tabs, Text, useUi } from '../ui';

const REASONS = { manual: 'Manual change', order: 'Order', cancel: 'Order cancelled', return: 'Returned', expire: 'Unpaid order expired', import: 'Import' };

function Adjust({ row, onClose, onSaved }) {
  const { notify } = useUi();
  const [change, setChange] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const save = async (e) => {
    e.preventDefault();
    const n = parseInt(change, 10);
    if (!n) return setError('Enter how many units to add (e.g. 12) or remove (e.g. -2).');
    setBusy(true);
    try {
      onSaved(await adminApi(`inventory/${row.id}/adjust`, { method: 'POST', body: { change: n, note: note || null } }));
      notify('Stock updated.');
      onClose();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal title={`Add / remove stock · ${row.product_name} ${row.name}`} onClose={onClose}>
      <form onSubmit={save} className="ad-form">
        <p className="ad-wide">In stock now: <b>{row.track_stock ? row.stock_quantity : 'not counted'}</b>. Adding or removing is safe even while orders come in.</p>
        <Text label="Units to add (use − to remove)" type="number" inputMode="numeric" value={change} onChange={setChange} placeholder="12 or -2" error={error} />
        <Text label="Reason (optional)" value={note} onChange={setNote} maxLength={300} placeholder="New delivery, damaged…" />
        <div className="ad-form__foot">
          <button type="button" className="ad-btn ad-btn--ghost" onClick={onClose}>Cancel</button>
          <button className="ad-btn" disabled={busy}>Save</button>
        </div>
      </form>
    </Modal>
  );
}

function History({ row, onClose }) {
  const { data, error, loading } = useLoad('inventory/movements?' + qs({ variant_id: row?.id }));
  return (
    <Modal title={row ? `Stock history · ${row.product_name} ${row.name}` : 'Stock history'} onClose={onClose} wide>
      <ErrorBox error={error} />
      {loading && !data ? <Loading /> : data && (data.data.length === 0 ? <Empty>No stock changes yet.</Empty> : (
        <div className="ad-table-wrap">
          <table className="ad-table">
            <thead><tr><th>When</th>{!row && <th>Product</th>}<th className="num">Change</th><th className="num">After</th><th>Why</th><th>By</th></tr></thead>
            <tbody>
              {data.data.map((m) => (
                <tr key={m.id}>
                  <td data-label="When">{fmtDateTime(m.at)}</td>
                  {!row && <td data-label="Product">{m.product} · {m.variant}</td>}
                  <td data-label="Change" className={'num ' + (m.change < 0 ? 'ad-bad' : 'ad-good')}>{m.change > 0 ? '+' : ''}{m.change}</td>
                  <td data-label="After" className="num">{m.quantity_after}</td>
                  <td data-label="Why">{REASONS[m.reason] || m.reason}{m.order ? ` ${m.order}` : ''}{m.note ? ` · ${m.note}` : ''}</td>
                  <td data-label="By">{m.user || 'System'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
    </Modal>
  );
}

export default function Inventory() {
  const [params, setParams] = useSearchParams();
  const { notify } = useUi();
  const filter = params.get('filter') || '';
  const page = params.get('page') || '';
  const [term, setTerm] = useState(params.get('search') || '');
  const [search, setSearch] = useState(term);
  const { data, error, loading, reload, setData } = useLoad('inventory?' + qs({ filter, search, page }));
  const [edits, setEdits] = useState({}); // id -> changed fields
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [adjust, setAdjust] = useState(null);
  const [history, setHistory] = useState(undefined); // undefined = closed, null = all
  useEffect(() => {
    const t = setTimeout(() => setSearch(term.trim()), 350);
    return () => clearTimeout(t);
  }, [term]);

  const changed = Object.keys(edits).length;
  const blocker = useBlocker(({ currentLocation, nextLocation }) => changed > 0 && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    // eslint-disable-next-line no-alert
    if (blocker.state === 'blocked') (window.confirm('You have unsaved stock or price changes. Leave without saving?') ? blocker.proceed() : blocker.reset());
  }, [blocker]);

  const setParam = (k, v) => {
    if (changed && !window.confirm('Discard unsaved changes?')) return; // eslint-disable-line no-alert
    setEdits({});
    const next = new URLSearchParams(params);
    v ? next.set(k, v) : next.delete(k);
    if (k !== 'page') next.delete('page');
    setParams(next);
  };
  const val = (r, k) => (edits[r.id] && k in edits[r.id] ? edits[r.id][k] : r[k]);
  const edit = (r, k, v) => setEdits((e) => {
    const cur = { ...(e[r.id] || {}), [k]: v };
    const same = (a, b) => (a === null || a === '' ? null : Number(a)) === (b === null || b === '' ? null : Number(b));
    if (same(cur[k], r[k])) delete cur[k];
    const next = { ...e, [r.id]: cur };
    if (!Object.keys(cur).length) delete next[r.id];
    return next;
  });

  const save = async () => {
    setBusy(true);
    setErrors({});
    const ids = Object.keys(edits);
    const rows = ids.map((id) => {
      const out = { id: Number(id) };
      for (const [k, v] of Object.entries(edits[id])) out[k] = num(v);
      // the server checks sale < price, so send the price too when only the sale price changed
      if ('sale_price' in out && !('price' in out)) out.price = data.data.find((r) => r.id === Number(id))?.price ?? null;
      return out;
    });
    try {
      const res = await adminApi('inventory', { method: 'PUT', body: { rows } });
      notify(`Saved ${res.updated} size${res.updated === 1 ? '' : 's'}.`);
      setEdits({});
      reload();
    } catch (e) {
      const fe = fieldErrors(e);
      const byId = {};
      for (const [k, msg] of Object.entries(fe)) {
        const m = k.match(/^rows\.(\d+)\.(\w+)$/);
        if (m) byId[`${rows[m[1]].id}.${m[2]}`] = msg;
      }
      setErrors(byId);
      notify(e.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHead title="Stock & Prices" sub="Change prices and stock for many sizes, then press Save. Empty price = “price on request”.">
        <button type="button" className="ad-btn ad-btn--ghost" onClick={() => setHistory(null)}>Stock history</button>
      </PageHead>
      <Tabs value={filter} onChange={(v) => setParam('filter', v)} items={[['', 'All sizes'], ['low', 'Low stock'], ['out', 'Out of stock'], ['unpriced', 'No price']]} />
      <div className="ad-filters">
        <input type="search" aria-label="Search sizes" placeholder="Search product or SKU…" value={term} onChange={(e) => setTerm(e.target.value)} />
      </div>
      <ErrorBox error={error} onRetry={reload} />
      {loading && !data ? <Loading /> : data && (data.data.length === 0 ? <Empty>Nothing here. {filter === 'low' || filter === 'out' ? 'Stock looks good.' : ''}</Empty> : (
        <div className="ad-table-wrap">
          <table className="ad-table ad-table--edit" data-testid="stock-table">
            <thead><tr><th>Product · size</th><th>Price</th><th>Sale price</th><th>Stock</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {data.data.map((r) => {
                const err = (k) => errors[`${r.id}.${k}`];
                return (
                  <tr key={r.id} className={edits[r.id] ? 'is-dirty' : ''} data-testid="stock-row">
                    <td data-label="Product">
                      <div className="ad-prod">
                        {r.image ? <img src={r.image} alt="" width="40" height="40" loading="lazy" /> : <span className="ad-thumb" />}
                        <span>
                          <Link to={`/admin/products/${r.product_id}`}><b>{r.product_name}</b></Link> · {r.name}
                          <small className="ad-block ad-muted">{r.sku || 'No SKU'}{!r.product_active ? ' · product hidden' : ''}{!r.is_active ? ' · size off' : ''}</small>
                        </span>
                      </div>
                    </td>
                    <td data-label="Price">
                      <input className="ad-cell" type="number" min="0" step="any" inputMode="decimal" aria-label={`Price for ${r.product_name} ${r.name}`} value={val(r, 'price') ?? ''} onChange={(e) => edit(r, 'price', e.target.value)} aria-invalid={!!err('price')} />
                      {err('price') && <small className="ad-error">{err('price')}</small>}
                    </td>
                    <td data-label="Sale price">
                      <input className="ad-cell" type="number" min="0" step="any" inputMode="decimal" aria-label={`Sale price for ${r.product_name} ${r.name}`} value={val(r, 'sale_price') ?? ''} onChange={(e) => edit(r, 'sale_price', e.target.value)} aria-invalid={!!err('sale_price')} />
                      {err('sale_price') && <small className="ad-error">{err('sale_price')}</small>}
                    </td>
                    <td data-label="Stock">
                      <input className={'ad-cell' + (r.low_stock ? ' is-low' : '') + (r.track_stock && r.stock_quantity <= 0 ? ' is-out' : '')} type="number" min="0" step="1" inputMode="numeric" aria-label={`Stock for ${r.product_name} ${r.name}`}
                        value={val(r, 'stock_quantity') ?? ''} placeholder={r.track_stock ? '' : 'not counted'} onChange={(e) => edit(r, 'stock_quantity', e.target.value)} />
                      {err('stock_quantity') && <small className="ad-error">{err('stock_quantity')}</small>}
                    </td>
                    <td data-label="" className="ad-row-actions">
                      <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" onClick={() => setAdjust(r)}>+/− stock</button>
                      <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" onClick={() => setHistory(r)}>History</button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ))}
      <Pager meta={data?.meta} onPage={(p) => setParam('page', String(p))} />
      {changed > 0 && (
        <div className="ad-savebar">
          <span>{changed} size{changed === 1 ? '' : 's'} changed</span>
          <button type="button" className="ad-btn ad-btn--ghost" onClick={() => { setEdits({}); setErrors({}); }}>Undo</button>
          <button type="button" className="ad-btn" disabled={busy} onClick={save} data-testid="save-stock">{busy ? 'Saving…' : 'Save changes'}</button>
        </div>
      )}
      {adjust && <Adjust row={adjust} onClose={() => setAdjust(null)} onSaved={(row) => setData((d) => ({ ...d, data: d.data.map((x) => (x.id === row.id ? row : x)) }))} />}
      {history !== undefined && <History row={history} onClose={() => setHistory(undefined)} />}
    </>
  );
}
