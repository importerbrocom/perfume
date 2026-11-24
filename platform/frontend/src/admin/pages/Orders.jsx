import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { money } from '../../lib/format';
import { countryName } from '../../lib/countries';
import { downloadFile, fmtDateTime, qs, useLoad } from '../lib';
import { Empty, ErrorBox, Loading, PageHead, Pager, PayPill, StatusPill, Tabs, useUi } from '../ui';

const STATUSES = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];
const LABELS = { pending: 'New', confirmed: 'Confirmed', processing: 'Processing', shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled', returned: 'Returned' };

/** All orders, or only orders with at least one product of `brand` (Fixnote orders screen). */
export default function Orders({ brand }) {
  const [params, setParams] = useSearchParams();
  const { notify } = useUi();
  const f = Object.fromEntries(['status', 'search', 'payment_status', 'payment_method', 'from', 'to', 'page'].map((k) => [k, params.get(k) || '']));
  const [term, setTerm] = useState(f.search);
  const set = (k, v) => {
    const next = new URLSearchParams(params);
    v ? next.set(k, v) : next.delete(k);
    if (k !== 'page') next.delete('page');
    setParams(next, { replace: k === 'search' });
  };
  useEffect(() => {
    const t = setTimeout(() => term !== f.search && set('search', term.trim()), 350);
    return () => clearTimeout(t);
  }, [term]); // eslint-disable-line react-hooks/exhaustive-deps

  const query = qs({ ...f, brand });
  const { data, error, loading, reload } = useLoad('orders?' + query);
  const counts = data?.counts || {};
  const all = Object.values(counts).reduce((a, b) => a + Number(b), 0);

  const exportCsv = async () => {
    try {
      const { page, ...rest } = f; // eslint-disable-line no-unused-vars
      await downloadFile('orders/export?' + qs({ ...rest, brand }), `${brand ? brand + '-' : ''}orders-${new Date().toISOString().slice(0, 10)}.csv`);
    } catch (e) {
      notify(e.message, 'error');
    }
  };

  return (
    <>
      <PageHead title={brand ? 'Fixnote orders' : 'Orders'} sub={brand ? 'Orders that include at least one Fixnote perfume. Click an order to confirm it, ship it or add tracking.' : 'Click an order to confirm it, ship it or add tracking.'}>
        <button type="button" className="ad-btn ad-btn--ghost" onClick={exportCsv}>Export CSV</button>
      </PageHead>
      <Tabs value={f.status} onChange={(v) => set('status', v)} items={[['', 'All', all], ...STATUSES.map((s) => [s, LABELS[s], counts[s] || 0])]} />
      <div className="ad-filters">
        <input type="search" aria-label="Search orders" placeholder="Order number, name, email, phone or tracking…" value={term} onChange={(e) => setTerm(e.target.value)} />
        <select aria-label="Payment status" value={f.payment_status} onChange={(e) => set('payment_status', e.target.value)}>
          <option value="">Any payment</option>
          <option value="paid">Paid</option>
          <option value="cod_pending">Pay on delivery</option>
          <option value="pending">Awaiting payment</option>
          <option value="failed">Payment failed</option>
          <option value="refunded">Refunded</option>
        </select>
        <select aria-label="Payment method" value={f.payment_method} onChange={(e) => set('payment_method', e.target.value)}>
          <option value="">Any method</option>
          <option value="cod">Cash on delivery</option>
          <option value="card">Card / Apple Pay / Google Pay</option>
        </select>
        <label className="ad-inline">From <input type="date" value={f.from} onChange={(e) => set('from', e.target.value)} /></label>
        <label className="ad-inline">To <input type="date" value={f.to} onChange={(e) => set('to', e.target.value)} /></label>
      </div>
      <ErrorBox error={error} onRetry={reload} />
      {loading && !data ? <Loading /> : data && (data.data.length === 0 ? <Empty>No orders match these filters.</Empty> : (
        <div className="ad-table-wrap">
          <table className="ad-table" data-testid="orders-table">
            <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Ship to</th><th>Status</th><th>Payment</th><th className="num">Total</th></tr></thead>
            <tbody>
              {data.data.map((o) => (
                <tr key={o.id}>
                  <td data-label="Order"><Link to={`/admin/orders/${o.id}`}><b>{o.number}</b></Link> <small className="ad-muted">{o.items_count} item{o.items_count === 1 ? '' : 's'}</small></td>
                  <td data-label="Date">{fmtDateTime(o.created_at)}</td>
                  <td data-label="Customer">{o.customer_name}<small className="ad-block ad-muted">{o.customer_phone}</small></td>
                  <td data-label="Ship to">{o.ship_city}, {countryName(o.ship_country)}</td>
                  <td data-label="Status"><StatusPill status={o.status} /></td>
                  <td data-label="Payment"><PayPill status={o.payment_status} /> <small className="ad-muted">{o.payment_method === 'cod' ? 'COD' : 'Card'}</small></td>
                  <td data-label="Total" className="num"><b>{money(o.total, o.currency)}</b></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
      <Pager meta={data?.meta} onPage={(p) => set('page', String(p))} />
    </>
  );
}
