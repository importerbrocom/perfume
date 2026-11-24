import { useEffect, useState } from 'react';
import { adminApi, fieldErrors, num, useLoad } from '../lib';
import Countries from '../Countries';
import { Area, Card, ErrorBox, Loading, Num, PageHead, Text, Toggle, useUi } from '../ui';

function Method({ m, onSaved }) {
  const { notify } = useUi();
  const stripe = m.provider === 'stripe';
  const init = () => ({
    is_enabled: m.is_enabled, title: m.title || '', description: m.description || '', fee: m.fee ?? null,
    min_total: m.min_total ?? null, max_total: m.max_total ?? null, countries: m.countries || [],
    publishable_key: m.options?.publishable_key || '', secret_key: '', webhook_secret: '',
  });
  const [f, setF] = useState(init);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => setF(init()), [m]); // eslint-disable-line react-hooks/exhaustive-deps
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const save = async (e) => {
    e?.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    const body = {
      is_enabled: f.is_enabled, title: f.title, description: f.description || null, fee: num(f.fee) ?? 0,
      min_total: num(f.min_total), max_total: num(f.max_total), countries: f.countries.length ? f.countries : null,
    };
    if (stripe) {
      if (f.publishable_key.trim()) body.publishable_key = f.publishable_key.trim();
      if (f.secret_key.trim()) body.secret_key = f.secret_key.trim(); // write-only: empty keeps the saved key
      if (f.webhook_secret.trim()) body.webhook_secret = f.webhook_secret.trim();
    }
    try {
      const saved = await adminApi(`payment-methods/${m.id}`, { method: 'PUT', body });
      onSaved(saved);
      notify(`${saved.title} saved.`);
    } catch (err) {
      setErrors(fieldErrors(err));
      setMessage(err.message);
    } finally {
      setBusy(false);
    }
  };

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(m.webhook_url);
      notify('Webhook link copied.');
    } catch {
      notify('Copy failed. Select the link and copy it by hand.', 'error');
    }
  };

  return (
    <Card title={stripe ? 'Cards, Apple Pay & Google Pay (Stripe)' : 'Cash on delivery'} actions={stripe && <span className={'ad-pill ' + (m.options?.mode === 'live' ? 'ad-pill--delivered' : 'ad-pill--pending')}>{m.options?.mode === 'live' ? 'Live mode' : 'Test mode'}</span>}>
      <form className="ad-form" onSubmit={save} noValidate data-testid={`method-${m.code}`}>
        {message && <div className="ad-alert ad-alert--error ad-wide" role="alert">{message}</div>}
        <div className="ad-wide"><Toggle label={f.is_enabled ? 'Shown at checkout' : 'Turned off'} checked={f.is_enabled} onChange={set('is_enabled')} /></div>
        <Text label="Name at checkout" value={f.title} onChange={set('title')} maxLength={100} error={errors.title} />
        <Num label="Extra fee" value={f.fee} onChange={set('fee')} error={errors.fee} help="Added to the order total. 0 = none." />
        <Area label="Description at checkout" rows={2} value={f.description} onChange={set('description')} maxLength={500} />
        <Num label="Only for orders from" value={f.min_total} onChange={set('min_total')} help="Optional" />
        <Num label="Only for orders up to" value={f.max_total} onChange={set('max_total')} help="Optional, e.g. limit COD to small orders" />
        <Countries label="Only for these countries" value={f.countries} onChange={set('countries')} help="None ticked = every delivery country." />
        {stripe && (
          <>
            <h3 className="ad-wide">Stripe keys</h3>
            <p className="ad-help ad-wide">From Stripe → Developers → API keys. Use test keys (pk_test_ / sk_test_) until you are ready to take real payments. Apple Pay and Google Pay turn on automatically on supported phones.</p>
            <Text label="Publishable key" value={f.publishable_key} onChange={set('publishable_key')} placeholder="pk_test_…" autoComplete="off" spellCheck={false} error={errors.publishable_key} />
            <Text label="Secret key" type="password" value={f.secret_key} onChange={set('secret_key')} autoComplete="new-password" spellCheck={false} error={errors.secret_key}
              placeholder={m.credentials_masked?.secret_key || 'sk_test_…'} help={m.credentials_masked?.secret_key ? `Saved (${m.credentials_masked.secret_key}). Leave empty to keep it.` : 'Never shown again after saving.'} />
            <Text label="Webhook signing secret" type="password" value={f.webhook_secret} onChange={set('webhook_secret')} autoComplete="new-password" spellCheck={false} error={errors.webhook_secret}
              placeholder={m.credentials_masked?.webhook_secret || 'whsec_…'} help={m.credentials_masked?.webhook_secret ? `Saved (${m.credentials_masked.webhook_secret}). Leave empty to keep it.` : 'Needed so paid orders are confirmed automatically.'} />
            <div className="ad-field ad-field--wide">
              <label htmlFor="ad-webhook">Webhook link (add in Stripe → Developers → Webhooks)</label>
              <div className="ad-copy">
                <input id="ad-webhook" readOnly value={m.webhook_url || ''} onFocus={(e) => e.target.select()} />
                <button type="button" className="ad-btn ad-btn--ghost ad-btn--sm" onClick={copy}>Copy</button>
              </div>
              <small className="ad-help">Events: payment_intent.succeeded, payment_intent.payment_failed, charge.refunded.</small>
            </div>
            {!m.is_configured && <p className="ad-alert ad-wide">Enter the publishable and secret keys before turning card payments on.</p>}
          </>
        )}
        <div className="ad-form__foot"><button className="ad-btn" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button></div>
      </form>
    </Card>
  );
}

export default function Payments() {
  const { data, error, loading, reload, setData } = useLoad('payment-methods');
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (loading && !data) return <Loading />;
  return (
    <>
      <PageHead title="Payment methods" sub="Choose how customers can pay. Secret keys are stored encrypted and never shown again." />
      <div className="ad-stack">
        {data.map((m) => <Method key={m.id} m={m} onSaved={(saved) => setData((list) => list.map((x) => (x.id === saved.id ? saved : x)))} />)}
      </div>
    </>
  );
}
