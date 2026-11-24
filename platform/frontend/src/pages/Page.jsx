import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api } from '../lib/api';
import { useSeo } from '../lib/seo';
import { textToHtml } from '../lib/sanitize';
import NotFound from './NotFound';

/** Store policy pages typed by the admin: about, returns, privacy, terms, shipping. */
export default function Page() {
  const { key } = useParams();
  const [page, setPage] = useState(null);
  const [error, setError] = useState(null);
  useEffect(() => {
    setPage(null);
    setError(null);
    api(`pages/${encodeURIComponent(key)}`).then(setPage).catch(setError);
  }, [key]);
  useSeo({ title: page?.title, description: page ? `${page.title} – Perfumarathi & Fixnote` : undefined });

  if (error?.status === 404) return <NotFound />;
  return (
    <div className="pmt-container pmt-entry pm-page">
      {error && <p className="pm-error" role="alert">{error.message}</p>}
      {!page && !error && <p className="pm-note">Loading…</p>}
      {page && (
        <article>
          <h1 className="pmt-page-title">{page.title}</h1>
          <div className="pm-prose" dangerouslySetInnerHTML={{ __html: textToHtml(page.body) }} />
        </article>
      )}
    </div>
  );
}
