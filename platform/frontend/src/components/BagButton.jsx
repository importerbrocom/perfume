import { useCart } from '../lib/cart';
import { BagIcon } from './Icons';

export default function BagButton() {
  const { count, setDrawerOpen } = useCart();
  return (
    <button type="button" className="pm-bag" aria-label={`Open shopping bag (${count} item${count === 1 ? '' : 's'})`} onClick={() => setDrawerOpen(true)}>
      <BagIcon />
      {count > 0 && <span className="pm-bag__count" data-testid="bag-count">{count}</span>}
    </button>
  );
}
