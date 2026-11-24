import { Link } from 'react-router-dom';
import { useCart } from '../lib/cart';
import { useSeo } from '../lib/seo';
import { CartLines, CouponForm, Totals } from '../components/CartDrawer';
import { BagIcon } from '../components/Icons';

export default function Cart() {
  const { items, quote, loading } = useCart();
  useSeo({ title: 'Your Bag', noindex: true });
  if (!items.length) {
    return (
      <div className="pmt-container pmt-entry">
        <div className="pm-empty">
          <BagIcon />
          <h1 className="pmt-page-title">Your bag is empty</h1>
          <p>Discover a fragrance you will love.</p>
          <Link className="pm-btn" to="/shop">Continue Shopping</Link>
        </div>
      </div>
    );
  }
  const blocked = quote?.issues?.length > 0;
  return (
    <div className="pmt-container pmt-entry">
      <h1 className="pmt-page-title">Your Bag</h1>
      <div className="pc-layout">
        <div className="pc-box">
          <CartLines quote={quote} />
          <Link className="pm-link" to="/shop">← Continue shopping</Link>
        </div>
        <aside className="pc-box pc-summary" aria-label="Order summary">
          <h2>Summary</h2>
          <CouponForm />
          <Totals quote={quote} />
          <p className="pm-note">Shipping is calculated for your delivery country at checkout.</p>
          {loading && <p className="pm-note">Updating…</p>}
          {blocked && <p className="pm-error" role="alert">Please fix the items marked in your bag.</p>}
          {blocked || !quote ? (
            <button className="pm-btn" disabled>Proceed to Checkout</button>
          ) : (
            <Link className="pm-btn" to="/checkout">Proceed to Checkout</Link>
          )}
        </aside>
      </div>
    </div>
  );
}
