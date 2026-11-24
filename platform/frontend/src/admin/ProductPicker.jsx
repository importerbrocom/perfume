import { useEffect, useId, useState } from 'react';
import { adminApi, qs } from './lib';

/** Search-and-add list of products (related products, manual homepage sections). */
export default function ProductPicker({ label, ids, setIds, selfId, max = 24 }) {
  const id = useId();
  const [term, setTerm] = useState('');
  const [found, setFound] = useState([]);
  const [known, setKnown] = useState({});
  useEffect(() => {
    if (!ids.length) return;
    const missing = ids.filter((i) => !known[i]);
    if (!missing.length) return;
    adminApi('products?' + qs({ ids: missing.join(','), per_page: 100 })).then((d) => setKnown((k) => ({ ...k, ...Object.fromEntries(d.data.map((p) => [p.id, p.name])) }))).catch(() => {});
  }, [ids.join()]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (term.trim().length < 2) return setFound([]);
    const t = setTimeout(() => adminApi('products?' + qs({ search: term.trim(), per_page: 10 })).then((d) => {
      setFound(d.data.filter((p) => p.id !== selfId));
      setKnown((k) => ({ ...k, ...Object.fromEntries(d.data.map((p) => [p.id, p.name])) }));
    }).catch(() => {}), 300);
    return () => clearTimeout(t);
  }, [term, selfId]);
  return (
    <div className="ad-field ad-field--wide">
      <label htmlFor={id}>{label}</label>
      <div className="ad-tags">
        {ids.map((id) => (
          <span key={id} className="ad-tag">{known[id] || `#${id}`}<button type="button" aria-label={`Remove ${known[id] || id}`} onClick={() => setIds(ids.filter((x) => x !== id))}>×</button></span>
        ))}
      </div>
      <input id={id} type="search" placeholder="Type a product name to add…" value={term} onChange={(e) => setTerm(e.target.value)} />
      {found.length > 0 && (
        <ul className="ad-suggest">
          {found.filter((p) => !ids.includes(p.id)).map((p) => (
            <li key={p.id}><button type="button" onClick={() => { setIds([...ids, p.id].slice(0, max)); setTerm(''); }}>{p.name} <small>{p.brand}</small></button></li>
          ))}
        </ul>
      )}
    </div>
  );
}
