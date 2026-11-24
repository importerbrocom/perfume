import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useCart } from '../lib/cart';
import { useStore } from '../lib/store';
import { useToast } from '../lib/toast';
import { money } from '../lib/format';
import { BagIcon } from './Icons';

export function CartLines({ quote }) {
  const { setQty, remove, items } = useCart();
  const { currency } = useStore();
  const lines = quote?.lines?.length ? quote.lines : items.map((i) => ({ variant_id: i.variant_id, product_name: i.snap?.name || 'Item', variant_name: i.snap?.variant, image: i.snap?.image, quantity: i.quantity, line_total: null, product_slug: i.snap?.slug }));
  // Products without a photo (e.g. Fixnote) show the picture saved when they were added.
  const image = (l) => l.image || items.find((i) => i.variant_id === l.variant_id)?.snap?.image;
  return lines.map((l) => (
    <div className="pm-line" key={l.variant_id} data-testid="cart-line">
      {image(l) ? <img src={image(l)} alt="" loading="lazy" /> : <span className="pm-line__ph" />}
      <div className="pm-line__info">
        <strong>{l.product_slug ? <Link to={`/product/${l.product_slug}`}>{l.product_name}</Link> : l.product_name}</strong>
        {l.variant_name && l.variant_name !== 'Standard' && <small>{l.variant_name}</small>}
        {l.problem && <small className="pm-error" role="alert">{l.problem}</small>}
        <div className="pm-qty">
          <button type="button" aria-label={`Decrease quantity of ${l.product_name}`} onClick={() => setQty(l.variant_id, l.quantity - 1)}>−</button>
          <span aria-live="polite">{l.quantity}</span>
          <button type="button" aria-label={`Increase quantity of ${l.product_name}`} disabled={l.available !== null && l.available !== undefined && l.quantity >= l.available} onClick={() => setQty(l.variant_id, l.quantity + 1)}>+</button>
        </div>
      </div>
      <div className="pm-line__right">
        <span>{l.line_total !== null && l.unit_price !== null ? money(l.line_total, currency) : ''}</span>
        <button type="button" className="pm-link" onClick={() => remove(l.variant_id)}>Remove</button>
      </div>
    </div>
  ));
}

export function CouponForm() {
  const { coupon, setCoupon, quote } = useCart();
  const [code, setCode] = useState(coupon);
  const toast = useToast();
  useEffect(() => setCode(coupon), [coupon]);
  useEffect(() => {
    if (coupon && quote?.coupon_message) toast(quote.coupon_message, 'error');
  }, [quote?.coupon_message]); // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <>
      <form className="pm-coupon" onSubmit={(e) => { e.preventDefault(); setCoupon(code.trim()); }}>
        <label className="screen-reader-text" htmlFor="pm-coupon-in">Coupon code</label>
        <input id="pm-coupon-in" value={code} onChange={(e) => setCode(e.target.value)} placeholder="Coupon code" autoComplete="off" />
        <button className="pm-btn pm-btn--ghost" type="submit">Apply</button>
      </form>
      {quote?.coupon && (
        <span className="pm-chip">{quote.coupon.code} <button type="button" aria-label={`Remove coupon ${quote.coupon.code}`} onClick={() => setCoupon('')}>×</button></span>
      )}
    </>
  );
}

export function Totals({ quote, showPayment = false }) {
  const { currency } = useStore();
  if (!quote) return null;
  return (
    <>
      <div className="pm-row"><span>Subtotal</span><span data-testid="subtotal">{money(quote.subtotal, currency)}</span></div>
      {quote.discount > 0 && <div className="pm-row"><span>Discount</span><span>−{money(quote.discount, currency)}</span></div>}
      <div className="pm-row"><span>Shipping</span><span>{quote.shipping > 0 ? money(quote.shipping, currency) : 'Free'}</span></div>
      {showPayment && quote.payment_fee > 0 && <div className="pm-row"><span>Payment fee</span><span>{money(quote.payment_fee, currency)}</span></div>}
      <div className="pm-row pm-row--total"><span>Total</span><span data-testid="total">{money(quote.total, currency)}</span></div>
    </>
  );
}

/** Slide-in bag (same look as the WordPress cart drawer). */
export default function CartDrawer() {
  const { drawerOpen, setDrawerOpen, items, quote, loading } = useCart();
  const navigate = useNavigate();
  const close = useRef(null);
  useEffect(() => {
    if (!drawerOpen) return undefined;
    const prev = document.activeElement;
    close.current?.focus();
    document.documentElement.classList.add('pm-lock');
    const f = (e) => e.key === 'Escape' && setDrawerOpen(false);
    document.addEventListener('keydown', f);
    return () => {
      document.documentElement.classList.remove('pm-lock');
      document.removeEventListener('keydown', f);
      prev?.focus?.();
    };
  }, [drawerOpen, setDrawerOpen]);
  const go = (to) => {
    setDrawerOpen(false);
    navigate(to);
  };
  const blocked = quote?.issues?.length > 0;
  return (
    <>
      {drawerOpen && <div className="pm-overlay" onClick={() => setDrawerOpen(false)} />}
      <aside className={'pm-drawer' + (drawerOpen ? ' is-open' : '')} role="dialog" aria-modal="true" aria-label="Shopping bag" aria-hidden={!drawerOpen} inert={drawerOpen ? undefined : ''}>
        <header className="pm-drawer__head">
          <h2>Your Bag</h2>
          <button ref={close} type="button" className="pm-x" onClick={() => setDrawerOpen(false)} aria-label="Close shopping bag">×</button>
        </header>
        <div className="pm-drawer__body">
          {!items.length ? (
            <div className="pm-empty">
              <BagIcon />
              <h3>Your bag is empty</h3>
              <p>Discover a fragrance you will love.</p>
            </div>
          ) : (
            <CartLines quote={quote} />
          )}
        </div>
        <footer className="pm-drawer__foot">
          {items.length ? (
            <>
              <CouponForm />
              <Totals quote={quote} />
              {loading && <p className="pm-note">Updating…</p>}
              {blocked && <p className="pm-error" role="alert">Please fix the items marked above.</p>}
              <button className="pm-btn" disabled={blocked || !quote} onClick={() => go('/checkout')}>Proceed to Checkout</button>
              <button className="pm-link pm-center" onClick={() => go('/cart')}>View cart</button>
            </>
          ) : (
            <button className="pm-btn" onClick={() => go('/shop')}>Continue Shopping</button>
          )}
        </footer>
      </aside>
    </>
  );
}
