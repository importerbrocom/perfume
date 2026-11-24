import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { adminApi, fieldErrors, num, toFormData, useLoad } from '../lib';
import Countries from '../Countries';
import { Area, Card, Check, ErrorBox, FileField, Loading, Modal, Num, PageHead, Tabs, Text, Toggle, useUi } from '../ui';

const REGIONS = [['UAE', 'UAE'], ['GCC', 'Other GCC / countries'], ['INDIA', 'India / Kerala'], ['SAUDI', 'Saudi']];
const WA_REGIONS = [['INDIA', 'India / Kerala'], ['SAUDI', 'Saudi']];
const IMAGES = [
  ['page_logo', 'Logo at the top of the Fixnote page'],
  ['shop_logo', 'Logo above the catalogue'],
  ['hero', 'Large picture under the title'],
  ['bottle', 'Bottle picture (perfume cards and collections, when a perfume has no photo of its own)'],
];

/** Rows of small text fields (collections, reviews, offer cards...). */
function Rows({ rows, onChange, fields, max = 12, highlight, addLabel, errors = {}, prefix }) {
  const upd = (i, patch) => onChange(rows.map((r, n) => (n === i ? { ...r, ...patch } : r)));
  const move = (i, d) => {
    const next = [...rows];
    [next[i], next[i + d]] = [next[i + d], next[i]];
    onChange(next);
  };
  return (
    <div className="ad-stack">
      {rows.map((r, i) => (
        <div key={i} className="ad-variant">
          <div className="ad-form">
            {fields.map(([k, label, maxLength, area]) => (area
              ? <Area key={k} label={label} rows={2} value={r[k]} onChange={(v) => upd(i, { [k]: v })} maxLength={maxLength} error={errors[`${prefix}.${i}.${k}`]} />
              : <Text key={k} label={label} value={r[k]} onChange={(v) => upd(i, { [k]: v })} maxLength={maxLength} error={errors[`${prefix}.${i}.${k}`]} />))}
            {highlight && <Check label="Orange card (stands out)" checked={r.highlight} onChange={(v) => upd(i, { highlight: v })} />}
          </div>
          <div className="ad-row-actions">
            <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" disabled={i === 0} onClick={() => move(i, -1)} aria-label="Move up">↑</button>
            <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" disabled={i === rows.length - 1} onClick={() => move(i, 1)} aria-label="Move down">↓</button>
            <button type="button" className="ad-btn ad-btn--sm ad-btn--danger-ghost" onClick={() => onChange(rows.filter((_, n) => n !== i))}>Remove</button>
          </div>
        </div>
      ))}
      {rows.length < max && (
        <button type="button" className="ad-btn ad-btn--ghost" onClick={() => onChange([...rows, Object.fromEntries(fields.map(([k]) => [k, '']).concat(highlight ? [['highlight', false]] : []))])}>
          {addLabel}
        </button>
      )}
    </div>
  );
}

/** Pop-up: a new Fixnote perfume with the three standard sizes at the Fixnote prices. */
export function NewFixnotePerfume({ onClose, onSaved }) {
  const [f, setF] = useState({ code: '', name: '', inspired_by: '', notes: '', is_active: true });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      onSaved(await adminApi('fixnote/products', { method: 'POST', body: f }));
    } catch (err) {
      const fe = fieldErrors(err);
      setErrors(Object.keys(fe).length ? fe : { _: err.message });
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal title="Add Fixnote perfume" onClose={onClose} footer={<><button type="button" className="ad-btn ad-btn--ghost" onClick={onClose}>Cancel</button><button type="submit" form="fx-new" className="ad-btn" disabled={busy}>{busy ? 'Saving…' : 'Add perfume'}</button></>}>
      <form id="fx-new" onSubmit={save} noValidate>
        {errors._ && <p className="ad-alert ad-alert--error">{errors._}</p>}
        <div className="ad-form">
          <Text label="Code" required value={f.code} onChange={set('code')} maxLength={20} placeholder="F380" error={errors.code} />
          <Text label="Name" required value={f.name} onChange={set('name')} maxLength={200} placeholder="Imagination" error={errors.name} />
          <Text label="Inspired by" value={f.inspired_by} onChange={set('inspired_by')} maxLength={100} placeholder="Louis Vuitton" error={errors.inspired_by} />
          <Area label="Notes" rows={2} value={f.notes} onChange={set('notes')} maxLength={500} placeholder="Ambrox ■ Black Tea ■ Neroli" error={errors.notes} />
          <Toggle label="Show on the Fixnote page" checked={f.is_active} onChange={set('is_active')} />
        </div>
        <p className="ad-help">It gets 30 ML, 50 ML and 100 ML at the Fixnote prices, always in stock. You can change them on the perfume afterwards.</p>
      </form>
    </Modal>
  );
}

function PricesCard({ data, onSaved }) {
  const { notify, confirm } = useUi();
  const [prices, setPrices] = useState(data.prices);
  const [all, setAll] = useState(true);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  useEffect(() => setPrices(data.prices), [data.prices]);
  const upd = (s, k, v) => setPrices((p) => ({ ...p, [s]: { ...p[s], [k]: v } }));
  const save = async () => {
    if (all && !(await confirm(`Change the price of every Fixnote perfume (${data.counts.products}) for the sizes you changed here? Sizes you did not change keep their prices.`, { ok: 'Change all prices' }))) return;
    setBusy(true);
    setErrors({});
    try {
      const body = { apply_to_all: all, prices: Object.fromEntries(data.sizes.map((s) => [s, { price: num(prices[s]?.price), sale_price: num(prices[s]?.sale_price) }])) };
      const saved = await adminApi('fixnote/prices', { method: 'PUT', body });
      onSaved(saved);
      notify(all ? `Prices saved: ${saved.updated} sizes changed.` : 'Prices saved for new perfumes.'); // updated = 0 when nothing changed
    } catch (err) {
      setErrors(fieldErrors(err));
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card title="Online prices (UAE and Other GCC)">
      <p className="ad-help">One price for each size of every Fixnote perfume, in the store currency. Leave the sale price empty for no sale.</p>
      <div className="ad-form">
        {data.sizes.map((s) => (
          <div key={s} className="ad-variant">
            <strong>{s}</strong>
            <div className="ad-form">
              <Num label="Price" value={prices[s]?.price} onChange={(v) => upd(s, 'price', v)} error={errors[`prices.${s}.price`]} data-testid={`fx-price-${s}`} />
              <Num label="Sale price" value={prices[s]?.sale_price} onChange={(v) => upd(s, 'sale_price', v)} error={errors[`prices.${s}.sale_price`]} />
            </div>
          </div>
        ))}
      </div>
      <div className="ad-variant__foot">
        <Check label={`Also change these sizes on all ${data.counts.products} Fixnote perfumes now`} checked={all} onChange={setAll} help="Untick to only use these prices for perfumes you add later." />
        <button type="button" className="ad-btn" disabled={busy} onClick={save} data-testid="fx-save-prices">{busy ? 'Saving…' : 'Save prices'}</button>
      </div>
    </Card>
  );
}

function ImagesCard({ data, onSaved }) {
  const { notify } = useUi();
  const [files, setFiles] = useState({});
  const [reset, setReset] = useState({});
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      const body = {};
      for (const [k] of IMAGES) {
        if (files[k]) body[k] = files[k];
        else if (reset[k]) body[`reset_${k}`] = true;
      }
      onSaved(await adminApi('fixnote/images', { method: 'POST', body: toFormData(body) }));
      setFiles({});
      setReset({});
      notify('Pictures saved.');
    } catch (err) {
      setErrors(fieldErrors(err));
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card title="Pictures">
      <p className="ad-help">JPG, PNG or WebP up to 8 MB. Until you upload one, the page uses the picture from your original Fixnote page.</p>
      <div className="ad-form">
        {IMAGES.map(([k, label]) => (
          <div key={k}>
            <FileField label={label} current={data.images[k]} onChange={(v) => setFiles((x) => ({ ...x, [k]: v }))} error={errors[k]} />
            {data.images[k] && !files[k] && <Check label="Use the original picture again" checked={!!reset[k]} onChange={(v) => setReset((x) => ({ ...x, [k]: v }))} />}
          </div>
        ))}
      </div>
      <div className="ad-savebar">
        <span />
        <button type="button" className="ad-btn" disabled={busy || (!Object.values(files).some(Boolean) && !Object.values(reset).some(Boolean))} onClick={save}>{busy ? 'Saving…' : 'Save pictures'}</button>
      </div>
    </Card>
  );
}

/** Admin > Fixnote page. */
export default function FixnoteAdmin() {
  const { notify } = useUi();
  const { data, error, loading, reload, setData } = useLoad('fixnote');
  const [tab, setTab] = useState('prices');
  const [f, setF] = useState(null);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const fromData = (d) => ({ page: d.page, delivery: d.delivery, regions: d.regions });
  // Fill the form once; saving prices or pictures must not undo unsaved edits on other tabs.
  useEffect(() => {
    if (data && !f) setF(fromData(data));
  }, [data, f]);
  if (error) return <ErrorBox error={error} onRetry={reload} />;
  if (loading && !f) return <Loading />;
  if (!f) return null;

  const page = f.page;
  const setPage = (k) => (v) => setF((x) => ({ ...x, page: { ...x.page, [k]: v } }));
  const t = (k, label, o = {}) => <Text label={label} value={page[k]} onChange={setPage(k)} error={errors[`page.${k}`]} {...o} />;
  const setDelivery = (k) => (v) => setF((x) => ({ ...x, delivery: { ...x.delivery, [k]: v } }));
  const setRegion = (r, patch) => setF((x) => ({ ...x, regions: { ...x.regions, [r]: { ...x.regions[r], ...patch } } }));

  const save = async (e) => {
    e?.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const body = {
        page: f.page,
        delivery: { ...f.delivery, free_from_pieces: num(f.delivery.free_from_pieces), fees: Object.fromEntries(data.sizes.map((s) => [s, num(f.delivery.fees[s]) ?? 0])) },
        regions: Object.fromEntries(WA_REGIONS.map(([r]) => [r, {
          currency: f.regions[r].currency,
          cod: !!f.regions[r].cod,
          prices: Object.fromEntries(data.sizes.map((s) => [s, num(f.regions[r].prices[s])])),
          delivery: { free_from_pieces: num(f.regions[r].delivery?.free_from_pieces), fees: Object.fromEntries(data.sizes.map((s) => [s, num(f.regions[r].delivery?.fees?.[s]) ?? 0])) },
        }])),
      };
      const saved = await adminApi('fixnote', { method: 'PUT', body });
      setData(saved);
      setF(fromData(saved));
      notify('Fixnote page saved. It shows on the website within a minute.');
    } catch (err) {
      const fe = fieldErrors(err);
      setErrors(fe);
      const first = Object.keys(fe)[0] || '';
      if (/^(delivery)/.test(first)) setTab('prices');
      else if (/^regions/.test(first)) setTab('regions');
      else if (/^page\.offers/.test(first)) setTab('offers');
      else if (first) setTab('page');
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  const saveBar = (
    <div className="ad-savebar">
      <span />
      <button type="submit" className="ad-btn" disabled={busy} data-testid="fx-save">{busy ? 'Saving…' : 'Save Fixnote page'}</button>
    </div>
  );

  return (
    <form onSubmit={save} noValidate>
      <PageHead title="Fixnote page" sub="The page behind the SHOP FIXNOTE button: prices for all sizes, delivery, India / Saudi prices, texts, offer banners and pictures.">
        <a className="ad-btn ad-btn--ghost" href="/shop-fixnote" target="_blank" rel="noopener noreferrer">View page ↗</a>
      </PageHead>
      <div className="ad-filters">
        <Link className="ad-btn ad-btn--ghost" to="/admin/fixnote/products">Fixnote perfumes ({data.counts.visible} shown of {data.counts.products})</Link>
        <Link className="ad-btn ad-btn--ghost" to="/admin/fixnote/orders">Fixnote orders ({data.counts.orders})</Link>
      </div>
      {data.brand && !data.brand.is_active && <p className="ad-alert ad-alert--error">The Fixnote brand is hidden (Catalogue › Brands), so the page shows no perfumes.</p>}
      <Tabs value={tab} onChange={setTab} items={[['prices', 'Prices & UAE delivery'], ['regions', 'India & Saudi'], ['page', 'Page texts'], ['offers', 'Offer banners'], ['images', 'Pictures']]} />

      {tab === 'prices' && (
        <>
          <PricesCard data={data} onSaved={(saved) => setData(saved)} />
          <Card title="Fixnote delivery">
            <p className="ad-help">For Fixnote orders to these countries, the delivery charge depends on the sizes in the order (the highest one applies) and is free from the number of pieces below. Other countries use the normal delivery charge from Store settings.</p>
            <div className="ad-form">
              <Toggle label="Use the Fixnote delivery charges" checked={f.delivery.enabled} onChange={setDelivery('enabled')} />
              {data.sizes.map((s) => (
                <Num key={s} label={`Delivery with ${s}`} value={f.delivery.fees[s]} onChange={(v) => setF((x) => ({ ...x, delivery: { ...x.delivery, fees: { ...x.delivery.fees, [s]: v } } }))} error={errors[`delivery.fees.${s}`]} />
              ))}
              <Num label="Free delivery from (pieces)" step={1} min={1} value={f.delivery.free_from_pieces} onChange={setDelivery('free_from_pieces')} error={errors['delivery.free_from_pieces']} help="Empty = never free." />
              <Countries label="Countries with Fixnote delivery charges" value={f.delivery.countries} onChange={setDelivery('countries')} error={errors['delivery.countries']} />
            </div>
          </Card>
          {saveBar}
        </>
      )}

      {tab === 'regions' && (
        <>
          <Card title="India & Saudi prices">
            <p className="ad-help">Customers in these regions add perfumes to their bag at these prices and check out in this currency (Fixnote perfumes only, delivery to that country only, no coupons). Payment: cash on delivery if switched on, or "Pay after confirmation" (UPI / Google Pay / bank transfer: you send the details on WhatsApp, then press Payment received on the order). Card payment is UAE / GCC only.</p>
            {WA_REGIONS.map(([r, label]) => (
              <div key={r} className="ad-variant">
                <strong>{label}</strong>
                <div className="ad-form">
                  <Text label="Currency" value={f.regions[r].currency} onChange={(v) => setRegion(r, { currency: v.toUpperCase() })} maxLength={3} error={errors[`regions.${r}.currency`]} help="INR, SAR, AED…" />
                  {data.sizes.map((s) => (
                    <Num key={s} label={s} value={f.regions[r].prices[s]} onChange={(v) => setRegion(r, { prices: { ...f.regions[r].prices, [s]: v } })} error={errors[`regions.${r}.prices.${s}`]} />
                  ))}
                  {data.sizes.map((s) => (
                    <Num key={`d${s}`} label={`Delivery with ${s}`} value={f.regions[r].delivery?.fees?.[s]} onChange={(v) => setRegion(r, { delivery: { ...f.regions[r].delivery, fees: { ...f.regions[r].delivery?.fees, [s]: v } } })} error={errors[`regions.${r}.delivery.fees.${s}`]} />
                  ))}
                  <Num label="Free delivery from (pieces)" step={1} min={1} value={f.regions[r].delivery?.free_from_pieces} onChange={(v) => setRegion(r, { delivery: { ...f.regions[r].delivery, free_from_pieces: v } })} error={errors[`regions.${r}.delivery.free_from_pieces`]} help="Empty = never free." />
                  <Toggle label="Cash on delivery" checked={!!f.regions[r].cod} onChange={(v) => setRegion(r, { cod: v })} />
                </div>
              </div>
            ))}
          </Card>
          {saveBar}
        </>
      )}

      {tab === 'page' && (
        <>
          <Card title="Top of the page">
            <div className="ad-form">
              {t('hero_eyebrow', 'Small heading', { maxLength: 80 })}
              {t('hero_title', 'Title', { maxLength: 80 })}
              {t('hero_highlight', 'Title, orange part', { maxLength: 80 })}
              <Area label="Text under the title" rows={2} value={page.hero_text} onChange={setPage('hero_text')} maxLength={400} error={errors['page.hero_text']} />
            </div>
          </Card>
          <Card title="Collections (scrolling cards)">
            <div className="ad-form">
              {t('collections_title', 'Heading', { maxLength: 100 })}
              {t('collections_text', 'Text', { maxLength: 200 })}
            </div>
            <Rows rows={page.collections} onChange={setPage('collections')} fields={[['title', 'Name', 60], ['text', 'Small line', 80]]} addLabel="+ Add collection" errors={errors} prefix="page.collections" />
          </Card>
          <Card title="Region buttons">
            <div className="ad-form">{t('regions_title', 'Heading', { maxLength: 80 })}</div>
            {REGIONS.map(([r, label]) => (
              <div key={r} className="ad-variant">
                <strong>{label}</strong>
                <div className="ad-form">
                  <Text label="Button text" value={page.region_cards[r]?.title} onChange={(v) => setPage('region_cards')({ ...page.region_cards, [r]: { ...page.region_cards[r], title: v } })} maxLength={60} />
                  <Text label="Small line" value={page.region_cards[r]?.text} onChange={(v) => setPage('region_cards')({ ...page.region_cards, [r]: { ...page.region_cards[r], text: v } })} maxLength={100} />
                </div>
              </div>
            ))}
          </Card>
          <Card title="Highlights (boxes under the regions)">
            <Rows rows={page.benefits} onChange={setPage('benefits')} fields={[['title', 'Big text', 40], ['text', 'Small text', 60]]} max={6} addLabel="+ Add box" errors={errors} prefix="page.benefits" />
          </Card>
          <Card title="Reviews">
            <div className="ad-form">{t('reviews_title', 'Heading', { maxLength: 100 })}</div>
            <Rows rows={page.reviews} onChange={setPage('reviews')} fields={[['text', 'Review', 400, true]]} addLabel="+ Add review" errors={errors} prefix="page.reviews" />
          </Card>
          <Card title="WhatsApp">
            <div className="ad-form">
              {t('whatsapp_number', 'WhatsApp number', { maxLength: 20, inputMode: 'numeric', help: 'Digits with country code, e.g. 971528209716. Selections are sent here.' })}
              {t('whatsapp_title', 'Heading', { maxLength: 100 })}
              {t('whatsapp_text', 'Text', { maxLength: 300 })}
              {t('whatsapp_button', 'Button', { maxLength: 80 })}
              <Area label="First message of the WhatsApp chat" rows={3} value={page.whatsapp_message} onChange={setPage('whatsapp_message')} maxLength={1000} error={errors['page.whatsapp_message']} />
              {t('home_foot', 'Black bottom line', { maxLength: 200, wide: true })}
            </div>
          </Card>
          <Card title="Catalogue">
            <div className="ad-form">
              {t('shop_sub', 'Line under the logo', { maxLength: 150 })}
              {t('shop_parent', 'Orange line under it', { maxLength: 150 })}
              {t('product_type', 'Perfume type label', { maxLength: 60, help: 'e.g. EXTRAIT PERFUME' })}
              {t('footer_title', 'Contact box title', { maxLength: 60 })}
              {t('footer_sub', 'Contact box line', { maxLength: 100 })}
              {t('footer_shipping', 'Shipping available', { maxLength: 200 })}
              {t('footer_email', 'Email', { type: 'email', maxLength: 150 })}
              {t('footer_phone', 'Mobile / WhatsApp (shown)', { maxLength: 40 })}
            </div>
          </Card>
          {saveBar}
        </>
      )}

      {tab === 'offers' && (
        <>
          <p className="ad-help">Scrolling cards at the top of each region's catalogue. Write ~~text~~ to show it struck through. These are texts: update them when you change prices.</p>
          {REGIONS.map(([r, label]) => (
            <Card key={r} title={label}>
              <Rows
                rows={page.offers[r] || []} onChange={(rows) => setPage('offers')({ ...page.offers, [r]: rows })}
                fields={[['tag', 'Small label', 60], ['title', 'Big text', 80], ['text', 'Line under it', 200]]} highlight addLabel="+ Add card" errors={errors} prefix={`page.offers.${r}`}
              />
            </Card>
          ))}
          {saveBar}
        </>
      )}

      {tab === 'images' && <ImagesCard data={data} onSaved={(saved) => setData(saved)} />}
    </form>
  );
}
