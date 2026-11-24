import { useState } from 'react';
import { adminApi, fmtDateTime, qs, useLoad } from '../lib';
import { Empty, ErrorBox, Loading, PageHead, Pager, Tabs, useUi } from '../ui';

export default function Reviews() {
  const { notify, confirm } = useUi();
  const [status, setStatus] = useState('pending');
  const [page, setPage] = useState(1);
  const { data, error, loading, reload } = useLoad('reviews?' + qs({ status, page }));
  const act = async (r, what) => {
    try {
      if (what === 'delete') {
        if (!(await confirm('Delete this review permanently?', { ok: 'Delete', danger: true }))) return;
        await adminApi(`reviews/${r.id}`, { method: 'DELETE' });
      } else {
        await adminApi(`reviews/${r.id}`, { method: 'PUT', body: { is_approved: what === 'approve' } });
      }
      notify(what === 'approve' ? 'Review is now on the product page.' : what === 'hide' ? 'Review hidden.' : 'Review deleted.');
      reload();
    } catch (e) {
      notify(e.message, 'error');
    }
  };
  return (
    <>
      <PageHead title="Reviews" sub="New reviews stay hidden until you approve them." />
      <Tabs value={status} onChange={(v) => { setStatus(v); setPage(1); }} items={[['pending', 'Waiting'], ['approved', 'Approved'], ['', 'All']]} />
      <ErrorBox error={error} onRetry={reload} />
      {loading && !data ? <Loading /> : data && (data.data.length === 0 ? <Empty>{status === 'pending' ? 'No reviews waiting.' : 'No reviews.'}</Empty> : (
        <ul className="ad-reviews">
          {data.data.map((r) => (
            <li key={r.id} className="ad-card">
              <div className="ad-review__head">
                <span className="ad-stars" aria-label={`${r.rating} out of 5`}>{'★'.repeat(r.rating)}<span>{'★'.repeat(5 - r.rating)}</span></span>
                <b>{r.author_name}</b>
                {r.author_email && <a href={`mailto:${r.author_email}`} className="ad-muted">{r.author_email}</a>}
                <span className="ad-muted">{fmtDateTime(r.created_at)}</span>
              </div>
              <p className="ad-muted">On <a href={`/product/${r.product?.slug}`} target="_blank" rel="noopener noreferrer">{r.product?.name}</a></p>
              <p className="ad-review__body">{r.body}</p>
              <div className="ad-actions">
                {r.is_approved ? (
                  <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" onClick={() => act(r, 'hide')}>Hide</button>
                ) : (
                  <button type="button" className="ad-btn ad-btn--sm" onClick={() => act(r, 'approve')}>Approve</button>
                )}
                <button type="button" className="ad-btn ad-btn--sm ad-btn--danger-ghost" onClick={() => act(r, 'delete')}>Delete</button>
              </div>
            </li>
          ))}
        </ul>
      ))}
      <Pager meta={data?.meta} onPage={setPage} />
    </>
  );
}
