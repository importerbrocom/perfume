import { useEffect, useState } from 'react';

const KEY = 'pm_wish';
const read = () => {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '[]');
    return Array.isArray(v) ? v.filter(Number.isInteger) : [];
  } catch {
    return [];
  }
};

export function useWishlist() {
  const [ids, setIds] = useState(read);
  useEffect(() => {
    const f = () => setIds(read());
    window.addEventListener('pm-wish', f);
    window.addEventListener('storage', f);
    return () => {
      window.removeEventListener('pm-wish', f);
      window.removeEventListener('storage', f);
    };
  }, []);
  const toggle = (id) => {
    const cur = read();
    const next = cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id].slice(-200);
    localStorage.setItem(KEY, JSON.stringify(next));
    window.dispatchEvent(new Event('pm-wish'));
    return next.includes(id);
  };
  return { ids, has: (id) => ids.includes(id), toggle };
}
