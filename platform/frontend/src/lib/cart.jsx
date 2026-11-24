import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api } from './api';

const KEY = 'pm_cart_v1';
const REGION_KEY = 'pm_cart_region';
/** Fixnote price regions: the bag is priced in that region's currency (Fixnote perfumes only). */
export const PRICE_REGIONS = { INDIA: { country: 'IN', label: 'India / Kerala' }, SAUDI: { country: 'SA', label: 'Saudi Arabia' } };
const loadRegion = () => {
  const r = localStorage.getItem(REGION_KEY);
  return PRICE_REGIONS[r] ? r : null;
};
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
  const [region, setRegion] = useState(loadRegion);
  const itemsRef = useRef(items);
  itemsRef.current = items;
  const regionRef = useRef(region);
  regionRef.current = region;
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
  useEffect(() => {
    region ? localStorage.setItem(REGION_KEY, region) : localStorage.removeItem(REGION_KEY);
    if (region) setCountry(PRICE_REGIONS[region].country);
  }, [region]);
  // An empty bag has no region.
  useEffect(() => {
    if (!items.length && region) setRegion(null);
  }, [items.length, region]);
  // Other tabs
  useEffect(() => {
    const f = (e) => {
      if (e.key === KEY) setItems(load());
      if (e.key === REGION_KEY) setRegion(loadRegion());
    };
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
        body: { items: items.map(({ variant_id, quantity }) => ({ variant_id, quantity })), coupon_code: region ? null : coupon || null, country: region ? PRICE_REGIONS[region].country : country, payment_method: method, region },
      });
      if (n === seq.current) setQuote(q);
      return q;
    } catch (e) {
      if (n === seq.current) setQuote((old) => old);
      throw e;
    } finally {
      if (n === seq.current) setLoading(false);
    }
  }, [items, coupon, country, method, region]);

  useEffect(() => {
    refresh().catch(() => {});
  }, [refresh]);

  /**
   * Adds to the bag. `forRegion` = INDIA / SAUDI for Fixnote regional prices, null for normal (store currency) prices.
   * A bag holds one kind only: switching asks first and starts a new bag. Returns false when the customer says no.
   */
  const add = useCallback((variantId, quantity = 1, snap = null, forRegion = null) => {
    const current = regionRef.current;
    if (itemsRef.current.length && current !== forRegion) {
      const from = current ? `${PRICE_REGIONS[current].label} prices` : 'UAE prices';
      const to = forRegion ? `${PRICE_REGIONS[forRegion].label} prices` : 'UAE prices';
      if (!window.confirm(`Your bag has items with ${from}. Start a new bag with ${to}? The items in your bag will be removed.`)) return false;
      itemsRef.current = [];
      setItems([]);
    }
    if (forRegion !== current) {
      regionRef.current = forRegion;
      setRegion(forRegion);
      if (forRegion) setCoupon('');
    }
    setItems((list) => {
      const found = list.find((i) => i.variant_id === variantId);
      if (found) return list.map((i) => (i.variant_id === variantId ? { ...i, quantity: Math.min(99, i.quantity + quantity), snap: snap || i.snap } : i));
      return [...list, { variant_id: variantId, quantity: Math.min(99, quantity), snap }];
    });
    return true;
  }, []);
  const setQty = useCallback((variantId, quantity) => {
    setItems((list) => (quantity <= 0 ? list.filter((i) => i.variant_id !== variantId) : list.map((i) => (i.variant_id === variantId ? { ...i, quantity: Math.min(99, quantity) } : i))));
  }, []);
  const remove = useCallback((variantId) => setItems((list) => list.filter((i) => i.variant_id !== variantId)), []);
  const clear = useCallback(() => {
    setItems([]);
    setCoupon('');
    setRegion(null);
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
    () => ({ items, count, quote, loading, refresh, add, setQty, remove, clear, replace, drawerOpen, setDrawerOpen, coupon, setCoupon, country, setCountry, method, setMethod, region }),
    [items, count, quote, loading, refresh, add, setQty, remove, clear, replace, drawerOpen, coupon, country, method, region],
  );
  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export const useCart = () => useContext(CartContext);
