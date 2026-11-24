import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../lib/api';
import { useCart } from '../lib/cart';
import { useStore } from '../lib/store';
import { useToast } from '../lib/toast';
import { useWishlist } from '../lib/wishlist';
import { useSeo } from '../lib/seo';
import { money, waLink } from '../lib/format';
import { sanitizeHtml } from '../lib/sanitize';
import ProductGrid from '../components/ProductGrid';
import { Stars } from '../components/ProductCard';
import NotFound from './NotFound';

function Gallery({ images, name }) {
  const [i, setI] = useState(0);
  const [zoom, setZoom] = useState(null);
  const [lightbox, setLightbox] = useState(false);
  useEffect(() => setI(0), [images]);
  useEffect(() => {
    if (!lightbox) return undefined;
    const f = (e) => {
      if (e.key === 'Escape') setLightbox(false);
      if (e.key === 'ArrowRight') setI((x) => (x + 1) % images.length);
      if (e.key === 'ArrowLeft') setI((x) => (x - 1 + images.length) % images.length);
    };
    document.addEventListener('keydown', f);
    return () => document.removeEventListener('keydown', f);
  }, [lightbox, images.length]);
  if (!images.length) return <div className="pp-gallery"><div className="pp-main pm-line__ph" /></div>;
  const img = images[i];
  return (
    <div className="pp-gallery">
      <button
        type="button"
        className="pp-main"
        aria-label={`Open ${name} image full screen`}
        onClick={() => setLightbox(true)}
        onMouseMove={(e) => {
          const r = e.currentTarget.getBoundingClientRect();
          setZoom(`${((e.clientX - r.left) / r.width) * 100}% ${((e.clientY - r.top) / r.height) * 100}%`);
        }}
        onMouseLeave={() => setZoom(null)}
      >
        <img src={img.url} alt={img.alt || name} style={zoom ? { transformOrigin: zoom, transform: 'scale(1.8)' } : undefined} fetchpriority="high" />
      </button>
      {images.length > 1 && (
        <div className="pp-thumbs" role="tablist" aria-label="Product images">
          {images.map((im, n) => (
            <button key={im.url} type="button" role="tab" aria-selected={n === i} className={n === i ? 'is-on' : ''} onClick={() => setI(n)} aria-label={`Image ${n + 1}`}>
              <img src={im.thumb} alt="" loading="lazy" />
            </button>
          ))}
        </div>
      )}
      {lightbox && (
        <div className="pp-lightbox" role="dialog" aria-modal="true" aria-label={`${name} images`} onClick={() => setLightbox(false)}>
          <img src={img.url} alt={img.alt || name} onClick={(e) => e.stopPropagation()} />
          <button type="button" className="pm-x" aria-label="Close" onClick={() => setLightbox(false)}>×</button>
          {images.length > 1 && (
            <>
              <button type="button" className="pp-nav pp-prev" aria-label="Previous image" onClick={(e) => { e.stopPropagation(); setI((x) => (x - 1 + images.length) % images.length); }}>‹</button>
              <button type="button" className="pp-nav pp-next" aria-label="Next image" onClick={(e) => { e.stopPropagation(); setI((x) => (x + 1) % images.length); }}>›</button>
            </>
          )}
        </div>
      )}
    </div>
  );
}

function ReviewForm({ slug }) {
  const [f, setF] = useState({ name: '', email: '', rating: 5, body: '', website: '' });
  const [msg, setMsg] = useState(null);
  const [busy, setBusy] = useState(false);
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await api(`products/${slug}/reviews`, { method: 'POST', body: f });
      setMsg({ ok: true, text: r.message });
      setF({ name: '', email: '', rating: 5, body: '', website: '' });
    } catch (err) {
      setMsg({ ok: false, text: err.message });
    } finally {
      setBusy(false);
    }
  };
  return (
    <form className="pp-review-form" onSubmit={submit}>
      <h3>Write a review</h3>
      <div className="pp-form-row">
        <label>Name<input required maxLength={80} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></label>
        <label>Email (not shown)<input required type="email" maxLength={150} value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></label>
      </div>
      <label>Rating
        <select value={f.rating} onChange={(e) => setF({ ...f, rating: Number(e.target.value) })}>
          {[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{'★'.repeat(n)} ({n})</option>)}
        </select>
      </label>
      <label>Your review<textarea required minLength={3} maxLength={2000} rows={4} value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} /></label>
      <input className="pm-hp" tabIndex={-1} autoComplete="off" aria-hidden="true" value={f.website} onChange={(e) => setF({ ...f, website: e.target.value })} />
      <button className="pm-btn" disabled={busy}>Submit review</button>
      {msg && <p className={msg.ok ? 'pm-note' : 'pm-error'} role="status">{msg.text}</p>}
    </form>
  );
}

export default function Product() {
  const { slug } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [variantId, setVariantId] = useState(null);
  const [qty, setQty] = useState(1);
  const { add, setDrawerOpen, items } = useCart();
  const { currency, store } = useStore();
  const toast = useToast();
  const wish = useWishlist();
  const navigate = useNavigate();

  useEffect(() => {
    setData(null);
    setError(null);
    window.scrollTo(0, 0);
    api(`products/${slug}`)
      .then((d) => {
        setData(d);
        const vs = d.product.variants;
        setVariantId((vs.find((v) => v.purchasable) || vs[0])?.id ?? null);
        setQty(1);
      })
      .catch(setError);
  }, [slug]);

  const p = data?.product;
  const v = p?.variants.find((x) => x.id === variantId);
  const inCart = items.find((i) => i.variant_id === variantId)?.quantity || 0;
  const max = v?.available ?? 99;
  const desc = useMemo(() => sanitizeHtml(p?.description || ''), [p?.description]);
  useSeo({
    title: p?.meta_title,
    description: p?.meta_description,
    image: p?.image_large,
    jsonLd: p && {
      '@context': 'https://schema.org',
      '@type': 'Product',
      name: p.name,
      image: p.images.map((i) => i.url),
      description: p.meta_description || p.short_description || undefined,
      sku: v?.sku || undefined,
      brand: p.brand ? { '@type': 'Brand', name: p.brand.name } : undefined,
      aggregateRating: p.reviews ? { '@type': 'AggregateRating', ratingValue: p.rating, reviewCount: p.reviews } : undefined,
      offers: p.priced
        ? p.variants.filter((x) => x.price !== null).map((x) => ({
            '@type': 'Offer', price: x.current_price, priceCurrency: currency, url: window.location.href,
            availability: x.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
          }))
        : undefined,
    },
  });

  if (error?.status === 404) return <NotFound />;
  if (error) return <div className="pmt-container"><p className="pm-error">{error.message}</p></div>;
  if (!p) {
    return (
      <div className="pmt-container pmt-entry">
        <div className="pp" aria-busy="true"><div className="pp-gallery pm-skel"><div className="pm-card__media" /></div><div className="pm-skel"><div className="pm-card__body"><i /><i /><i /></div></div></div>
      </div>
    );
  }

  const snap = { name: p.name, image: p.image, slug: p.slug, variant: v?.name };
  const canBuy = v?.purchasable && qty >= 1 && (v.available === null || qty + inCart <= v.available);
  const addToCart = () => {
    if (!add(v.id, qty, snap)) return;
    toast(`${p.name} added to your bag`);
    setDrawerOpen(true);
  };
  const buyNow = () => {
    if (add(v.id, qty, snap)) navigate('/checkout');
  };
  const wa = waLink(store?.settings?.whatsapp_number, `Hi Perfumarathi, I'm interested in ${p.name}${v && v.name !== 'Standard' ? ' (' + v.name + ')' : ''}.`);
  const noteLabel = { top: 'Top notes', middle: 'Heart notes', base: 'Base notes' };

  return (
    <div className="pmt-container pmt-entry">
      <nav className="pp-crumbs" aria-label="Breadcrumb">
        <Link to="/">Home</Link> / {p.brand ? <Link to={`/shop-${p.brand.slug}`}>{p.brand.name}</Link> : <Link to="/shop">Shop</Link>} / <span>{p.name}</span>
      </nav>
      <div className="pp">
        <Gallery images={p.images} name={p.name} />
        <div className="pp-summary">
          {p.brand && <span className="pm-card__brand">{p.brand.name}</span>}
          <h1 className="pp-title">{p.name}</h1>
          {p.tagline && <p className="pmc-single-tag">{p.tagline}</p>}
          {p.inspired_by && <p className="pmc-single-tag" data-testid="inspired-by">Inspired by {p.inspired_by}</p>}
          {p.reviews > 0 && <p className="pp-rating"><Stars rating={p.rating} /> <a href="#reviews">{p.reviews} review{p.reviews === 1 ? '' : 's'}</a></p>}

          <div className="pp-price" aria-live="polite">
            {v && v.price !== null ? (
              <>
                {v.sale_price !== null && <del>{money(v.price, currency)}</del>} <ins>{money(v.current_price, currency)}</ins>
                {v.sale_price !== null && <span className="pm-badge pp-save">Save {Math.round((1 - v.sale_price / v.price) * 100)}%</span>}
              </>
            ) : (
              <span className="pm-price-on-request">Price on request</span>
            )}
          </div>

          {p.short_description && <p className="pp-short">{p.short_description}</p>}

          {p.variants.length > 1 && (
            <fieldset className="pp-sizes">
              <legend>Size</legend>
              <div className="pm-sizes-pick">
                {p.variants.map((x) => (
                  <button key={x.id} type="button" aria-pressed={x.id === variantId} className={'pm-size' + (x.id === variantId ? ' is-on' : '') + (!x.in_stock ? ' is-out' : '')} onClick={() => { setVariantId(x.id); setQty(1); }}>
                    {x.name}
                    {x.price !== null && <small>{money(x.current_price, currency)}</small>}
                  </button>
                ))}
              </div>
            </fieldset>
          )}

          {v && v.price !== null && (
            <p className={'pm-stock ' + (v.in_stock ? 'in' : 'out')} data-testid="stock">
              {!v.in_stock ? 'Out of stock' : v.available !== null && v.available <= 5 ? `Only ${v.available} left` : 'In stock'}
            </p>
          )}

          {v?.purchasable ? (
            <div className="pp-buy">
              <div className="pm-qty pp-qty" role="group" aria-label="Quantity">
                <button type="button" aria-label="Decrease quantity" onClick={() => setQty((q) => Math.max(1, q - 1))} disabled={qty <= 1}>−</button>
                <input aria-label="Quantity" inputMode="numeric" value={qty} onChange={(e) => setQty(Math.max(1, Math.min(99, parseInt(e.target.value, 10) || 1)))} />
                <button type="button" aria-label="Increase quantity" onClick={() => setQty((q) => Math.min(max - inCart, q + 1))} disabled={v.available !== null && qty + inCart >= v.available}>+</button>
              </div>
              <button type="button" className="pm-btn pp-add" onClick={addToCart} disabled={!canBuy}>Add to Cart</button>
              <button type="button" className="pm-btn pp-buynow" onClick={buyNow} disabled={!canBuy}>Buy Now</button>
              {v.available !== null && inCart > 0 && qty + inCart > v.available && <p className="pm-error">You already have {inCart} in your bag.</p>}
            </div>
          ) : null}

          <div className="pp-secondary">
            <button type="button" className="pm-link" onClick={() => toast(wish.toggle(p.id) ? 'Saved to wishlist' : 'Removed from wishlist')} aria-pressed={wish.has(p.id)}>
              {wish.has(p.id) ? '♥ In your wishlist' : '♡ Add to wishlist'}
            </button>
          </div>
          {wa && <a className="pm-enquire" href={wa} target="_blank" rel="noopener noreferrer">WhatsApp • Ask about {p.name}</a>}

          {Object.keys(p.notes).length > 0 && (
            <div className="pm-notes">
              {Object.entries(p.notes).map(([k, val]) => <p key={k}><strong>{noteLabel[k]}:</strong> {val}</p>)}
            </div>
          )}
          <dl className="pp-facts">
            {p.perfume_type && (<><dt>Type</dt><dd>{p.perfume_type}</dd></>)}
            {p.concentration && (<><dt>Concentration</dt><dd>{p.concentration}</dd></>)}
            {p.fragrance_family && (<><dt>Fragrance family</dt><dd>{p.fragrance_family}</dd></>)}
            {p.gender && (<><dt>For</dt><dd>{p.gender === 'men' ? 'Men' : p.gender === 'women' ? 'Women' : 'Everyone (unisex)'}</dd></>)}
            {p.longevity && (<><dt>Lasting</dt><dd>{p.longevity}</dd></>)}
            {p.accords && (<><dt>Accords</dt><dd>{p.accords}</dd></>)}
          </dl>
          {p.traits && <p className="pmc-traits">{p.traits}</p>}
          {p.categories.length > 0 && (
            <p className="pp-cats">{p.categories.map((c, n) => <span key={c.slug}>{n > 0 && ', '}<Link to={`/category/${c.slug}`}>{c.name}</Link></span>)}</p>
          )}
        </div>
      </div>

      {data.bundle.length > 0 && (
        <section className="pm-home__section" aria-label="In this combo">
          <h2 className="pm-home__title">In this combo</h2>
          <ProductGrid products={data.bundle} rail />
        </section>
      )}

      {desc && (
        <section className="pp-desc">
          <h2>Description</h2>
          <div dangerouslySetInnerHTML={{ __html: desc }} />
        </section>
      )}

      <section className="pp-reviews" id="reviews">
        <h2>Reviews {p.reviews > 0 && `(${p.reviews})`}</h2>
        {data.reviews.length === 0 && <p className="pm-note">No reviews yet.</p>}
        {data.reviews.map((r) => (
          <blockquote key={r.id} className="pp-review">
            <Stars rating={r.rating} />
            <p>{r.body}</p>
            <cite>{r.author_name}</cite>
          </blockquote>
        ))}
        <ReviewForm slug={p.slug} />
      </section>

      {data.related.length > 0 && (
        <section className="pm-home__section" aria-label="You may also like">
          <h2 className="pm-home__title">You may also like</h2>
          <ProductGrid products={data.related} rail />
        </section>
      )}
    </div>
  );
}
