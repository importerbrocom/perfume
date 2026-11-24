import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useCart } from '../lib/cart';
import { useStore } from '../lib/store';
import { useToast } from '../lib/toast';
import { money } from '../lib/format';

export default function QuickView({ slug, onClose }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [variantId, setVariantId] = useState(null);
  const { add, setDrawerOpen } = useCart();
  const { currency } = useStore();
  const toast = useToast();
  const box = useRef(null);
  useEffect(() => {
    api(`products/${slug}`).then((d) => {
      setData(d.product);
      setVariantId(d.product.variants.find((v) => v.purchasable)?.id || d.product.variants[0]?.id);
    }).catch(setError);
  }, [slug]);
  useEffect(() => {
    const prev = document.activeElement;
    box.current?.focus();
    const f = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', f);
    document.documentElement.classList.add('pm-lock');
    return () => {
      document.removeEventListener('keydown', f);
      document.documentElement.classList.remove('pm-lock');
      prev?.focus?.();
    };
  }, [onClose]);
  const v = data?.variants.find((x) => x.id === variantId);
  return (
    <>
      <div className="pm-overlay" onClick={onClose} />
      <div className="pm-modal is-open" role="dialog" aria-modal="true" aria-label="Quick view">
        <div className="pm-modal__box" ref={box} tabIndex={-1}>
          <button className="pm-x" onClick={onClose} aria-label="Close quick view">×</button>
          {error && <p>Could not load this product.</p>}
          {!data && !error && <p>Loading…</p>}
          {data && (
            <div className="pm-modal__grid">
              <img src={data.images[0]?.url || data.image_large} alt={data.name} />
              <div>
                {data.brand && <span className="pm-card__brand">{data.brand.name}</span>}
                <h2>{data.name}</h2>
                {data.tagline && <p className="pmc-single-tag">{data.tagline}</p>}
                <div className="pm-card__price">{v?.price !== null && v ? money(v.current_price, currency) : <span className="pm-card__req">Price on request</span>}</div>
                {data.short_description && <p>{data.short_description}</p>}
                {Object.keys(data.notes).length > 0 && (
                  <ul className="pm-notes-list">
                    {Object.entries(data.notes).map(([k, val]) => <li key={k}><strong>{k === 'middle' ? 'Heart' : k[0].toUpperCase() + k.slice(1)}:</strong> {val}</li>)}
                  </ul>
                )}
                {data.variants.length > 1 && (
                  <div className="pm-sizes-pick" role="radiogroup" aria-label="Size">
                    {data.variants.map((x) => (
                      <button key={x.id} type="button" role="radio" aria-checked={x.id === variantId} className={'pm-size' + (x.id === variantId ? ' is-on' : '')} onClick={() => setVariantId(x.id)} disabled={!x.in_stock}>{x.name}</button>
                    ))}
                  </div>
                )}
                <div className="pm-card__actions">
                  {v?.purchasable && (
                    <button className="pm-btn" onClick={() => { add(v.id, 1, { name: data.name, image: data.image, slug: data.slug, variant: v.name }); toast('Added to your bag'); onClose(); setDrawerOpen(true); }}>Add to Cart</button>
                  )}
                  <Link className="pm-btn pm-btn--ghost" to={`/product/${data.slug}`} onClick={onClose}>Full Details</Link>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
