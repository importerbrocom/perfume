import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useSeo } from '../lib/seo';
import OrderView from '../components/OrderView';

function recentOrders() {
  try {
    const v = JSON.parse(localStorage.getItem('pm_orders') || '[]');
    return Array.isArray(v) ? v.filter((o) => o?.number && o?.token).slice(0, 5) : [];
  } catch {
    return [];
  }
}

export default function Track() {
  useSeo({ title: 'Track Your Order', description: 'Track your Perfumarathi order with your order number and email.' });
  const [form, setForm] = useState(() => {
    try {
      return { number: '', email: JSON.parse(localStorage.getItem('pm_checkout_contact') || '{}').email || '' };
    } catch {
      return { number: '', email: '' };
    }
  });
  const [order, setOrder] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const recent = recentOrders();

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setOrder(null);
    try {
      setOrder(await api('track', { method: 'POST', body: { number: form.number.trim().toUpperCase(), email: form.email.trim() } }));
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="pmt-container pmt-entry">
      <h1 className="pmt-page-title">Track Your Order</h1>
      <form className="pm-track" onSubmit={submit}>
        <p>Enter your order number (for example PM100123) and the email you used at checkout.</p>
        <div className="pm-track__row">
          <label>
            <span>Order number</span>
            <input name="number" required maxLength={20} autoComplete="off" value={form.number} onChange={(e) => setForm({ ...form, number: e.target.value })} placeholder="PM100123" />
          </label>
          <label>
            <span>Email</span>
            <input name="email" type="email" required maxLength={150} autoComplete="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} placeholder="you@example.com" />
          </label>
          <button className="pm-btn" disabled={busy}>{busy ? 'Searching…' : 'Track'}</button>
        </div>
        {error && <p className="pm-error" role="alert">{error}</p>}
      </form>

      {order && <OrderView order={order} />}

      {!order && recent.length > 0 && (
        <div className="pc-box pm-recent">
          <h3>Orders from this device</h3>
          <ul>
            {recent.map((o) => (
              <li key={o.number}><Link className="pm-link" to={`/order/${o.number}?token=${encodeURIComponent(o.token)}`}>{o.number}</Link></li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
