import { statusLabel } from '../lib/format';

/** Order progress (same markup as the WordPress tracking timeline). */
export default function Timeline({ order }) {
  if (order.ended) {
    return <p className="pm-timeline__ended">This order was <strong>{statusLabel(order.ended).toLowerCase()}</strong>.</p>;
  }
  const current = order.timeline.filter((t) => t.done).length - 1;
  return (
    <ol className="pm-timeline">
      {order.timeline.map((t, i) => (
        <li key={t.status} className={i < current ? 'is-done' : i === current ? 'is-current' : ''} aria-current={i === current ? 'step' : undefined}>
          <span className="pm-timeline__dot" />
          <span className="pm-timeline__label">{statusLabel(t.status)}</span>
        </li>
      ))}
    </ol>
  );
}
