import { Link, useNavigate } from 'react-router-dom';
import { useCart } from '../lib/cart';
import { useStore } from '../lib/store';
import { useToast } from '../lib/toast';
import { useWishlist } from '../lib/wishlist';
import { priceRange, money, waLink } from '../lib/format';

export function Stars({ rating }) {
  const n = Math.round(rating || 0);
  return (
    <span className="pm-stars" aria-label={`Rated ${Number(rating || 0).toFixed(1)} out of 5`}>
      {'★★★★★'.slice(0, n)}
      <span className="pm-stars__off">{'★★★★★'.slice(n)}</span>
    </span>
  );
}

/** Product card (same markup/classes as the WordPress shop cards). */
export default function ProductCard({ p, onQuickView }) {
  const { add, setDrawerOpen } = useCart();
  const { currency, store } = useStore();
  const toast = useToast();
  const wish = useWishlist();
  const navigate = useNavigate();
  const url = `/product/${p.slug}`;
  const saved = wish.has(p.id);
  const snap = { name: p.name, image: p.image, slug: p.slug };
  const wa = waLink(store?.settings?.whatsapp_number, `Hi Perfumarathi, I'd like to know the price of ${p.name}.`);

  let actions;
  if (!p.in_stock && p.priced) {
    actions = <button className="pm-btn pm-btn--sm" disabled>Out of stock</button>;
  } else if (!p.priced) {
    actions = (
      <>
        <Link className="pm-btn pm-btn--sm pm-btn--ghost" to={url}>View Details</Link>
        {wa && <a className="pm-btn pm-btn--sm pm-btn--wa" href={wa} target="_blank" rel="noopener noreferrer">Enquire</a>}
      </>
    );
  } else if (p.purchasable && p.single_variant_id) {
    actions = (
      <>
        <button className="pm-btn pm-btn--sm" onClick={() => { add(p.single_variant_id, 1, snap); toast('Added to your bag'); setDrawerOpen(true); }}>Add to Cart</button>
        <button className="pm-btn pm-btn--sm pm-btn--ghost" onClick={() => { add(p.single_variant_id, 1, snap); navigate('/checkout'); }}>Buy Now</button>
      </>
    );
  } else {
    actions = <Link className="pm-btn pm-btn--sm" to={url}>Choose Size</Link>;
  }

  return (
    <article className="pm-card" data-testid="product-card">
      <div className="pm-card__media">
        <Link to={url}>
          {p.image ? <img src={p.image} alt={p.name} loading="lazy" width="300" height="300" /> : <span className="pm-line__ph" style={{ display: 'block', width: '100%', height: '100%' }} />}
        </Link>
        {p.on_sale && <span className="pm-badge">Sale</span>}
        <button
          className={'pm-wish' + (saved ? ' is-on' : '')}
          aria-pressed={saved}
          aria-label={`${saved ? 'Remove' : 'Add'} ${p.name} ${saved ? 'from' : 'to'} wishlist`}
          onClick={() => toast(wish.toggle(p.id) ? 'Saved to wishlist' : 'Removed from wishlist')}
        >♥</button>
        {onQuickView && <button className="pm-qv" onClick={() => onQuickView(p)}>Quick View</button>}
      </div>
      <div className="pm-card__body">
        {p.brand && <span className="pm-card__brand">{p.brand.name}</span>}
        <h3><Link to={url}>{p.name}</Link></h3>
        {p.reviews > 0 && <div className="pm-card__rating"><Stars rating={p.rating} /> <small>({p.reviews})</small></div>}
        <div className="pm-card__price">
          {p.priced ? (
            <>
              {p.on_sale && p.regular_min > p.price_min && <del>{money(p.regular_min, currency)}</del>}
              <ins>{priceRange(p, currency)}</ins>
            </>
          ) : (
            <span className="pm-card__req">Price on request</span>
          )}
        </div>
        {p.priced && <small className={'pm-stock ' + (p.in_stock ? 'in' : 'out')}>{p.in_stock ? 'In stock' : 'Out of stock'}</small>}
        <div className="pm-card__actions">{actions}</div>
      </div>
    </article>
  );
}

export function SkeletonCards({ n = 4 }) {
  return Array.from({ length: n }, (_, i) => (
    <div key={i} className="pm-card pm-skel" aria-hidden="true">
      <div className="pm-card__media" />
      <div className="pm-card__body"><i /><i /><i /></div>
    </div>
  ));
}
