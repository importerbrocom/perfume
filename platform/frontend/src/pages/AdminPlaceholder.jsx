import { useSeo } from '../lib/seo';

/** Temporary /admin screen until the React admin panel (phase 3) ships. The admin API is already live. */
export default function AdminPlaceholder() {
  useSeo({ title: 'Store Admin', noindex: true });
  return (
    <div className="pmt-container pmt-entry">
      <div className="pm-empty">
        <h1 className="pmt-page-title">Store Admin</h1>
        <p>The admin panel is being built and will appear here soon.</p>
      </div>
    </div>
  );
}
