import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { methodLabel, money, statusLabel, waLink } from '../../lib/format';
import { countryName } from '../../lib/countries';
import { useAuth } from '../AdminApp';
import { adminApi, fieldErrors, fmtDateTime, useLoad } from '../lib';
import { Area, Card, Check, ErrorBox, Loading, PageHead, PayPill, StatusPill, Text, useUi } from '../ui';

const ACTION = {
  confirmed: ['Confirm order', ''], processing: ['Mark as processing', ''], shipped: ['Mark as shipped', ''],
  delivered: ['Mark as delivered', ''], cancelled: ['Cancel order', 'danger'], returned: ['Mark as returned', 'danger'],
};

function StatusBox({ order, onSaved }) {
  const { notify, confirm } = useUi();
  const [note, setNote] = useState('');
  const [notify_, setNotify] = useState(true);
  const [busy, setBusy] = useState(false);
  const change = async (status) => {
    if (['cancelled', 'returned'].includes(status)) {
      const ok = await confirm(`${ACTION[status][0]} ${order.number}? Reserved stock goes back to the shelf.`, { ok: ACTION[status][0], danger: true });
      if (!ok) return;
    }
    setBusy(true);
    try {
      onSaved(await adminApi(`orders/${order.id}/status`, { method: 'PUT', body: { status, note: note || null, notify: notify_ } }));
      setNote('');
      notify(`Order is now ${statusLabel(status).toLowerCase()}.`);
    } catch (e) {
      notify(e.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  if (!order.allowed_statuses.length) return <Card title="Status"><p className="ad-muted">This order is {statusLabel(order.status).toLowerCase()}. No further changes.</p></Card>;
  return (
    <Card title="Update status">
      <div className="ad-actions">
        {order.allowed_statuses.map((s) => (
          <button key={s} type="button" disabled={busy} className={'ad-btn' + (ACTION[s]?.[1] === 'danger' ? ' ad-btn--danger-ghost' : '')} onClick={() => change(s)}>
            {ACTION[s]?.[0] || statusLabel(s)}
          </button>
        ))}
      </div>
      <Area label="Note for the customer (optional)" rows={2} value={note} onChange={setNote} maxLength={1000} help="Shown on the customer's order page with the new status." />
      <Check label="Email the customer about this change" checked={notify_} onChange={setNotify} />
    </Card>
  );
}

function TrackingBox({ order, onSaved }) {
  const { notify } = useUi();
  const [f, setF] = useState({ carrier: order.carrier || '', tracking_number: order.tracking_number || '', tracking_url: order.tracking_url || '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  useEffect(() => setF({ carrier: order.carrier || '', tracking_number: order.tracking_number || '', tracking_url: order.tracking_url || '' }), [order.carrier, order.tracking_number, order.tracking_url]);
  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      onSaved(await adminApi(`orders/${order.id}/tracking`, { method: 'PUT', body: { carrier: f.carrier || null, tracking_number: f.tracking_number || null, tracking_url: f.tracking_url || null } }));
      notify('Tracking saved.');
    } catch (err) {
      setErrors(fieldErrors(err));
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card title="Shipping & tracking">
      <form className="ad-form" onSubmit={save}>
        <Text label="Courier" value={f.carrier} onChange={(v) => setF({ ...f, carrier: v })} placeholder="Aramex, DHL, Emirates Post…" maxLength={100} error={errors.carrier} />
        <Text label="Tracking number" value={f.tracking_number} onChange={(v) => setF({ ...f, tracking_number: v })} maxLength={100} error={errors.tracking_number} />
        <Text label="Tracking link" type="url" wide value={f.tracking_url} onChange={(v) => setF({ ...f, tracking_url: v })} placeholder="https://…" maxLength={500} error={errors.tracking_url} />
        <div className="ad-form__foot"><button className="ad-btn" disabled={busy}>Save tracking</button></div>
      </form>
    </Card>
  );
}

function NoteBox({ order, onSaved }) {
  const { notify } = useUi();
  const [message, setMessage] = useState('');
  const [visible, setVisible] = useState(false);
  const [busy, setBusy] = useState(false);
  const save = async (e) => {
    e.preventDefault();
    if (!message.trim()) return;
    setBusy(true);
    try {
      onSaved(await adminApi(`orders/${order.id}/notes`, { method: 'POST', body: { message, customer_visible: visible } }));
      setMessage('');
      notify('Note added.');
    } catch (err) {
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <form onSubmit={save} className="ad-note-form">
      <Area label="Add a note" rows={2} value={message} onChange={setMessage} maxLength={2000} />
      <Check label="Customer can see this note" checked={visible} onChange={setVisible} />
      <button className="ad-btn ad-btn--ghost" disabled={busy || !message.trim()}>Add note</button>
    </form>
  );
}

export default function OrderDetail() {
  const { id } = useParams();
  const { user } = useAuth();
  const { notify, confirm } = useUi();
  const { data: o, error, loading, reload, setData } = useLoad(`orders/${id}`);
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (loading && !o) return <Loading />;
  const c = o.currency;
  const wa = waLink(o.customer_phone, `Hello ${o.customer_name}, this is Perfumarathi about your order ${o.number}.`);
  const refund = async () => {
    if (!(await confirm(`Refund ${money(o.total, c)} to the customer's card through Stripe? This cannot be undone.`, { ok: 'Refund now', danger: true }))) return;
    try {
      setData(await adminApi(`orders/${o.id}/refund`, { method: 'POST' }));
      notify('Refund sent to Stripe.');
    } catch (e) {
      notify(e.message, 'error');
    }
  };
  const card = o.payments?.find((p) => p.details?.last4);
  const canMarkPaid = o.payment_method === 'transfer' && !['paid', 'refunded'].includes(o.payment_status) && !['cancelled', 'returned'].includes(o.status);
  const markPaid = async () => {
    if (!(await confirm(`Did you receive ${money(o.total, c)} for order ${o.number} (UPI / Google Pay / bank transfer)?`, { ok: 'Yes, payment received' }))) return;
    try {
      setData(await adminApi(`orders/${o.id}/paid`, { method: 'POST', body: {} }));
      notify('Payment marked as received. The customer gets an email.');
    } catch (e) {
      notify(e.message, 'error');
    }
  };
  return (
    <>
      <PageHead title={`Order ${o.number}`} sub={`Placed ${fmtDateTime(o.created_at)}`}>
        <Link className="ad-btn ad-btn--ghost" to="/admin/orders">← All orders</Link>
      </PageHead>
      <div className="ad-pills-row" data-testid="admin-order-status">
        <StatusPill status={o.status} /> <PayPill status={o.payment_status} />
        <span className="ad-muted">{methodLabel(o.payment_method)}{card ? ` · ${card.details.brand || ''} •••• ${card.details.last4}` : ''}</span>
        {canMarkPaid && <button type="button" className="ad-btn ad-btn--sm" onClick={markPaid} data-testid="mark-paid">Payment received</button>}
      </div>
      {canMarkPaid && <p className="ad-alert">Send the customer your UPI / Google Pay / bank details on WhatsApp. When the money arrives, press <b>Payment received</b>; then you can ship the order.</p>}

      <div className="ad-grid2 ad-grid2--detail">
        <div className="ad-stack">
          <Card title="Items">
            <ul className="ad-items">
              {o.items.map((i) => (
                <li key={i.id}>
                  {i.image_url ? <img src={i.image_url} alt="" width="56" height="56" /> : <span className="ad-thumb" />}
                  <div>
                    {i.product_id ? <Link to={`/admin/products/${i.product_id}`}>{i.product_name}</Link> : i.product_name}
                    <small className="ad-block ad-muted">{[i.variant_name, i.sku].filter(Boolean).join(' · ')}</small>
                  </div>
                  <span>{i.quantity} × {money(i.unit_price, c)}</span>
                  <b>{money(i.line_total, c)}</b>
                </li>
              ))}
            </ul>
            <dl className="ad-totals">
              <dt>Subtotal</dt><dd>{money(o.subtotal, c)}</dd>
              {Number(o.discount_total) > 0 && <><dt>Discount{o.coupon_code ? ` (${o.coupon_code})` : ''}</dt><dd>−{money(o.discount_total, c)}</dd></>}
              <dt>Shipping</dt><dd>{Number(o.shipping_total) > 0 ? money(o.shipping_total, c) : 'Free'}</dd>
              {Number(o.payment_fee) > 0 && <><dt>Payment fee</dt><dd>{money(o.payment_fee, c)}</dd></>}
              <dt className="ad-totals__sum">Total</dt><dd className="ad-totals__sum">{money(o.total, c)}</dd>
            </dl>
            {user.role === 'admin' && o.can_refund && <button type="button" className="ad-btn ad-btn--danger-ghost" onClick={refund}>Refund to card</button>}
          </Card>
          <StatusBox order={o} onSaved={setData} />
          <TrackingBox order={o} onSaved={setData} />
        </div>
        <div className="ad-stack">
          <Card title="Customer">
            <p><b>{o.customer_name}</b>{o.customer && <> · <Link to={`/admin/customers?open=${o.customer.id}`}>{o.customer_orders} order{o.customer_orders === 1 ? '' : 's'}</Link></>}</p>
            <p><a href={`mailto:${o.customer_email}`}>{o.customer_email}</a></p>
            <p><a href={`tel:${o.customer_phone.replace(/[^\d+]/g, '')}`}>{o.customer_phone}</a>{wa && <> · <a href={wa} target="_blank" rel="noopener noreferrer">WhatsApp</a></>}</p>
            {o.customer?.is_blocked && <p className="ad-alert ad-alert--error">This customer is blocked.</p>}
          </Card>
          <Card title="Delivery address">
            <address className="ad-address">
              {o.ship_address}<br />
              {[o.ship_city, o.ship_state, o.ship_postal_code].filter(Boolean).join(', ')}<br />
              {countryName(o.ship_country)}
            </address>
            {o.customer_note && <p className="ad-quote"><b>Customer note:</b> {o.customer_note}</p>}
          </Card>
          <Card title="History">
            <NoteBox order={o} onSaved={setData} />
            <ol className="ad-history">
              {[...o.events].reverse().map((e) => (
                <li key={e.id}>
                  <time dateTime={e.created_at}>{fmtDateTime(e.created_at)}</time>
                  <span>{e.message || (e.type === 'status' ? `Status: ${statusLabel(e.to_value)}` : `${e.type}: ${e.to_value || ''}`)}</span>
                  <small className="ad-muted">{e.user?.name || 'System'}{e.is_customer_visible ? ' · visible to customer' : ''}</small>
                </li>
              ))}
            </ol>
          </Card>
        </div>
      </div>
    </>
  );
}
