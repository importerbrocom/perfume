import { useEffect, useState } from 'react';
import { adminApi, fieldErrors, toFormData, useLoad } from '../lib';
import Countries from '../Countries';
import { Area, Card, ErrorBox, FileField, Loading, Num, PageHead, Tabs, Text, useUi } from '../ui';

// Text settings saved by this screen (files are sent separately, only when chosen).
const KEYS = [
  'store_name', 'store_tagline', 'currency', 'contact_phone', 'whatsapp_number', 'contact_email', 'store_address',
  'social_instagram', 'social_facebook', 'social_tiktok', 'social_youtube',
  'shipping_flat_rate', 'free_shipping_over', 'shipping_countries', 'shipping_info',
  'return_policy', 'privacy_policy', 'terms', 'about_us',
  'low_stock_threshold', 'card_payment_timeout_minutes', 'order_notification_email', 'apple_pay_domain_association',
  'home_title', 'home_caption', 'perfumarathi_eyebrow', 'perfumarathi_subtitle', 'perfumarathi_foot',
  'fixnote_eyebrow', 'fixnote_subtitle', 'fixnote_foot', 'presence_text', 'trust_line',
];
const FILES = ['logo', 'home_video', 'home_poster', 'perfumarathi_logo', 'fixnote_logo'];
const NUMBERS = ['shipping_flat_rate', 'free_shipping_over', 'low_stock_threshold', 'card_payment_timeout_minutes'];

const pick = (s) => Object.fromEntries(KEYS.map((k) => [k, s[k] ?? (k === 'shipping_countries' ? [] : '')]));

export default function Settings() {
  const { notify } = useUi();
  const { data, error, loading, reload, setData } = useLoad('settings');
  const [tab, setTab] = useState('store');
  const [f, setF] = useState(null);
  const [files, setFiles] = useState({});
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (data) setF(pick(data));
  }, [data]);
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (loading && !f) return <Loading />;
  if (!f) return null;
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
  const t = (k, label, o = {}) => <Text label={label} value={f[k]} onChange={set(k)} error={errors[k]} {...o} />;
  const a = (k, label, o = {}) => <Area label={label} value={f[k]} onChange={set(k)} error={errors[k]} {...o} />;
  const n = (k, label, o = {}) => <Num label={label} value={f[k]} onChange={set(k)} error={errors[k]} {...o} />;
  const file = (k, label, o = {}) => <FileField label={label} current={data[`${k}_url`]} onChange={(v) => setFiles((x) => ({ ...x, [k]: v }))} error={errors[k]} {...o} />;

  const save = async (e) => {
    e.preventDefault();
    if (!f.shipping_countries.length) {
      setErrors({ shipping_countries: 'Choose at least one country you deliver to.' });
      setTab('shipping');
      return;
    }
    setBusy(true);
    setErrors({});
    const body = { ...f };
    for (const k of NUMBERS) if (body[k] === '') body[k] = null;
    for (const k of FILES) if (files[k]) body[k] = files[k];
    try {
      const saved = await adminApi('settings', { method: 'POST', body: toFormData(body) });
      setData(saved);
      setFiles({});
      notify('Settings saved. The shop shows them within a minute.');
    } catch (err) {
      const fe = fieldErrors(err);
      setErrors(fe);
      const first = Object.keys(fe)[0] || '';
      const where = [
        ['store', /^(store_|currency|contact|whatsapp|social|logo)/], ['shipping', /^(shipping|free_)/],
        ['home', /^(home_|perfumarathi|fixnote|presence|trust)/], ['pages', /^(return|privacy|terms|about)/], ['orders', /./],
      ].find(([, re]) => re.test(first));
      if (where) setTab(where[0]);
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={save} noValidate>
      <PageHead title="Store settings" sub="Shop details, delivery, homepage content and policy pages." />
      <Tabs value={tab} onChange={setTab} items={[['store', 'Store & contact'], ['shipping', 'Delivery'], ['home', 'Homepage'], ['pages', 'Policy pages'], ['orders', 'Orders & stock']]} />

      {tab === 'store' && (
        <>
          <Card title="Store">
            <div className="ad-form">
              {t('store_name', 'Store name', { required: true, maxLength: 100 })}
              {t('store_tagline', 'Tagline', { maxLength: 200 })}
              {t('currency', 'Currency', { maxLength: 3, help: 'Three letters, e.g. AED. Changing it does not convert prices.' })}
              {file('logo', 'Logo (header)')}
            </div>
          </Card>
          <Card title="Contact">
            <div className="ad-form">
              {t('contact_phone', 'Phone (shown on the site)', { maxLength: 40 })}
              {t('whatsapp_number', 'WhatsApp number', { maxLength: 20, inputMode: 'numeric', help: 'Digits only with country code, e.g. 971528209716.' })}
              {t('contact_email', 'Email', { type: 'email', maxLength: 150 })}
              {a('store_address', 'Address', { rows: 2, maxLength: 500 })}
            </div>
          </Card>
          <Card title="Social media">
            <div className="ad-form">
              {t('social_instagram', 'Instagram link', { type: 'url' })}
              {t('social_facebook', 'Facebook link', { type: 'url' })}
              {t('social_tiktok', 'TikTok link', { type: 'url' })}
              {t('social_youtube', 'YouTube link', { type: 'url' })}
            </div>
          </Card>
        </>
      )}

      {tab === 'shipping' && (
        <Card title="Delivery">
          <div className="ad-form">
            {n('shipping_flat_rate', 'Delivery charge per order', { help: '0 = free delivery' })}
            {n('free_shipping_over', 'Free delivery for orders over', { help: 'Empty = no free-delivery amount' })}
            <Countries label="Countries you deliver to" value={f.shipping_countries} onChange={set('shipping_countries')} error={errors.shipping_countries} help="Checkout only offers these countries." />
            {a('shipping_info', 'Delivery information page', { rows: 6, help: 'Shown at /pages/shipping.' })}
          </div>
        </Card>
      )}

      {tab === 'home' && (
        <>
          <Card title="Video section">
            <div className="ad-form">
              {t('home_title', 'Title', { maxLength: 100 })}
              {t('home_caption', 'Caption', { maxLength: 150 })}
              {file('home_video', 'Video (MP4 or WebM, up to 100 MB)', { accept: 'video/mp4,video/webm', video: true, help: 'Plays muted on a loop. Leave empty to keep the current video.' })}
              {file('home_poster', 'Poster image (shown while the video loads)')}
            </div>
          </Card>
          <Card title="Perfumarathi half">
            <div className="ad-form">
              {t('perfumarathi_eyebrow', 'Small heading', { maxLength: 100 })}
              {t('perfumarathi_subtitle', 'Subtitle', { maxLength: 200 })}
              {t('perfumarathi_foot', 'Bottom line', { maxLength: 200 })}
              {file('perfumarathi_logo', 'Perfumarathi logo')}
            </div>
          </Card>
          <Card title="Fixnote half">
            <div className="ad-form">
              {t('fixnote_eyebrow', 'Small heading', { maxLength: 100 })}
              {t('fixnote_subtitle', 'Subtitle', { maxLength: 200 })}
              {t('fixnote_foot', 'Bottom line', { maxLength: 200 })}
              {file('fixnote_logo', 'Fixnote logo')}
            </div>
          </Card>
          <Card title="Other texts">
            <div className="ad-form">
              {t('trust_line', 'Top bar line', { maxLength: 200, wide: true })}
              {t('presence_text', 'Where you deliver (footer)', { maxLength: 200, wide: true })}
            </div>
            <p className="ad-help">Banners, product rows and the announcement bar are under Marketing in the menu.</p>
          </Card>
        </>
      )}

      {tab === 'pages' && (
        <Card title="Policy pages" >
          <p className="ad-help">Plain text: an empty line starts a new paragraph. Empty pages are hidden from the shop.</p>
          <div className="ad-form">
            {a('about_us', 'About us (/pages/about)', { rows: 8 })}
            {a('return_policy', 'Returns & refunds (/pages/returns)', { rows: 8 })}
            {a('terms', 'Terms & conditions (/pages/terms)', { rows: 8 })}
            {a('privacy_policy', 'Privacy policy (/pages/privacy)', { rows: 8 })}
          </div>
        </Card>
      )}

      {tab === 'orders' && (
        <>
          <Card title="Orders & stock">
            <div className="ad-form">
              {n('low_stock_threshold', 'Low-stock alert at (units)', { step: 1, help: 'Store default; each size can override it.' })}
              {n('card_payment_timeout_minutes', 'Unpaid card orders expire after (minutes)', { step: 1, min: 15, help: '15–1440. Reserved stock goes back when they expire.' })}
              {t('order_notification_email', 'Send new-order emails to', { type: 'email', maxLength: 150 })}
            </div>
          </Card>
          <Card title="Apple Pay">
            {a('apple_pay_domain_association', 'Domain association file', { rows: 4, help: 'From Stripe → Settings → Payment methods → Apple Pay → Add domain. Paste the file contents here, then press Verify in Stripe.' })}
          </Card>
        </>
      )}

      <div className="ad-savebar">
        <span />
        <button className="ad-btn" disabled={busy} data-testid="save-settings">{busy ? 'Saving…' : 'Save settings'}</button>
      </div>
    </form>
  );
}
