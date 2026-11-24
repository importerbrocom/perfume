import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { money, waLink } from '../../lib/format';
import { countryName } from '../../lib/countries';
import { adminApi, fmtDate, fmtDateTime, qs, useLoad } from '../lib';
import { Area, Empty, ErrorBox, Loading, Modal, PageHead, Pager, PayPill, StatusPill, Text, Toggle, useUi } from '../ui';

function CustomerModal({ id, onClose, onSaved }) {
  const { notify } = useUi();
  const { data: c, error, loading, setData } = useLoad(`customers/${id}`);
  const [f, setF] = useState(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (c) setF({ name: c.name, phone: c.phone || '', admin_note: c.admin_note || '', is_blocked: c.is_blocked });
  }, [c]);
  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      const saved = await adminApi(`customers/${id}`, { method: 'PUT', body: { ...f, phone: f.phone || null, admin_note: f.admin_note || null } });
      setData(saved);
      onSaved();
      notify('Customer saved.');
    } catch (err) {
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  const wa = c && waLink(c.phone);
  return (
    <Modal title={c ? c.name : 'Customer'} onClose={onClose} wide>
      <ErrorBox error={error} />
      {loading && !c ? <Loading /> : c && f && (
        <>
          <p>
            <a href={`mailto:${c.email}`}>{c.email}</a>
            {c.phone && <> · {c.phone}</>}{wa && <> · <a href={wa} target="_blank" rel="noopener noreferrer">WhatsApp</a></>}
            {c.city && <> · {c.city}, {countryName(c.country)}</>}
          </p>
          <p className="ad-muted">{c.orders_count} order{c.orders_count === 1 ? '' : 's'} · spent {money(c.total_spent)} · customer since {fmtDate(c.created_at)}</p>
          <form className="ad-form" onSubmit={save}>
            <Text label="Name" value={f.name} onChange={(v) => setF({ ...f, name: v })} maxLength={120} />
            <Text label="Phone" value={f.phone} onChange={(v) => setF({ ...f, phone: v })} maxLength={40} />
            <Area label="Private note (staff only)" rows={2} value={f.admin_note} onChange={(v) => setF({ ...f, admin_note: v })} maxLength={2000} />
            <div className="ad-wide"><Toggle label={f.is_blocked ? 'Blocked: cannot place orders' : 'Can place orders'} checked={f.is_blocked} onChange={(v) => setF({ ...f, is_blocked: v })} /></div>
            <div className="ad-form__foot"><button className="ad-btn" disabled={busy}>Save</button></div>
          </form>
          <h3>Orders</h3>
          {c.orders.length === 0 ? <Empty>No orders.</Empty> : (
            <ul className="ad-list">
              {c.orders.map((o) => (
                <li key={o.id}>
                  <Link to={`/admin/orders/${o.id}`}><b>{o.number}</b><small>{fmtDateTime(o.created_at)}</small></Link>
                  <span className="ad-list__end"><StatusPill status={o.status} /> <PayPill status={o.payment_status} /> <b>{money(o.total, o.currency)}</b></span>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </Modal>
  );
}

export default function Customers() {
  const [params, setParams] = useSearchParams();
  const sort = params.get('sort') || '';
  const page = params.get('page') || '';
  const open = params.get('open');
  const [term, setTerm] = useState(params.get('search') || '');
  const [search, setSearch] = useState(term);
  useEffect(() => {
    const t = setTimeout(() => setSearch(term.trim()), 350);
    return () => clearTimeout(t);
  }, [term]);
  const { data, error, loading, reload } = useLoad('customers?' + qs({ search, sort, page }));
  const set = (k, v) => {
    const next = new URLSearchParams(params);
    v ? next.set(k, v) : next.delete(k);
    if (k !== 'page' && k !== 'open') next.delete('page');
    setParams(next);
  };
  return (
    <>
      <PageHead title="Customers" sub="Everyone who has ordered. Customers are added automatically at checkout." />
      <div className="ad-filters">
        <input type="search" aria-label="Search customers" placeholder="Name, email or phone…" value={term} onChange={(e) => setTerm(e.target.value)} />
        <select aria-label="Sort" value={sort} onChange={(e) => set('sort', e.target.value)}>
          <option value="">Latest order</option>
          <option value="orders">Most orders</option>
          <option value="spent">Most spent</option>
          <option value="name">Name</option>
        </select>
      </div>
      <ErrorBox error={error} onRetry={reload} />
      {loading && !data ? <Loading /> : data && (data.data.length === 0 ? <Empty>No customers yet.</Empty> : (
        <div className="ad-table-wrap">
          <table className="ad-table">
            <thead><tr><th>Name</th><th>Contact</th><th>Location</th><th className="num">Orders</th><th className="num">Spent</th><th>Last order</th></tr></thead>
            <tbody>
              {data.data.map((c) => (
                <tr key={c.id}>
                  <td data-label="Name"><button type="button" className="ad-linkbtn" onClick={() => set('open', String(c.id))}><b>{c.name}</b></button>{c.is_blocked && <span className="ad-pill ad-pill--cancelled">Blocked</span>}</td>
                  <td data-label="Contact">{c.email}<small className="ad-block ad-muted">{c.phone}</small></td>
                  <td data-label="Location">{c.city ? `${c.city}, ${countryName(c.country)}` : '—'}</td>
                  <td data-label="Orders" className="num">{c.orders_count}</td>
                  <td data-label="Spent" className="num">{money(c.total_spent)}</td>
                  <td data-label="Last order">{fmtDate(c.last_order_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
      <Pager meta={data?.meta} onPage={(p) => set('page', String(p))} />
      {open && <CustomerModal id={open} onClose={() => set('open', '')} onSaved={reload} />}
    </>
  );
}
