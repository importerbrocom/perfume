import { useEffect, useMemo, useState } from 'react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { api } from '../lib/api';
import { money } from '../lib/format';

const stripes = {};
const getStripe = (pk) => (stripes[pk] ??= loadStripe(pk));

function PayForm({ returnUrl, amount, currency, onPaid }) {
  const stripe = useStripe();
  const elements = useElements();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const submit = async (e) => {
    e.preventDefault();
    if (!stripe || !elements) return;
    setBusy(true);
    setError(null);
    // Stripe handles 3-D Secure, Apple Pay and Google Pay; it only redirects when the method needs it.
    const { error: err, paymentIntent } = await stripe.confirmPayment({ elements, confirmParams: { return_url: returnUrl }, redirect: 'if_required' });
    setBusy(false);
    if (err) setError(err.message || 'The payment did not go through. Please try again or use another card.');
    else if (paymentIntent && ['succeeded', 'processing'].includes(paymentIntent.status)) onPaid?.();
  };
  return (
    <form className="pp-pay" onSubmit={submit}>
      <PaymentElement options={{ layout: 'tabs', wallets: { applePay: 'auto', googlePay: 'auto' } }} />
      {error && <p className="pm-error" role="alert">{error}</p>}
      <button className="pm-btn pp-paybtn" disabled={!stripe || busy}>{busy ? 'Processing…' : `Pay ${money(amount, currency)}`}</button>
      <p className="pm-note">Payments are processed securely by Stripe. Card details never touch our servers.</p>
    </form>
  );
}

/** Loads (or reuses) the order's Stripe PaymentIntent and shows Stripe's payment form. */
export default function StripePay({ number, token, onPaid }) {
  const [intent, setIntent] = useState(null);
  const [error, setError] = useState(null);
  useEffect(() => {
    api(`orders/${number}/pay`, { method: 'POST', body: { token } })
      .then((d) => (d.status === 'paid' ? onPaid?.() : setIntent(d)))
      .catch((e) => setError(e.message));
  }, [number, token]); // eslint-disable-line react-hooks/exhaustive-deps
  const options = useMemo(
    () => intent && ({
      clientSecret: intent.client_secret,
      appearance: { theme: 'stripe', variables: { colorPrimary: '#4b2c16', colorText: '#1f1711', fontFamily: 'Arial, Helvetica, sans-serif', borderRadius: '12px' } },
    }),
    [intent],
  );
  if (error) return <p className="pm-error" role="alert">{error}</p>;
  if (!intent) return <p className="pm-note">Loading secure payment…</p>;
  const returnUrl = `${window.location.origin}/order/${encodeURIComponent(number)}?token=${encodeURIComponent(token)}`;
  return (
    <Elements stripe={getStripe(intent.publishable_key)} options={options}>
      <PayForm returnUrl={returnUrl} amount={intent.amount} currency={intent.currency} onPaid={onPaid} />
    </Elements>
  );
}
