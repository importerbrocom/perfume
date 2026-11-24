import { useState } from 'react';
import ProductCard, { SkeletonCards } from './ProductCard';
import QuickView from './QuickView';

export default function ProductGrid({ products, loading, skeletons = 8, rail = false, empty }) {
  const [qv, setQv] = useState(null);
  return (
    <>
      <div className={'pm-grid' + (rail ? ' pm-grid--rail' : '')} aria-live="polite" aria-busy={loading}>
        {products.map((p) => <ProductCard key={p.id} p={p} onQuickView={(x) => setQv(x.slug)} />)}
        {loading && <SkeletonCards n={products.length ? 4 : skeletons} />}
        {!loading && !products.length && (empty || (
          <div className="pm-empty pm-empty--grid"><h3>No perfumes found</h3><p>Try a different search or category.</p></div>
        ))}
      </div>
      {qv && <QuickView slug={qv} onClose={() => setQv(null)} />}
    </>
  );
}
