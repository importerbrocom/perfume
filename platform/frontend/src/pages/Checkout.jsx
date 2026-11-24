import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useCart } from '../lib/cart';
import { useStore } from '../lib/store';
import { useSeo } from '../lib/seo';
import { money } from '../lib/format';
import { COUNTRIES, countryName } from '../lib/countries';
import { CouponForm, Totals } from '../components/CartDrawer';
import StripePay from '../components/StripePay';

const SAVED = 'pm_checkout_contact';
const blank = { name: '', email: '', phone: '', country: 'AE', state: '', city: '', address: '', postal_code: '', note: '' };

function Field({ label, name, form, setForm, errors, type = 'text', required = true, autoComplete, ...rest }) {
  const err = errors[name]?.[0] || errors[name];
  return (
    <label className={'co-field' + (err ? ' has-error' : '')}>
      <span>{label}{required && <b aria-hidden="true"> *</b>}</span>
      <input
        type={type} name={name} required={required} autoComplete={autoComplete} value={form[name]}
        aria-invalid={!!err} aria-describedby={err ? `${name}-err` : undefined}
        onChange={(e) => setForm({ ...form, [name]: e.target.value })} {...rest}
      />
      {err && <small id={`${name}-err`} className="pm-error">{err}</small>}
    </label>
  );
}

export default function Checkout() {
  const { items, quote, loading, clear, setCountry, method, setMethod, coupon } = useCart();
  const { store, currency } = useStore();
  const navigate = useNavigate();
  const [form, setForm] = useState(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(SAVED) || '{}');
      return { ...blank, ...(saved && typeof saved === 'object' ? saved : {}) };
    } catch {
      return blank;
    }
  });
  const [terms, setTerms] = useState(false);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState(null);
  const [placed, setPlaced] = useState(null); // card order waiting for payment
  useSeo({ title: 'Checkout', noindex: true });

  const s = store?.settings || {};
  const allowed = s.shipping_countries?.length ? s.shipping_countries : Object.keys(COUNTRIES);
  useEffect(() => {
    if (!allowed.includes(form.country)) setForm((f) => ({ ...f, country: allowed[0] || 'AE' }));
  }, [allowed.join()]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => setCountry(form.country), [form.country, setCountry]);
  const methods = quote?.payment_methods || [];
  useEffect(() => {
    if (methods.length && !methods.some((m) => m.code === method)) setMethod(methods[0].code);
  }, [methods.map((m) => m.code).join()]); // eslint-disable-line react-hooks/exhaustive-deps

  if (placed) {
    return (
      <div className="pmt-container pmt-entry co-pay">
        <h1 className="pmt-page-title">Payment</h1>
        <p className="pmc-sub">Order <strong>{placed.number}</strong> · {money(placed.total, placed.currency)}. Your items are reserved while you pay.</p>
        <StripePay number={placed.number} token={placed.token} onPaid={() => navigate(`/order/${placed.number}?token=${encodeURIComponent(placed.token)}`)} />
        <p className="pm-note pm-center"><Link to={`/order/${placed.number}?token=${encodeURIComponent(placed.token)}`}>View order</Link></p>
      </div>
    );
  }

  if (!items.length) {
    return (
      <div className="pmt-container pmt-entry pm-empty">
        <h1 className="pmt-page-title">Your bag is empty</h1>
        <Link className="pm-btn" to="/shop">Continue Shopping</Link>
      </div>
    );
  }

  const submit = async (e) => {
    e.preventDefault();
    setErrors({});
    setMessage(null);
    if (!terms) {
      setErrors({ accept_terms: ['Please accept the terms to place your order.'] });
      return;
    }
    if (!method) {
      setMessage('Please choose a payment method.');
      return;
    }
    setBusy(true);
    try {
      const res = await api('checkout', {
        method: 'POST',
        body: { ...form, items: items.map(({ variant_id, quantity }) => ({ variant_id, quantity })), coupon_code: coupon || null, payment_method: method, accept_terms: true },
      });
      const { note, ...contact } = form; // eslint-disable-line no-unused-vars
      localStorage.setItem(SAVED, JSON.stringify(contact));
      const mine = JSON.parse(localStorage.getItem('pm_orders') || '[]');
      localStorage.setItem('pm_orders', JSON.stringify([{ number: res.number, token: res.token }, ...mine].slice(0, 20)));
      clear();
      if (res.requires_payment) setPlaced(res);
      else navigate(`/order/${res.number}?token=${encodeURIComponent(res.token)}`);
    } catch (err) {
      setErrors(err.errors || {});
      setMessage(err.message);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      setBusy(false);
    }
  };

  const blocked = quote?.issues?.length > 0 || quote?.ships_to_country === false;
  return (
    <div className="pmt-container pmt-entry">
      <h1 className="pmt-page-title">Checkout</h1>
      {message && <p className="pm-error co-message" role="alert">{message}</p>}
      <form className="pc-layout" onSubmit={submit} noValidate>
        <div className="pc-box">
          <h2>Contact</h2>
          <div className="co-grid">
            <Field label="Full name" name="name" autoComplete="name" maxLength={120} {...{ form, setForm, errors }} />
            <Field label="Email" name="email" type="email" autoComplete="email" maxLength={150} {...{ form, setForm, errors }} />
            <Field label="Mobile number" name="phone" type="tel" autoComplete="tel" placeholder="+971 50 123 4567" maxLength={30} {...{ form, setForm, errors }} />
          </div>
          <h2>Delivery address</h2>
          <div className="co-grid">
            <label className={'co-field' + (errors.country ? ' has-error' : '')}>
              <span>Country<b aria-hidden="true"> *</b></span>
              <select name="country" autoComplete="country" value={form.country} onChange={(e) => setForm({ ...form, country: e.target.value })}>
                {allowed.map((c) => <option key={c} value={c}>{countryName(c)}</option>)}
              </select>
              {errors.country && <small className="pm-error">{errors.country[0] || errors.country}</small>}
            </label>
            <Field label="State / Emirate" name="state" required={false} autoComplete="address-level1" maxLength={100} {...{ form, setForm, errors }} />
            <Field label="City" name="city" autoComplete="address-level2" maxLength={100} {...{ form, setForm, errors }} />
            <Field label="Postal code" name="postal_code" required={false} autoComplete="postal-code" maxLength={20} {...{ form, setForm, errors }} />
          </div>
          <label className={'co-field' + (errors.address ? ' has-error' : '')}>
            <span>Complete address<b aria-hidden="true"> *</b></span>
            <textarea name="address" rows={3} autoComplete="street-address" maxLength={500} placeholder="Building / villa, street, area, landmark" value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
            {errors.address && <small className="pm-error">{errors.address[0] || errors.address}</small>}
          </label>
          <label className="co-field">
            <span>Order notes (optional)</span>
            <textarea name="note" rows={2} maxLength={1000} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
          </label>

          <h2>Payment</h2>
          {methods.length === 0 && !loading && <p className="pm-error">No payment method is available for this order. Please contact us on WhatsApp.</p>}
          <div className="co-methods" role="radiogroup" aria-label="Payment method">
            {methods.map((m) => (
              <label key={m.code} className={'co-method' + (method === m.code ? ' is-on' : '')}>
                <input type="radio" name="payment_method" value={m.code} checked={method === m.code} onChange={() => setMethod(m.code)} />
                <span>
                  <strong>{m.title}</strong>
                  {m.fee > 0 && <em> (+{money(m.fee, currency)})</em>}
                  {m.description && <small>{m.description}</small>}
                </span>
              </label>
            ))}
          </div>
          {errors.payment_method && <p className="pm-error">{errors.payment_method[0] || errors.payment_method}</p>}
        </div>

        <aside className="pc-box pc-summary" aria-label="Order summary">
          <h2>Your order</h2>
          {(quote?.lines || []).map((l) => (
            <div className="co-line" key={l.variant_id}>
              <span>{l.product_name}{l.variant_name && l.variant_name !== 'Standard' ? ` · ${l.variant_name}` : ''} × {l.quantity}</span>
              <span>{l.problem ? <em className="pm-error">{l.problem}</em> : money(l.line_total, currency)}</span>
            </div>
          ))}
          <CouponForm />
          <Totals quote={quote} showPayment />
          {quote?.ships_to_country === false && <p className="pm-error">We do not deliver to {countryName(form.country)} yet.</p>}
          <label className={'co-terms' + (errors.accept_terms ? ' has-error' : '')}>
            <input type="checkbox" checked={terms} onChange={(e) => setTerms(e.target.checked)} />
            <span>I agree to the {s.has_terms ? <Link to="/pages/terms" target="_blank">terms &amp; conditions</Link> : 'terms & conditions'}{s.has_return_policy && <> and <Link to="/pages/returns" target="_blank">return policy</Link></>}.</span>
          </label>
          {errors.accept_terms && <p className="pm-error">{errors.accept_terms[0] || errors.accept_terms}</p>}
          <button className="pm-btn co-place" disabled={busy || loading || blocked || !methods.length} data-testid="place-order">
            {busy ? 'Placing order…' : method && method !== 'cod' ? 'Continue to payment' : 'Place order'}
          </button>
          <p className="pm-note">Prices and stock are checked again when you place the order.</p>
        </aside>
      </form>
    </div>
  );
}
