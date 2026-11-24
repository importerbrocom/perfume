import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { CartProvider, useCart } from './cart';

const wrapper = ({ children }) => <CartProvider>{children}</CartProvider>;

describe('cart', () => {
  let bodies;
  beforeEach(() => {
    bodies = [];
    vi.stubGlobal('fetch', vi.fn(async (_url, init) => {
      const body = JSON.parse(init.body);
      bodies.push(body);
      return new Response(JSON.stringify({ lines: [], subtotal: 10, total: 10, issues: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }));
  });
  afterEach(() => vi.unstubAllGlobals());

  it('merges quantities, caps at 99 and only sends ids + quantities to the server', async () => {
    const { result } = renderHook(() => useCart(), { wrapper });
    act(() => result.current.add(5, 2, { name: 'Oud', price: 1 }));
    act(() => result.current.add(5, 120));
    expect(result.current.items).toEqual([{ variant_id: 5, quantity: 99, snap: { name: 'Oud', price: 1 } }]);
    expect(result.current.count).toBe(99);
    await waitFor(() => expect(result.current.quote?.total).toBe(10));
    expect(bodies.at(-1).items).toEqual([{ variant_id: 5, quantity: 99 }]);
  });

  it('removes a line when its quantity reaches zero and persists to localStorage', () => {
    const { result } = renderHook(() => useCart(), { wrapper });
    act(() => {
      result.current.add(1);
      result.current.add(2, 3);
    });
    act(() => result.current.setQty(1, 0));
    expect(result.current.items.map((i) => i.variant_id)).toEqual([2]);
    expect(JSON.parse(localStorage.getItem('pm_cart_v1'))).toEqual([{ variant_id: 2, quantity: 3, snap: null }]);
  });

  it('ignores corrupt stored data', () => {
    localStorage.setItem('pm_cart_v1', '{"not":"a list"}');
    const { result } = renderHook(() => useCart(), { wrapper });
    expect(result.current.items).toEqual([]);
  });

  it('replace() swaps a size and merges with an existing line', () => {
    const { result } = renderHook(() => useCart(), { wrapper });
    act(() => {
      result.current.add(1, 2);
      result.current.add(2, 1);
    });
    act(() => result.current.replace(1, 2, null));
    expect(result.current.items).toEqual([{ variant_id: 2, quantity: 3, snap: null }]);
  });
});
