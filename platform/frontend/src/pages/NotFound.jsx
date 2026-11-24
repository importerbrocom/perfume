import { Link } from 'react-router-dom';
import { useSeo } from '../lib/seo';

export default function NotFound() {
  useSeo({ title: 'Page not found', noindex: true });
  return (
    <div className="pmt-container pmt-entry">
      <div className="pm-empty">
        <h1 className="pmt-page-title">Page not found</h1>
        <p>The page you are looking for has moved or no longer exists.</p>
        <div className="pm-order__actions">
          <Link className="pm-btn" to="/shop-perfumarathi">Shop Perfumarathi</Link>
          <Link className="pm-btn pm-btn--ghost" to="/shop-fixnote">Shop Fixnote</Link>
        </div>
      </div>
    </div>
  );
}
