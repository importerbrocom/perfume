import { useEffect, useMemo, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { api, qs } from '../lib/api';
import { useStore } from '../lib/store';
import { useSeo } from '../lib/seo';
import ProductGrid from '../components/ProductGrid';

const SORTS = [
  ['featured', 'Featured'],
  ['latest', 'Latest arrivals'],
  ['popular', 'Popularity'],
  ['price_asc', 'Price: low to high'],
  ['price_desc', 'Price: high to low'],
  ['name', 'Name'],
];

/**
 * Product list: all perfumes, one brand (/shop-perfumarathi, /shop-fixnote, /brand/:slug) or one category / collection.
 * Search, category filter, sort and "Load more" (same toolbar as the WordPress shop).
 */
export default function Shop({ brand: fixedBrand }) {
  const params = useParams();
  const [sp, setSp] = useSearchParams();
  const brand = fixedBrand || params.brand || '';
  const category = params.category || sp.get('category') || '';
  const search = sp.get('search') || '';
  const sort = sp.get('sort') || 'featured';
  const flag = sp.get('flag') || '';
  const { store } = useStore();
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [term, setTerm] = useState(search);

  const brandObj = store?.brands?.find((b) => b.slug === brand);
  const catObj = store?.categories?.find((c) => c.slug === category);
  const title = catObj?.name || (brandObj ? `Shop ${brandObj.name}` : search ? `Search: ${search}` : 'All Perfumes');
  useSeo({ title, description: brandObj?.tagline || `${title} at Perfumarathi. Long-lasting perfumes delivered across the UAE, GCC and India.` });

  const key = useMemo(() => JSON.stringify({ brand, category, search, sort, flag }), [brand, category, search, sort, flag]);
  useEffect(() => {
    setItems([]);
    setPage(1);
  }, [key]);
  useEffect(() => setTerm(search), [search]);

  useEffect(() => {
    const ctl = new AbortController();
    setLoading(true);
    setError(null);
    api('products?' + qs({ brand, category, search, sort, flag, page, per_page: 12 }), { signal: ctl.signal })
      .then((d) => {
        setItems((old) => (page === 1 ? d.data : [...old, ...d.data.filter((n) => !old.some((o) => o.id === n.id))]));
        setMeta(d.meta);
        setLoading(false);
      })
      .catch((e) => {
        if (e.name !== 'AbortError') {
          setError(e.message);
          setLoading(false);
        }
      });
    return () => ctl.abort();
  }, [key, page]); // eslint-disable-line react-hooks/exhaustive-deps

  // Search as you type (debounced).
  useEffect(() => {
    if (term === search) return undefined;
    const t = setTimeout(() => {
      const next = new URLSearchParams(sp);
      term ? next.set('search', term) : next.delete('search');
      setSp(next, { replace: true });
    }, 350);
    return () => clearTimeout(t);
  }, [term]); // eslint-disable-line react-hooks/exhaustive-deps

  const setParam = (k, v) => {
    const next = new URLSearchParams(sp);
    v ? next.set(k, v) : next.delete(k);
    setSp(next);
  };
  const cats = (store?.categories || []).filter((c) => c.slug !== 'uncategorized');

  return (
    <div className="pmt-container pmt-entry">
      <section className="pm-shop" data-brand={brand}>
        <h1 className="pmt-page-title">{title}</h1>
        {brandObj?.tagline && !category && <p className="pmc-sub">{brandObj.tagline}</p>}
        <form className="pm-toolbar" role="search" onSubmit={(e) => e.preventDefault()}>
          <label className="screen-reader-text" htmlFor="pm-search">Search perfumes</label>
          <input id="pm-search" type="search" className="pm-search" placeholder="Search perfumes, notes…" value={term} onChange={(e) => setTerm(e.target.value)} />
          {!params.category && cats.length > 0 && (
            <>
              <label className="screen-reader-text" htmlFor="pm-cat">Category</label>
              <select id="pm-cat" className="pm-cat" value={category} onChange={(e) => setParam('category', e.target.value)}>
                <option value="">All categories</option>
                {cats.map((c) => <option key={c.slug} value={c.slug}>{c.name}</option>)}
              </select>
            </>
          )}
          <label className="screen-reader-text" htmlFor="pm-sort">Sort by</label>
          <select id="pm-sort" className="pm-sort" value={sort} onChange={(e) => setParam('sort', e.target.value === 'featured' ? '' : e.target.value)}>
            {SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </form>
        {error && <p className="pm-error" role="alert">{error}</p>}
        <ProductGrid products={items} loading={loading} />
        {meta && meta.page < meta.pages && !loading && (
          <div className="pm-more-wrap">
            <button type="button" className="pm-btn pm-btn--ghost" onClick={() => setPage((p) => p + 1)}>Load more</button>
          </div>
        )}
        {meta && <p className="pm-note pm-center" aria-live="polite">{meta.total} perfume{meta.total === 1 ? '' : 's'}</p>}
      </section>
    </div>
  );
}
