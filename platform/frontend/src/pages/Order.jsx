import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { api } from '../lib/api';
import { useSeo } from '../lib/seo';
import { useStore } from '../lib/store';
import { waLink } from '../lib/format';
import OrderView from '../components/OrderView';
import StripePay from '../components/StripePay';

/** Order confirmation / status page: /order/PM100001?token=… (token is the order's secret view key). */
export default function Order() {
  const { number } = useParams();
  const [params] = useSearchParams();
  const token = params.get('token') || '';
  const redirect = params.get('redirect_status'); // set by Stripe after 3-D Secure / wallet redirects
  const s = useStore()?.store?.settings || {};
  const [order, setOrder] = useState(null);
  const [error, setError] = useState(null);
  const [retry, setRetry] = useState(false);
  const polls = useRef(0);
  useSeo({ title: `Order ${number}`, noindex: true });

  const load = useCallback(
    () => api(`orders/${encodeURIComponent(number)}?token=${encodeURIComponent(token)}`).then((o) => (setOrder(o), o)),
    [number, token],
  );

  useEffect(() => {
    load().catch(setError);
  }, [load]);

  // The webhook may land a few seconds after Stripe returns the customer; check again a few times.
  const awaitingPayment = order && order.payment_method !== 'cod' && order.payment_status !== 'paid' && order.status === 'pending';
  useEffect(() => {
    if (!awaitingPayment || redirect === 'failed' || polls.current >= (redirect === 'succeeded' ? 8 : 0)) return undefined;
    const t = setTimeout(() => {
      polls.current += 1;
      load().catch(() => {});
    }, 2500);
    return () => clearTimeout(t);
  }, [awaitingPayment, redirect, order, load]);

  if (error) {
    return (
      <div className="pmt-container pmt-entry">
        <div className="pm-empty">
          <h1 className="pmt-page-title">Order not found</h1>
          <p>{error.status === 404 || error.status === 403 ? 'This order link is not valid. Please use the link from your confirmation email, or track your order with your email address.' : error.message}</p>
          <Link className="pm-btn" to="/track-order">Track an Order</Link>
        </div>
      </div>
    );
  }
  if (!order) return <div className="pmt-container pmt-entry"><p className="pm-note">Loading your order…</p></div>;

  const paid = order.payment_status === 'paid';
  const stillChecking = awaitingPayment && redirect === 'succeeded' && polls.current < 8;
  const showPay = awaitingPayment && !stillChecking && order.payment_status !== 'refunded';
  const wa = waLink(s.whatsapp_number, `Hello, I have a question about order ${order.number}.`);

  return (
    <div className="pmt-container pmt-entry">
      <div className="pm-order__thanks">
        {order.ended ? (
          <h1 className="pmt-page-title">Order {order.number}</h1>
        ) : paid || order.payment_method === 'cod' ? (
          <>
            <h1 className="pmt-page-title">Thank you, {order.customer_name?.split(' ')[0] || 'friend'}!</h1>
            <p>Your order is confirmed. We will message you when it ships.{order.payment_method === 'cod' ? ' Please keep the amount ready for the courier.' : ''}</p>
          </>
        ) : (
          <>
            <h1 className="pmt-page-title">Complete your payment</h1>
            <p>Your order is reserved. Pay below to confirm it.</p>
          </>
        )}
      </div>

      <OrderView order={order}>
        {stillChecking && <p className="pm-note" role="status">Confirming your payment with the bank…</p>}
        {redirect === 'failed' && !paid && <p className="pm-error" role="alert">The payment did not go through. You can try again below.</p>}
        {showPay && (
          <div className="pc-box co-pay">
            <h3>Pay securely</h3>
            {retry || redirect !== 'failed' ? (
              <StripePay number={order.number} token={token} onPaid={() => load().catch(() => {})} />
            ) : (
              <button className="pm-btn" onClick={() => setRetry(true)}>Try payment again</button>
            )}
          </div>
        )}
      </OrderView>

      <div className="pm-order__actions">
        <Link className="pm-btn" to="/shop">Continue Shopping</Link>
        {wa && <a className="pm-btn pm-btn--wa" href={wa} target="_blank" rel="noopener noreferrer">Ask on WhatsApp</a>}
      </div>
    </div>
  );
}
