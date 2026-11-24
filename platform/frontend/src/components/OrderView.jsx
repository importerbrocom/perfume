import { methodLabel, money, paymentLabel, statusLabel } from '../lib/format';
import { countryName } from '../lib/countries';
import Timeline from './Timeline';

const dateFmt = new Intl.DateTimeFormat('en-AE', { dateStyle: 'medium', timeStyle: 'short' });
const when = (iso) => (iso ? dateFmt.format(new Date(iso)) : '');

/** Read-only order summary shared by the order page and order tracking. */
export default function OrderView({ order, children }) {
  const c = order.currency;
  return (
    <div className="pm-order" data-testid="order">
      <div className="pm-order__head">
        <div>
          <p className="pm-order__eyebrow">Order</p>
          <h2 className="pm-order__number" data-testid="order-number">{order.number}</h2>
          <p className="pm-note">Placed {when(order.placed_at)}</p>
        </div>
        <div className="pm-order__chips">
          <span className={'pm-chip pm-chip--' + order.status} data-testid="order-status">{statusLabel(order.status)}</span>
          <span className={'pm-chip pm-chip--pay-' + order.payment_status}>{paymentLabel(order.payment_status)}</span>
        </div>
      </div>

      <Timeline order={order} />
      {children}

      {order.tracking_number && (
        <div className="pm-order__ship">
          <strong>Shipment:</strong> {order.carrier ? `${order.carrier} · ` : ''}{order.tracking_number}
          {order.tracking_url && /^https?:\/\//i.test(order.tracking_url) && (
            <> · <a className="pm-link" href={order.tracking_url} target="_blank" rel="noopener noreferrer">Track parcel</a></>
          )}
        </div>
      )}

      <div className="pc-layout pm-order__body">
        <div className="pc-box">
          <h3>Items</h3>
          <ul className="pm-order__items">
            {order.items.map((i, n) => (
              <li key={n} className="pm-line">
                {i.image ? <img src={i.image} alt="" width="64" height="64" loading="lazy" /> : <span className="pm-line__ph" />}
                <div className="pm-line__info">
                  <strong>{i.name}</strong>
                  {i.variant && <small>{i.variant}</small>}
                  <small>{i.quantity} × {money(i.unit_price, c)}</small>
                </div>
                <div className="pm-line__right">{money(i.line_total, c)}</div>
              </li>
            ))}
          </ul>
        </div>
        <aside className="pc-box pc-summary" aria-label="Order totals">
          <h3>Totals</h3>
          <div className="pm-row"><span>Subtotal</span><span>{money(order.subtotal, c)}</span></div>
          {order.discount > 0 && <div className="pm-row"><span>Discount</span><span>−{money(order.discount, c)}</span></div>}
          <div className="pm-row"><span>Shipping</span><span>{order.shipping > 0 ? money(order.shipping, c) : 'Free'}</span></div>
          {order.payment_fee > 0 && <div className="pm-row"><span>Payment fee</span><span>{money(order.payment_fee, c)}</span></div>}
          <div className="pm-row pm-row--total"><span>Total</span><span data-testid="order-total">{money(order.total, c)}</span></div>
          <p className="pm-note">
            {methodLabel(order.payment_method)}
            {order.ship_city ? ` · Delivering to ${order.ship_city}, ${countryName(order.ship_country)}` : ''}
          </p>
        </aside>
      </div>

      {order.history?.length > 0 && (
        <div className="pc-box pm-order__history">
          <h3>Updates</h3>
          <ul>
            {[...order.history].reverse().map((h, n) => (
              <li key={n}>
                <time dateTime={h.at}>{when(h.at)}</time>
                <span>{h.message || statusLabel(h.to)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
