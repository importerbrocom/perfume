import { Link } from 'react-router-dom';
import { money } from '../../lib/format';
import { fmtDateTime, useLoad } from '../lib';
import { Card, Empty, ErrorBox, Loading, PageHead, PayPill, StatusPill } from '../ui';

function Stat({ label, value, to, tone }) {
  const body = (
    <>
      <span className="ad-stat__label">{label}</span>
      <strong className="ad-stat__value">{value}</strong>
    </>
  );
  return to ? <Link className={'ad-stat' + (tone ? ' ad-stat--' + tone : '')} to={to}>{body}</Link> : <div className="ad-stat">{body}</div>;
}

function SalesChart({ days, currency }) {
  const max = Math.max(1, ...days.map((d) => d.total));
  const total = days.reduce((s, d) => s + d.total, 0);
  return (
    <figure className="ad-chart" aria-label={`Paid sales over the last 30 days: ${money(total, currency)}`}>
      <div className="ad-chart__bars">
        {days.map((d) => (
          <span key={d.date} style={{ height: `${Math.max(2, (d.total / max) * 100)}%` }} title={`${d.date}: ${money(d.total, currency)}`} className={d.total ? '' : 'is-zero'} />
        ))}
      </div>
      <figcaption><span>{days[0]?.date}</span><span>Total {money(total, currency)}</span><span>{days.at(-1)?.date}</span></figcaption>
    </figure>
  );
}

export default function Dashboard() {
  const { data: d, error, loading, reload } = useLoad('dashboard');
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (loading && !d) return <Loading />;
  const c = d.currency;
  return (
    <>
      <PageHead title="Dashboard" sub="Sales count paid orders only.">
        <Link className="ad-btn" to="/admin/products/new">+ Add product</Link>
      </PageHead>
      <div className="ad-stats">
        <Stat label="Sales today" value={money(d.sales_today, c)} />
        <Stat label="Sales this month" value={money(d.sales_month, c)} />
        <Stat label="All-time sales" value={money(d.sales_total, c)} />
        <Stat label="Orders to handle" value={d.orders_pending} to="/admin/orders" tone={d.orders_pending ? 'warn' : ''} />
        <Stat label="Orders today" value={d.orders_today} to="/admin/orders" />
        <Stat label="Delivered" value={d.orders_delivered} to="/admin/orders?status=delivered" />
        <Stat label="Customers" value={d.customers_total} to="/admin/customers" />
        <Stat label="Active products" value={`${d.products_active} / ${d.products_total}`} to="/admin/products" />
        <Stat label="Low stock sizes" value={d.variants_low} to="/admin/stock?filter=low" tone={d.variants_low ? 'warn' : ''} />
        <Stat label="Out of stock sizes" value={d.variants_out} to="/admin/stock?filter=out" tone={d.variants_out ? 'bad' : ''} />
        <Stat label="Without a price" value={d.products_unpriced} to="/admin/stock?filter=unpriced" tone={d.products_unpriced ? 'warn' : ''} />
        <Stat label="Reviews to approve" value={d.reviews_pending} to="/admin/reviews" tone={d.reviews_pending ? 'warn' : ''} />
      </div>

      <div className="ad-grid2">
        <Card title="Sales, last 30 days"><SalesChart days={d.sales_by_day} currency={c} /></Card>
        <Card title="Low stock" actions={<Link className="ad-link" to="/admin/stock?filter=low">Update stock</Link>}>
          {d.low_stock.length === 0 ? <Empty>No sizes are running low.</Empty> : (
            <ul className="ad-list">
              {d.low_stock.map((v) => <li key={v.id}><span>{v.product} · {v.variant}</span><b className="ad-warn">{v.stock} left</b></li>)}
            </ul>
          )}
        </Card>
        <Card title="Recent orders" actions={<Link className="ad-link" to="/admin/orders">All orders</Link>}>
          {d.recent_orders.length === 0 ? <Empty>No orders yet.</Empty> : (
            <ul className="ad-list">
              {d.recent_orders.map((o) => (
                <li key={o.id}>
                  <Link to={`/admin/orders/${o.id}`}><b>{o.number}</b> · {o.customer_name}<small>{fmtDateTime(o.created_at)}</small></Link>
                  <span className="ad-list__end"><StatusPill status={o.status} /> <PayPill status={o.payment_status} /> <b>{money(o.total, o.currency)}</b></span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Best sellers">
          {d.best_sellers.length === 0 ? <Empty>Best sellers appear after the first orders.</Empty> : (
            <ul className="ad-list">
              {d.best_sellers.map((b) => (
                <li key={b.product_id || b.name}>
                  {b.product_id ? <Link to={`/admin/products/${b.product_id}`}>{b.name}</Link> : <span>{b.name}</span>}
                  <span className="ad-list__end">{b.quantity} sold · {money(b.revenue, c)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  );
}
