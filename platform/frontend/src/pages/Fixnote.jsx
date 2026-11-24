import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api } from '../lib/api';
import { useCart } from '../lib/cart';
import { useToast } from '../lib/toast';
import { useSeo } from '../lib/seo';
import { waLink } from '../lib/format';
import BagButton from '../components/BagButton';
import defaultLogo from '../assets/fixnote/logo.webp';
import defaultHero from '../assets/fixnote/hero.webp';
import defaultBottle from '../assets/fixnote/bottle.webp';
import '../styles/fixnote.css';

/** URL part -> region. UAE and Other GCC order online; India and Saudi send the selection on WhatsApp. */
export const REGIONS = { uae: 'UAE', india: 'INDIA', saudi: 'SAUDI', gcc: 'GCC' };
const ONLINE = new Set(['UAE', 'GCC']);
const DEFAULT_SIZE = '100 ML';
const label = (it, sep = ' ') => (it.code ? `${it.code}${sep}${it.name}` : it.name);

let cache = null;
function loadFixnote() {
  if (!cache || Date.now() - cache.at > 30000) {
    const promise = api('fixnote');
    cache = { at: Date.now(), promise };
    promise.catch(() => {
      cache = null;
    });
  }
  return cache.promise;
}

/** 29 AED, 1,199 INR (the way the Fixnote catalogue writes prices). */
export function fxPrice(amount, currency) {
  if (amount === null || amount === undefined) return '';
  return `${Number(amount).toLocaleString('en-US', { maximumFractionDigits: 2 })} ${currency}`;
}

/** "~~₹7,996~~" is shown struck through. */
function Rich({ text }) {
  return String(text || '').split(/(~~[^~]+~~)/).map((part, i) => (part.startsWith('~~') && part.endsWith('~~') ? <s key={i}>{part.slice(2, -2)}</s> : part));
}

function Offers({ offers, region }) {
  if (!offers?.length) return null;
  return (
    <section className="fx-offers" aria-label={`${region} offers`} data-testid="fx-offers">
      <div className="fx-offers__run">
        {[...offers, ...offers].map((o, i) => (
          <div key={i} className={'fx-offer' + (o.highlight ? ' fx-offer--hi' : '')} aria-hidden={i >= offers.length || undefined}>
            {o.tag && <div className="fx-offer__tag">{o.tag}</div>}
            {o.title && <strong><Rich text={o.title} /></strong>}
            {o.text && <span><Rich text={o.text} /></span>}
          </div>
        ))}
      </div>
    </section>
  );
}

function FxHome({ data, img }) {
  const p = data.page;
  const { hash } = useLocation();
  useEffect(() => {
    if (hash !== '#fx-regions') return undefined;
    const t = setTimeout(() => document.getElementById('fx-regions')?.scrollIntoView({ behavior: 'smooth' }), 50);
    return () => clearTimeout(t);
  }, [hash]);
  const cards = p.collections || [];
  const wa = waLink(p.whatsapp_number, p.whatsapp_message);
  return (
    <div className="fx-home">
      <div className="fx-nav">
        <Link to="/" className="fx-nav__logo" aria-label="Perfumarathi & Fixnote home"><img src={img.page_logo} alt="FIXNOT Fragrances" decoding="async" /></Link>
        <div className="fx-nav__right">
          <button type="button" className="fx-btn fx-btn--dark fx-menu" onClick={() => document.getElementById('fx-regions')?.scrollIntoView({ behavior: 'smooth' })}>SHOP MENU</button>
          <BagButton />
        </div>
      </div>
      <section className="fx-hero">
        {p.hero_eyebrow && <div className="fx-eyebrow">{p.hero_eyebrow}</div>}
        <h1>{p.hero_title} {p.hero_highlight && <em>{p.hero_highlight}</em>}</h1>
        {p.hero_text && <p>{p.hero_text}</p>}
      </section>
      <section className="fx-visual"><img src={img.hero} alt="FIXNOT fragrance lifestyle" decoding="async" /></section>
      {cards.length > 0 && (
        <section className="fx-coll" aria-label={p.collections_title || 'Collections'}>
          {p.collections_title && <h2>{p.collections_title}</h2>}
          {p.collections_text && <p>{p.collections_text}</p>}
          <div className="fx-coll__run">
            {[...cards, ...cards].map((c, i) => (
              <div key={i} className="fx-coll__card" aria-hidden={i >= cards.length || undefined}>
                <img src={img.bottle} alt="" loading="lazy" decoding="async" />
                <strong>{c.title}</strong>
                <span>{c.text}</span>
              </div>
            ))}
          </div>
        </section>
      )}
      <section className="fx-regions-block" id="fx-regions" aria-labelledby="fx-regions-title">
        <h2 className="fx-regions-title" id="fx-regions-title">{p.regions_title || 'CHOOSE YOUR REGION'}</h2>
        <div className="fx-regions">
          {Object.entries(REGIONS).map(([slug, r]) => (
            <Link key={r} to={`/shop-fixnote/${slug}`} className={'fx-region' + (r === 'UAE' ? ' fx-region--primary' : '')} data-testid={`fx-region-${slug}`}>
              <b>{p.region_cards?.[r]?.title || r}</b>
              {p.region_cards?.[r]?.text && <small>{p.region_cards[r].text}</small>}
            </Link>
          ))}
        </div>
      </section>
      {p.benefits?.length > 0 && (
        <section className="fx-benefits">
          {p.benefits.map((b, i) => <div key={i} className="fx-benefit"><strong>{b.title}</strong><span>{b.text}</span></div>)}
        </section>
      )}
      {p.reviews?.length > 0 && (
        <section className="fx-reviews">
          {p.reviews_title && <h2>{p.reviews_title}</h2>}
          <div className="fx-reviews__grid">
            {p.reviews.map((r, i) => <blockquote key={i} className="fx-review"><div className="fx-stars" aria-label="5 stars">★★★★★</div>“{r.text}”</blockquote>)}
          </div>
        </section>
      )}
      {wa && (
        <section className="fx-wa">
          {p.whatsapp_title && <h2>{p.whatsapp_title}</h2>}
          {p.whatsapp_text && <p>{p.whatsapp_text}</p>}
          <a href={wa} target="_blank" rel="noopener noreferrer">{p.whatsapp_button || 'WHATSAPP'}</a>
        </section>
      )}
      <div className="fx-foot">{p.home_foot}</div>
    </div>
  );
}

function FxShop({ data, region, img }) {
  const p = data.page;
  const online = ONLINE.has(region);
  const navigate = useNavigate();
  const toast = useToast();
  const { items: cartItems, add, remove, replace, quote, setCountry } = useCart();
  const [term, setTerm] = useState('');
  const [group, setGroup] = useState('');
  const [picked, setPicked] = useState({}); // WhatsApp regions: product id -> variant id
  const q = useDeferredValue(term.trim().toLowerCase());
  const regionPrices = data.regions?.[region]?.prices || {};
  const currency = online ? data.currency : data.regions?.[region]?.currency || data.currency;

  useEffect(() => {
    if (region === 'UAE') setCountry('AE');
  }, [region, setCountry]);

  const byVariant = useMemo(() => {
    const m = new Map();
    for (const it of data.items) for (const v of it.variants) m.set(v.id, it);
    return m;
  }, [data.items]);
  /** product id -> cart lines of that perfume */
  const lines = useMemo(() => {
    const m = new Map();
    for (const l of cartItems) {
      const it = byVariant.get(l.variant_id);
      if (it) m.set(it.id, [...(m.get(it.id) || []), l]);
    }
    return m;
  }, [cartItems, byVariant]);
  const hay = useMemo(() => new Map(data.items.map((it) => [it.id, `${it.name} ${it.code || ''} ${it.inspired_by} ${it.notes || ''}`.toLowerCase()])), [data.items]);

  // Sizes a customer can choose here, with the price for this region.
  const options = (it) => (online
    ? it.variants.map((v) => ({ v, price: v.current_price, regular: v.on_sale ? v.price : null, ok: v.purchasable }))
    : it.variants.filter((v) => v.size && regionPrices[v.size] != null).map((v) => ({ v, price: regionPrices[v.size], regular: null, ok: v.in_stock })));
  const firstChoice = (opts) => opts.find((o) => o.ok && o.v.size === DEFAULT_SIZE) || opts.find((o) => o.ok);
  const snap = (it, v) => ({ name: it.name, image: it.image || img.bottle, slug: it.slug, variant: v.name });

  const toggle = (it, on) => {
    if (online) {
      if (!on) return (lines.get(it.id) || []).forEach((l) => remove(l.variant_id));
      const o = firstChoice(options(it));
      if (o) add(o.v.id, 1, snap(it, o.v)); // the bar at the bottom shows the count
      return undefined;
    }
    setPicked((x) => {
      const next = { ...x };
      if (on) next[it.id] = firstChoice(options(it))?.v.id;
      else delete next[it.id];
      return next;
    });
    return undefined;
  };
  const changeSize = (it, variantId) => {
    const v = it.variants.find((x) => x.id === variantId);
    if (!v) return;
    if (online) {
      const l = (lines.get(it.id) || [])[0];
      if (l && l.variant_id !== v.id) replace(l.variant_id, v.id, snap(it, v));
    } else {
      setPicked((x) => ({ ...x, [it.id]: v.id }));
    }
  };

  // Selection = Fixnote lines in the bag (online) or the ticked perfumes (WhatsApp regions).
  const selection = online
    ? cartItems.filter((l) => byVariant.has(l.variant_id)).map((l) => {
        const it = byVariant.get(l.variant_id);
        const v = it.variants.find((x) => x.id === l.variant_id);
        return { it, v, qty: l.quantity, price: v.current_price };
      })
    : Object.entries(picked).map(([id, vid]) => {
        const it = data.items.find((x) => x.id === Number(id));
        const v = it?.variants.find((x) => x.id === vid);
        return it && v ? { it, v, qty: 1, price: regionPrices[v.size] } : null;
      }).filter(Boolean);
  const count = selection.reduce((n, s) => n + s.qty, 0);

  const sendWa = () => {
    if (!selection.length) {
      toast('Please select at least one fragrance.');
      return;
    }
    let total = 0;
    const rows = selection.map(({ it, v, qty, price }) => {
      total += (price || 0) * qty;
      return `${label(it, ' – ')} – ${p.product_type || 'Extrait Perfume'} – ${v.name}${qty > 1 ? ` × ${qty}` : ''} – ${fxPrice((price || 0) * qty, currency)}`;
    });
    const msg = `Hi Perfumarathi, I selected these FIXNOT fragrances:\n\n${rows.join('\n')}\n\nRegion: ${region}\nTotal: ${fxPrice(total, currency)}\n\nPlease confirm my order.`;
    const href = waLink(p.whatsapp_number, msg);
    if (href) window.location.href = href;
  };

  const shown = data.items.filter((it) => (!group || it.inspired_by === group) && (!q || hay.get(it.id).includes(q)));
  const groups = data.groups.filter((g) => shown.some((it) => it.inspired_by === g));
  const sizeRows = ['30 ML', '50 ML', '100 ML'].map((s) => (online
    ? { s, price: data.prices?.[s]?.sale_price ?? data.prices?.[s]?.price, regular: data.prices?.[s]?.sale_price != null ? data.prices[s].price : null }
    : { s, price: regionPrices[s], regular: null })).filter((r) => r.price != null);

  const fd = region === 'UAE' && count > 0 ? quote?.fixnote_delivery : null;
  let hint = null;
  if (fd) {
    if (fd.fee <= 0) hint = <p className="fx-bar__hint is-free" data-testid="fx-delivery">FREE DELIVERY on this order</p>;
    else if (fd.free_from && fd.pieces < fd.free_from) {
      const more = fd.free_from - fd.pieces;
      hint = <p className="fx-bar__hint" data-testid="fx-delivery">Delivery {fxPrice(fd.fee, currency)} • add {more} more piece{more === 1 ? '' : 's'} for FREE delivery</p>;
    } else hint = <p className="fx-bar__hint" data-testid="fx-delivery">Delivery {fxPrice(fd.fee, currency)}</p>;
  }

  return (
    <div className="fx-shop">
      <Link to="/shop-fixnote" className="fx-btn fx-btn--dark fx-back">← HOME</Link>
      <Offers offers={p.offers?.[region]} region={region} />
      <header className="fx-head">
        <div className="fx-head__top">
          <Link to="/shop-fixnote" className="fx-head__logo" aria-label="Fixnote home"><img src={img.shop_logo} alt="FIXNOT" decoding="async" /></Link>
          <BagButton />
        </div>
        {p.shop_sub && <div className="fx-sub">{p.shop_sub}</div>}
        {p.shop_parent && <div className="fx-parent">{p.shop_parent}</div>}
        <div className="fx-region-now">
          REGION: {p.region_cards?.[region]?.title || region} · <Link to="/shop-fixnote#fx-regions">Change</Link>
          {!online && ' · Orders are confirmed on WhatsApp'}
        </div>
        {sizeRows.length > 0 && (
          <div className="fx-quick" data-testid="fx-quick">
            <div className="fx-quick__type">{p.product_type}</div>
            {sizeRows.map((r) => (
              <div key={r.s} className="fx-quick__row"><span>{r.s}</span><strong>{r.regular != null && <del>{fxPrice(r.regular, currency)}</del>}{fxPrice(r.price, currency)}</strong></div>
            ))}
          </div>
        )}
        <div className="fx-controls">
          <input type="search" autoComplete="off" aria-label="Search perfumes" placeholder="Search perfume, code, brand or note…" value={term} onChange={(e) => setTerm(e.target.value)} />
          <select aria-label="Inspired by brand" value={group} onChange={(e) => setGroup(e.target.value)}>
            <option value="">All Brands</option>
            {data.groups.map((g) => <option key={g} value={g}>{g}</option>)}
          </select>
        </div>
      </header>

      <main className="fx-main" id="pmt-content">
        {groups.length === 0 && <p className="fx-empty">{data.items.length ? 'No perfume matches your search.' : 'The Fixnote catalogue is being updated. Please check back soon.'}</p>}
        {groups.map((g) => (
          <section key={g} aria-label={g}>
            <h2 className="fx-group">{g}</h2>
            <div className="fx-grid">
              {shown.filter((it) => it.inspired_by === g).map((it) => {
                const opts = options(it);
                const avail = opts.some((o) => o.ok);
                const mine = online ? lines.get(it.id) || [] : [];
                const selId = online ? mine[0]?.variant_id : picked[it.id];
                const sel = selId ? opts.find((o) => o.v.id === selId) : null;
                const on = !!selId;
                const qty = mine.reduce((n, l) => n + l.quantity, 0);
                return (
                  <div key={it.id} className={'fx-card' + (on ? ' is-on' : '') + (!avail ? ' is-out' : '')} data-testid={`fx-card-${it.code || it.id}`}>
                    <img src={it.image || img.bottle} alt="" loading="lazy" decoding="async" />
                    <div>
                      {it.code && <div className="fx-code">{it.code}</div>}
                      <div className="fx-name">{it.name}</div>
                      {it.notes && <div className="fx-notes">{it.notes}</div>}
                    </div>
                    <input
                      className="fx-check" type="checkbox" checked={on} disabled={!avail && !on}
                      aria-label={`Select ${label(it)}`} onChange={(e) => toggle(it, e.target.checked)}
                    />
                    {!avail && !on && <div className="fx-out">{online ? 'Out of stock' : 'Not available in this region'}</div>}
                    {on && (
                      <div className="fx-opts">
                        <div className="fx-opts__type">{p.product_type}</div>
                        <select aria-label={`Size for ${label(it)}`} value={selId} onChange={(e) => changeSize(it, Number(e.target.value))}>
                          {opts.map((o) => (
                            <option key={o.v.id} value={o.v.id} disabled={!o.ok && o.v.id !== selId}>
                              {o.v.name} - {fxPrice(o.price, currency)}{!o.ok ? ' (out of stock)' : ''}
                            </option>
                          ))}
                        </select>
                        {sel && (
                          <div className="fx-price">
                            {sel.regular != null && <del>{fxPrice(sel.regular, currency)}</del>}
                            {fxPrice(sel.price, currency)}
                            {online && qty > 1 && <span className="fx-qty"> · {qty} in your bag</span>}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </section>
        ))}
      </main>

      <section className="fx-info" aria-label="Contact">
        <div className="fx-info__title">{p.footer_title}</div>
        <div className="fx-info__sub">{p.footer_sub}</div>
        <div className="fx-info__grid">
          {p.footer_shipping && <div className="fx-info__box"><div className="fx-info__label">Shipping Available</div><div className="fx-info__value">{p.footer_shipping}</div></div>}
          {p.footer_email && <div className="fx-info__box"><div className="fx-info__label">Email</div><div className="fx-info__value"><a href={`mailto:${p.footer_email}`}>{p.footer_email}</a></div></div>}
          {p.footer_phone && <div className="fx-info__box"><div className="fx-info__label">Mobile / WhatsApp</div><div className="fx-info__value"><a href={`tel:${p.footer_phone.replace(/[^\d+]/g, '')}`}>{p.footer_phone}</a></div></div>}
        </div>
      </section>

      <div className="fx-bar">
        {hint}
        <div className="fx-bar__row">
          <span className="fx-bar__count"><span data-testid="fx-count">{count}</span> selected</span>
          {online ? (
            <>
              <button type="button" className="fx-btn fx-btn--orange" disabled={!count} onClick={() => navigate('/checkout')}>CHECKOUT</button>
              <button type="button" className="fx-btn fx-btn--wa" onClick={sendWa} aria-label="Send my selection on WhatsApp">WHATSAPP</button>
            </>
          ) : (
            <button type="button" className="fx-btn" onClick={sendWa}>SEND MY SELECTION ON WHATSAPP</button>
          )}
        </div>
      </div>
    </div>
  );
}

/** /shop-fixnote (brand page with the region choice) and /shop-fixnote/:region (catalogue). */
export default function Fixnote() {
  const { region: slug } = useParams();
  const region = slug ? REGIONS[slug.toLowerCase()] : null;
  const [state, setState] = useState({ data: null, error: null });
  const [attempt, setAttempt] = useState(0);
  // The shop sets overflow-x:hidden on html/body, which stops position:sticky (header, bottom bar).
  useEffect(() => {
    document.documentElement.classList.add('fx-page');
    return () => document.documentElement.classList.remove('fx-page');
  }, []);
  useEffect(() => {
    let live = true;
    loadFixnote()
      .then((data) => live && setState({ data, error: null }))
      .catch((error) => live && setState({ data: null, error }));
    return () => {
      live = false;
    };
  }, [attempt]);
  useSeo({
    title: region ? `FIXNOT Fragrances – ${region === 'GCC' ? 'GCC & other countries' : region}` : 'FIXNOT Fragrances',
    description: 'FIXNOT by Perfumarathi: inspired extrait perfumes in 30, 50 and 100 ML. Long lasting, high projection. Order online in the UAE and GCC.',
  });

  if (slug && !region) return <Navigate to="/shop-fixnote" replace />;
  const d = state.data;
  const img = {
    page_logo: d?.images?.page_logo || defaultLogo,
    shop_logo: d?.images?.shop_logo || defaultLogo, // the original catalogue logo is mostly white space
    hero: d?.images?.hero || defaultHero,
    bottle: d?.images?.bottle || defaultBottle,
  };
  return (
    <div className="fx">
      {state.error ? (
        <div className="fx-empty" role="alert">
          <p>{state.error.message || 'The Fixnote catalogue could not be loaded.'}</p>
          <button type="button" className="fx-btn fx-btn--orange" style={{ flex: 'none', padding: '12px 22px' }} onClick={() => setAttempt((n) => n + 1)}>Try again</button>
        </div>
      ) : !d ? (
        <p className="fx-empty" role="status">Loading the Fixnote catalogue…</p>
      ) : region ? (
        <FxShop key={region} data={d} region={region} img={img} />
      ) : (
        <FxHome data={d} img={img} />
      )}
    </div>
  );
}
