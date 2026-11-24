import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { money } from '../../lib/format';
import { adminApi, qs, useLoad } from '../lib';
import { Empty, ErrorBox, Loading, PageHead, Pager, Tabs, useUi } from '../ui';

export function priceText(p, currency = 'AED') {
  const prices = p.variants.filter((v) => v.is_active && v.price !== null).map((v) => v.current_price ?? v.price);
  if (!prices.length) return <span className="ad-warn">No price</span>;
  const lo = Math.min(...prices);
  const hi = Math.max(...prices);
  return lo === hi ? money(lo, currency) : `${money(lo, currency)} – ${money(hi, currency)}`;
}

function stockText(p) {
  const tracked = p.variants.filter((v) => v.is_active && v.track_stock);
  if (!p.variants.some((v) => v.is_active && v.in_stock)) return <span className="ad-bad">Out of stock</span>;
  if (!tracked.length) return 'In stock';
  return <span className={p.variants.some((v) => v.low_stock) ? 'ad-warn' : ''}>{p.stock_total} in stock</span>;
}

export default function Products() {
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const { notify, confirm } = useUi();
  const f = Object.fromEntries(['search', 'brand_id', 'category_id', 'status', 'stock', 'sort', 'page'].map((k) => [k, params.get(k) || '']));
  const [term, setTerm] = useState(f.search);
  const set = (k, v) => {
    const next = new URLSearchParams(params);
    v ? next.set(k, v) : next.delete(k);
    if (k !== 'page') next.delete('page');
    setParams(next, { replace: k === 'search' });
  };
  useEffect(() => {
    const t = setTimeout(() => term !== f.search && set('search', term.trim()), 350);
    return () => clearTimeout(t);
  }, [term]); // eslint-disable-line react-hooks/exhaustive-deps

  const { data, error, loading, reload } = useLoad('products?' + qs(f));
  const brands = useLoad('brands').data || [];
  const cats = useLoad('categories').data || [];

  const act = async (p, what) => {
    try {
      if (what === 'delete') {
        if (!(await confirm(`Delete "${p.name}"? It disappears from the shop. Past orders keep their details and you can restore it from "Deleted".`, { ok: 'Delete', danger: true }))) return;
        await adminApi(`products/${p.id}`, { method: 'DELETE' });
        notify('Product deleted.');
      } else if (what === 'restore') {
        await adminApi(`products/${p.id}/restore`, { method: 'POST' });
        notify('Product restored.');
      } else if (what === 'duplicate') {
        const copy = await adminApi(`products/${p.id}/duplicate`, { method: 'POST' });
        notify('Copy created (hidden until you publish it).');
        navigate(`/admin/products/${copy.id}`);
        return;
      } else if (what === 'toggle') {
        const body = editable(p);
        body.variants = body.variants.map(({ _stock0, ...v }) => ({ ...v, stock_quantity: null })); // eslint-disable-line no-unused-vars
        await adminApi(`products/${p.id}`, { method: 'PUT', body: { ...body, is_active: !p.is_active } });
        notify(p.is_active ? 'Hidden from the shop.' : 'Visible in the shop.');
      }
      reload();
    } catch (e) {
      notify(e.message, 'error');
    }
  };

  return (
    <>
      <PageHead title="Products" sub="Add perfumes, set sizes, prices and stock, and choose what shows in the shop.">
        <Link className="ad-btn" to="/admin/products/new">+ Add product</Link>
      </PageHead>
      <Tabs value={f.status} onChange={(v) => set('status', v)} items={[['', 'All'], ['active', 'Visible'], ['hidden', 'Hidden'], ['unpriced', 'No price'], ['deleted', 'Deleted']]} />
      <div className="ad-filters">
        <input type="search" aria-label="Search products" placeholder="Search by name or SKU…" value={term} onChange={(e) => setTerm(e.target.value)} />
        <select aria-label="Brand" value={f.brand_id} onChange={(e) => set('brand_id', e.target.value)}>
          <option value="">All brands</option>
          {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
        <select aria-label="Category" value={f.category_id} onChange={(e) => set('category_id', e.target.value)}>
          <option value="">All categories</option>
          {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select aria-label="Stock" value={f.stock} onChange={(e) => set('stock', e.target.value)}>
          <option value="">Any stock</option>
          <option value="in">In stock</option>
          <option value="low">Low stock</option>
          <option value="out">Out of stock</option>
        </select>
        <select aria-label="Sort" value={f.sort} onChange={(e) => set('sort', e.target.value)}>
          <option value="">Shop order</option>
          <option value="latest">Newest</option>
          <option value="sales">Best selling</option>
          <option value="stock">Lowest stock</option>
        </select>
      </div>
      <ErrorBox error={error} onRetry={reload} />
      {loading && !data ? <Loading /> : data && (data.data.length === 0 ? <Empty>No products found. <Link to="/admin/products/new">Add one</Link>.</Empty> : (
        <div className="ad-table-wrap">
          <table className="ad-table" data-testid="products-table">
            <thead><tr><th>Product</th><th>Brand</th><th>Price</th><th>Stock</th><th>Shop</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {data.data.map((p) => (
                <tr key={p.id}>
                  <td data-label="Product">
                    <div className="ad-prod">
                      {p.images[0] ? <img src={p.images[0].thumb || p.images[0].url} alt="" width="44" height="44" loading="lazy" /> : <span className="ad-thumb" />}
                      <span>
                        {f.status === 'deleted' ? <b>{p.name}</b> : <Link to={`/admin/products/${p.id}`}><b>{p.name}</b></Link>}
                        <small className="ad-block ad-muted">{p.variants.length} size{p.variants.length === 1 ? '' : 's'}{p.sku ? ` · ${p.sku}` : ''}</small>
                      </span>
                    </div>
                  </td>
                  <td data-label="Brand">{p.brand || '—'}</td>
                  <td data-label="Price">{priceText(p)}</td>
                  <td data-label="Stock">{stockText(p)}</td>
                  <td data-label="Shop">
                    {f.status === 'deleted' ? <span className="ad-muted">Deleted</span> : (
                      <button type="button" className={'ad-chip' + (p.is_active ? ' is-on' : '')} onClick={() => act(p, 'toggle')} aria-pressed={p.is_active} title="Show or hide in the shop">
                        {p.is_active ? 'Visible' : 'Hidden'}
                      </button>
                    )}
                  </td>
                  <td data-label="" className="ad-row-actions">
                    {f.status === 'deleted' ? (
                      <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" onClick={() => act(p, 'restore')}>Restore</button>
                    ) : (
                      <>
                        <Link className="ad-btn ad-btn--sm ad-btn--ghost" to={`/admin/products/${p.id}`}>Edit</Link>
                        <a className="ad-btn ad-btn--sm ad-btn--ghost" href={`/product/${p.slug}`} target="_blank" rel="noopener noreferrer">View</a>
                        <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" onClick={() => act(p, 'duplicate')}>Copy</button>
                        <button type="button" className="ad-btn ad-btn--sm ad-btn--danger-ghost" onClick={() => act(p, 'delete')}>Delete</button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
      <Pager meta={data?.meta} onPage={(p) => set('page', String(p))} />
    </>
  );
}

/**
 * Product as the update endpoint expects it (used by quick actions and the editor).
 * `_stock0` remembers the loaded stock so we only send stock the user changed: orders placed
 * while the form is open must not be overwritten by an old number.
 */
export function editable(p) {
  const keys = ['name', 'slug', 'sku', 'brand_id', 'tagline', 'short_description', 'description', 'notes_top', 'notes_middle', 'notes_base', 'accords',
    'fragrance_family', 'perfume_type', 'concentration', 'gender', 'traits', 'longevity', 'is_active', 'is_featured', 'is_new_arrival', 'is_best_seller',
    'is_combo', 'sort_order', 'meta_title', 'meta_description', 'category_ids', 'related_ids'];
  const out = Object.fromEntries(keys.map((k) => [k, p[k] ?? null]));
  out.category_ids = p.category_ids || [];
  out.related_ids = p.related_ids || [];
  out.variants = p.variants.map((v) => ({
    id: v.id, name: v.name, sku: v.sku, price: v.price, sale_price: v.raw_sale_price ?? v.sale_price ?? null, track_stock: v.track_stock,
    stock_quantity: v.stock_quantity, _stock0: v.stock_quantity, stock_status: v.stock_status, low_stock_threshold: v.low_stock_threshold, is_active: v.is_active,
  }));
  return out;
}
