const cache = {};
export function money(amount, currency = 'AED') {
  if (amount === null || amount === undefined) return '';
  const key = currency;
  cache[key] ??= new Intl.NumberFormat('en-AE', { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return cache[key].format(Number(amount));
}

export function priceRange(p, currency) {
  if (!p.priced) return null;
  return p.price_min === p.price_max ? money(p.price_min, currency) : `${money(p.price_min, currency)} – ${money(p.price_max, currency)}`;
}

export const statusLabel = (s) =>
  ({
    pending: 'Order placed', confirmed: 'Confirmed', processing: 'Processing', shipped: 'Shipped',
    delivered: 'Delivered', cancelled: 'Cancelled', returned: 'Returned',
  })[s] || s;

export const paymentLabel = (s) =>
  ({ pending: 'Awaiting payment', paid: 'Paid', failed: 'Payment failed', refunded: 'Refunded', cod_pending: 'Pay on delivery' })[s] || s;

export function waLink(number, text) {
  const n = String(number || '').replace(/\D/g, '');
  return n ? `https://wa.me/${n}${text ? '?text=' + encodeURIComponent(text) : ''}` : null;
}
