import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, qs } from '../lib/api';
import { useSeo } from '../lib/seo';
import { useWishlist } from '../lib/wishlist';
import ProductGrid from '../components/ProductGrid';

export default function Wishlist() {
  useSeo({ title: 'Wishlist', noindex: true });
  const { ids } = useWishlist();
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const key = ids.join(',');

  useEffect(() => {
    if (!key) {
      setProducts([]);
      setLoading(false);
      return undefined;
    }
    const ctl = new AbortController();
    setLoading(true);
    api('products?' + qs({ ids: key, per_page: 48, sort: 'name' }), { signal: ctl.signal })
      .then((d) => setProducts(d.data))
      .catch((e) => e.name !== 'AbortError' && setError(e.message))
      .finally(() => !ctl.signal.aborted && setLoading(false));
    return () => ctl.abort();
  }, [key]);

  return (
    <div className="pmt-container pmt-entry pm-shop">
      <h1 className="pmt-page-title">Your Wishlist</h1>
      {error && <p className="pm-error" role="alert">{error}</p>}
      <ProductGrid
        products={products}
        loading={loading}
        skeletons={Math.min(ids.length || 4, 8)}
        empty={
          <div className="pm-empty pm-empty--grid">
            <h3>Your wishlist is empty</h3>
            <p>Tap the heart on any perfume to save it here.</p>
            <Link className="pm-btn" to="/shop">Browse Perfumes</Link>
          </div>
        }
      />
    </div>
  );
}
