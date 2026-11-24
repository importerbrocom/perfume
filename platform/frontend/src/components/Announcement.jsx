import { Link } from 'react-router-dom';
import { useStore } from '../lib/store';

/** Announcement bar (Admin > Content). Falls back to the trust line from the brand page. */
export default function Announcement() {
  const store = useStore()?.store;
  const items = store?.announcements || [];
  const fallback = store?.settings?.trust_line;
  if (!items.length && !fallback) return null;
  return (
    <div className="pmt-topbar" role="region" aria-label="Announcements">
      {items.length
        ? items.map((a, i) => (
            <span key={a.id}>
              {i > 0 && ' \u00a0•\u00a0 '}
              {a.link_url ? (a.link_url.startsWith('/') ? <Link to={a.link_url}>{a.text}</Link> : <a href={a.link_url}>{a.text}</a>) : a.text}
            </span>
          ))
        : fallback}
    </div>
  );
}
