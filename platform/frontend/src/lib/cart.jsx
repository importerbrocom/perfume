import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api } from './api';

const KEY = 'pm_cart_v1';
const CartContext = createContext(null);

function load() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '[]');
    return Array.isArray(v) ? v.filter((i) => i && i.variant_id > 0 && i.quantity > 0) : [];
  } catch {
    return [];
  }
}

/**
 * Cart kept in the browser (survives reloads). Only variant ids + quantities are trusted;
 * prices, stock and totals always come from POST /api/cart/quote.
 */
export function CartProvider({ children }) {
  const [items, setItems] = useState(load);
  const [quote, setQuote] = useState(null);
  const [loading, setLoading] = useState(false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [coupon, setCoupon] = useState(() => localStorage.getItem('pm_coupon') || '');
  const [country, setCountry] = useState('AE');
  const [method, setMethod] = useState(null);
  const seq = useRef(0);

  useEffect(() => {
    localStorage.setItem(KEY, JSON.stringify(items.map(({ variant_id, quantity, snap }) => ({ variant_id, quantity, snap }))));
  }, [items]);
  useEffect(() => {
    coupon ? localStorage.setItem('pm_coupon', coupon) : localStorage.removeItem('pm_coupon');
  }, [coupon]);
  // Other tabs
  useEffect(() => {
    const f = (e) => e.key === KEY && setItems(load());
    window.addEventListener('storage', f);
    return () => window.removeEventListener('storage', f);
  }, []);

  const refresh = useCallback(async () => {
    const n = ++seq.current;
    if (!items.length) {
      setQuote(null);
      return null;
    }
    setLoading(true);
    try {
      const q = await api('cart/quote', {
        method: 'POST',
        body: { items: items.map(({ variant_id, quantity }) => ({ variant_id, quantity })), coupon_code: coupon || null, country, payment_method: method },
      });
      if (n === seq.current) setQuote(q);
      return q;
    } catch (e) {
      if (n === seq.current) setQuote((old) => old);
      throw e;
    } finally {
      if (n === seq.current) setLoading(false);
    }
  }, [items, coupon, country, method]);

  useEffect(() => {
    refresh().catch(() => {});
  }, [refresh]);

  const add = useCallback((variantId, quantity = 1, snap = null) => {
    setItems((list) => {
      const found = list.find((i) => i.variant_id === variantId);
      if (found) return list.map((i) => (i.variant_id === variantId ? { ...i, quantity: Math.min(99, i.quantity + quantity), snap: snap || i.snap } : i));
      return [...list, { variant_id: variantId, quantity: Math.min(99, quantity), snap }];
    });
  }, []);
  const setQty = useCallback((variantId, quantity) => {
    setItems((list) => (quantity <= 0 ? list.filter((i) => i.variant_id !== variantId) : list.map((i) => (i.variant_id === variantId ? { ...i, quantity: Math.min(99, quantity) } : i))));
  }, []);
  const remove = useCallback((variantId) => setItems((list) => list.filter((i) => i.variant_id !== variantId)), []);
  const clear = useCallback(() => {
    setItems([]);
    setCoupon('');
  }, []);
  const replace = useCallback((variantId, newVariantId, snap) => {
    setItems((list) => {
      const old = list.find((i) => i.variant_id === variantId);
      if (!old) return list;
      const rest = list.filter((i) => i.variant_id !== variantId && i.variant_id !== newVariantId);
      const merged = (list.find((i) => i.variant_id === newVariantId)?.quantity || 0) + old.quantity;
      return [...rest, { variant_id: newVariantId, quantity: Math.min(99, merged), snap }];
    });
  }, []);

  const count = items.reduce((n, i) => n + i.quantity, 0);
  const value = useMemo(
    () => ({ items, count, quote, loading, refresh, add, setQty, remove, clear, replace, drawerOpen, setDrawerOpen, coupon, setCoupon, country, setCountry, method, setMethod }),
    [items, count, quote, loading, refresh, add, setQty, remove, clear, replace, drawerOpen, coupon, country, method],
  );
  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export const useCart = () => useContext(CartContext);
