import { useMemo, useState } from 'react';
import { money } from '../../lib/format';
import { useAuth } from '../AdminApp';
import ProductPicker from '../ProductPicker';
import { adminApi, fieldErrors, fmtDate, fmtDateTime, fromLocalInput, num, toFormData, toLocalInput, useLoad } from '../lib';
import { Area, Empty, ErrorBox, FileField, Loading, Modal, Num, PageHead, Select, Text, Toggle, useUi } from '../ui';

/*
 * One screen for the simple lists: coupons, banners, homepage sections, announcement bar, brands, categories, staff.
 * Each kind describes its columns and form fields; the API is a plain list + create / update / delete.
 */

const active = (r) => <span className={'ad-pill ' + (r.is_active ? 'ad-pill--delivered' : 'ad-pill--muted')}>{r.is_active ? 'On' : 'Off'}</span>;
const when = (r) => (r.starts_at || r.ends_at ? `${r.starts_at ? fmtDate(r.starts_at) : '…'} → ${r.ends_at ? fmtDate(r.ends_at) : '…'}` : 'Always');
const SOURCES = [['featured', 'Featured products'], ['best_sellers', 'Best sellers'], ['new_arrivals', 'New arrivals'], ['on_sale', 'On sale'], ['category', 'A category / collection'], ['brand', 'A brand'], ['manual', 'Products I choose']];

const KINDS = {
  coupons: {
    title: 'Coupons', sub: 'Discount codes customers type in the bag or at checkout.', path: 'coupons', noun: 'coupon',
    columns: [
      ['Code', (r) => <b className="ad-code">{r.code}</b>],
      ['Discount', (r) => (r.type === 'percent' ? `${Number(r.value)}%` : money(r.value))],
      ['Used', (r) => `${r.used_count}${r.usage_limit ? ` / ${r.usage_limit}` : ''}`],
      ['Valid', when], ['Status', active],
    ],
    blank: { code: '', type: 'percent', value: 10, min_subtotal: null, max_discount: null, usage_limit: null, starts_at: null, ends_at: null, is_active: true },
    fields: [
      ['code', 'text', 'Code', { required: true, maxLength: 40, help: 'Letters, numbers, - and _. Saved in capitals.' }],
      ['type', 'select', 'Type', { options: [['percent', 'Percent off'], ['fixed', 'Fixed amount off']] }],
      ['value', 'number', 'Amount', { help: 'Percent (1–100) or amount in AED' }],
      ['min_subtotal', 'number', 'Minimum order', { help: 'Optional' }],
      ['max_discount', 'number', 'Maximum discount', { help: 'Optional, for percent coupons' }],
      ['usage_limit', 'number', 'Total uses allowed', { step: 1, min: 1, help: 'Empty = unlimited' }],
      ['starts_at', 'datetime', 'Starts'], ['ends_at', 'datetime', 'Ends'],
      ['is_active', 'toggle', 'Active'],
    ],
  },
  banners: {
    title: 'Banners', sub: 'Pictures with a link, shown on the homepage below the brand section.', path: 'banners', noun: 'banner', multipart: true,
    columns: [
      ['Image', (r) => (r.image_url ? <img src={r.image_url} alt="" className="ad-banner-thumb" /> : '—')],
      ['Title', (r) => <><b>{r.title || '—'}</b><small className="ad-block ad-muted">{r.link_url}</small></>],
      ['Where', (r) => (r.placement === 'home' ? 'Homepage' : 'Promo')], ['Shown', when], ['Status', active],
    ],
    blank: { placement: 'home', title: '', subtitle: '', button_text: '', link_url: '', is_active: true, sort_order: 0, starts_at: null, ends_at: null, image: undefined, mobile_image: undefined },
    fields: [
      ['image', 'image', 'Image (wide, e.g. 1600×600)', { current: 'image_url', requiredNew: true }],
      ['mobile_image', 'image', 'Phone image (optional, e.g. 800×1000)', { current: 'mobile_image_url' }],
      ['title', 'text', 'Title', { maxLength: 200 }], ['button_text', 'text', 'Button text', { maxLength: 60, placeholder: 'Shop now' }],
      ['subtitle', 'area', 'Text', { maxLength: 500, rows: 2 }],
      ['link_url', 'text', 'Link', { maxLength: 500, placeholder: '/shop-fixnote or https://…', help: 'Starts with / for a page on this site.' }],
      ['placement', 'select', 'Where', { options: [['home', 'Homepage'], ['promo', 'Promo strip']] }],
      ['sort_order', 'number', 'Order', { step: 1 }],
      ['starts_at', 'datetime', 'Show from'], ['ends_at', 'datetime', 'Show until'],
      ['is_active', 'toggle', 'Active'],
    ],
  },
  'home-sections': {
    title: 'Homepage sections', sub: 'Product rows on the homepage, in this order.', path: 'home-sections', noun: 'section',
    columns: [
      ['Title', (r) => <b>{r.title}</b>], ['Shows', (r) => SOURCES.find((s) => s[0] === r.source)?.[1] || r.source],
      ['Max', (r) => r.limit], ['Order', (r) => r.sort_order], ['Status', active],
    ],
    blank: { title: '', subtitle: '', source: 'featured', source_id: null, product_ids: [], limit: 8, is_active: true, sort_order: 0 },
    fields: [
      ['title', 'text', 'Title', { required: true, maxLength: 120 }], ['subtitle', 'text', 'Subtitle', { maxLength: 500 }],
      ['source', 'select', 'Products to show', { options: SOURCES }],
      ['source_id', 'source', 'Which one'],
      ['product_ids', 'products', 'Products'],
      ['limit', 'number', 'How many', { step: 1, min: 1, max: 24 }], ['sort_order', 'number', 'Order', { step: 1 }],
      ['is_active', 'toggle', 'Active'],
    ],
  },
  announcements: {
    title: 'Announcement bar', sub: 'Short messages in the dark bar at the top of every page.', path: 'announcements', noun: 'message',
    columns: [['Message', (r) => <><b>{r.text}</b><small className="ad-block ad-muted">{r.link_url}</small></>], ['Shown', when], ['Order', (r) => r.sort_order], ['Status', active]],
    blank: { text: '', link_url: '', is_active: true, sort_order: 0, starts_at: null, ends_at: null },
    fields: [
      ['text', 'text', 'Message', { required: true, maxLength: 300, wide: true, placeholder: 'Free delivery in the UAE over AED 200' }],
      ['link_url', 'text', 'Link (optional)', { maxLength: 500, placeholder: '/shop' }], ['sort_order', 'number', 'Order', { step: 1 }],
      ['starts_at', 'datetime', 'Show from'], ['ends_at', 'datetime', 'Show until'], ['is_active', 'toggle', 'Active'],
    ],
  },
  brands: {
    title: 'Brands', sub: 'Perfumarathi, Fixnote and any other brands you sell.', path: 'brands', noun: 'brand', multipart: true,
    columns: [
      ['Brand', (r) => <><b>{r.name}</b><small className="ad-block ad-muted">/brand/{r.slug}</small></>],
      ['Tagline', (r) => r.tagline || '—'], ['Products', (r) => r.products_count], ['Status', active],
    ],
    blank: { name: '', slug: '', tagline: '', description: '', is_active: true, sort_order: 0, logo: undefined },
    fields: [
      ['name', 'text', 'Name', { required: true, maxLength: 100 }], ['tagline', 'text', 'Tagline', { maxLength: 200 }],
      ['slug', 'text', 'Web address (slug)', { maxLength: 100, help: 'Made from the name if empty. Changing it changes the shop link.' }],
      ['sort_order', 'number', 'Order', { step: 1 }],
      ['description', 'area', 'Description', { maxLength: 5000 }], ['logo', 'image', 'Logo', { current: 'logo_url' }],
      ['is_active', 'toggle', 'Active'],
    ],
    canDelete: (r) => (r.products_count > 0 ? 'Move or delete this brand’s products first.' : null),
  },
  categories: {
    title: 'Categories & collections', sub: 'Categories (Men, Women, Oud…) and collections (Combos, Luxury…) used to group products.', path: 'categories', noun: 'category', multipart: true,
    columns: [
      ['Name', (r) => <><b>{r.name}</b><small className="ad-block ad-muted">/category/{r.slug}</small></>],
      ['Type', (r) => (r.kind === 'collection' ? 'Collection' : 'Category')], ['Products', (r) => r.products_count], ['Status', active],
    ],
    blank: { name: '', slug: '', kind: 'category', parent_id: null, description: '', is_active: true, sort_order: 0, image: undefined },
    fields: [
      ['name', 'text', 'Name', { required: true, maxLength: 100 }],
      ['kind', 'select', 'Type', { options: [['category', 'Category'], ['collection', 'Collection']] }],
      ['parent_id', 'parent', 'Inside (optional)'],
      ['slug', 'text', 'Web address (slug)', { maxLength: 100, help: 'Made from the name if empty.' }],
      ['sort_order', 'number', 'Order', { step: 1 }],
      ['description', 'area', 'Description', { maxLength: 5000 }], ['image', 'image', 'Image', { current: 'image_url' }],
      ['is_active', 'toggle', 'Active'],
    ],
    deleteText: 'Delete this category? Its products stay in the shop; they are only removed from this category.',
  },
  staff: {
    title: 'Staff accounts', sub: 'Admins can change everything. Managers handle orders, products and stock, but not settings, payments, refunds or staff.', path: 'staff', noun: 'staff account',
    columns: [
      ['Name', (r) => <><b>{r.name}</b><small className="ad-block ad-muted">{r.email}</small></>],
      ['Role', (r) => (r.role === 'admin' ? 'Admin' : 'Manager')], ['Last login', (r) => fmtDateTime(r.last_login_at)], ['Status', active],
    ],
    blank: { name: '', email: '', role: 'manager', is_active: true, password: '' },
    fields: [
      ['name', 'text', 'Name', { required: true, maxLength: 100 }], ['email', 'text', 'Email', { required: true, type: 'email', maxLength: 150 }],
      ['role', 'select', 'Role', { options: [['manager', 'Manager'], ['admin', 'Admin']] }],
      ['password', 'password', 'Password', { help: 'At least 10 characters with letters and numbers. Leave empty to keep the current password.' }],
      ['is_active', 'toggle', 'Can log in'],
    ],
    canDelete: (r, me) => (r.id === me.id ? 'You cannot delete your own account.' : null),
  },
};

function Editor({ kind, cfg, row, onClose, onSaved, lookups }) {
  const { notify } = useUi();
  const isNew = !row?.id;
  const [f, setF] = useState(() => {
    const base = { ...cfg.blank };
    if (row) for (const k of Object.keys(base)) if (k in row && !(base[k] === undefined)) base[k] = row[k];
    if (kind === 'staff') base.password = '';
    for (const [k, type] of cfg.fields) if (type === 'datetime') base[k] = toLocalInput(base[k]);
    return base;
  });
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    const body = {};
    for (const [k, type] of cfg.fields) {
      let v = f[k];
      if (type === 'datetime') v = fromLocalInput(v);
      if (type === 'number' || type === 'source' || type === 'parent') v = num(v);
      if ((type === 'text' || type === 'area') && v === '') v = null;
      if (type === 'image' && !(v instanceof File)) continue;
      if (type === 'password' && !v) continue;
      if (type === 'products' && f.source !== 'manual') v = [];
      if (type === 'source' && !['category', 'brand'].includes(f.source)) v = null;
      body[k] = v;
    }
    if (kind === 'home-sections' && f.source === 'manual') body.product_ids = f.product_ids;
    const path = isNew ? cfg.path : `${cfg.path}/${row.id}`;
    try {
      const saved = cfg.multipart
        ? await adminApi(path, { method: 'POST', body: toFormData(body) })
        : await adminApi(path, { method: isNew ? 'POST' : 'PUT', body });
      notify(`${cfg.noun[0].toUpperCase() + cfg.noun.slice(1)} saved.`);
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      setMessage(err.message);
    } finally {
      setBusy(false);
    }
  };

  const field = ([k, type, label, o = {}]) => {
    const common = { key: k, label, error: errors[k], help: o.help };
    switch (type) {
      case 'text': return <Text {...common} value={f[k]} onChange={set(k)} required={o.required} maxLength={o.maxLength} placeholder={o.placeholder} type={o.type} wide={o.wide} />;
      case 'password': return <Text {...common} value={f[k]} onChange={set(k)} type="password" autoComplete="new-password" required={isNew} />;
      case 'area': return <Area {...common} value={f[k]} onChange={set(k)} rows={o.rows || 3} maxLength={o.maxLength} />;
      case 'number': return <Num {...common} value={f[k]} onChange={set(k)} step={o.step ?? 'any'} min={o.min ?? 0} max={o.max} />;
      case 'select': return <Select {...common} value={f[k]} onChange={set(k)} options={o.options} />;
      case 'datetime': return <Text {...common} type="datetime-local" value={f[k]} onChange={set(k)} />;
      case 'toggle': return <div key={k} className="ad-wide"><Toggle label={label} checked={f[k]} onChange={set(k)} /></div>;
      case 'image': return <FileField {...common} current={row?.[o.current]} onChange={set(k)} help={o.help || (isNew && o.requiredNew ? 'Required. JPG, PNG or WebP.' : 'JPG, PNG or WebP. Leave empty to keep the current one.')} />;
      case 'parent': return <Select {...common} value={f[k] ?? ''} onChange={set(k)} empty="— none —" options={lookups.categories.filter((c) => c.id !== row?.id).map((c) => [c.id, c.name])} />;
      case 'source':
        if (f.source === 'category') return <Select {...common} label="Category" value={f[k] ?? ''} onChange={set(k)} empty="Choose…" options={lookups.categories.map((c) => [c.id, c.name])} />;
        if (f.source === 'brand') return <Select {...common} label="Brand" value={f[k] ?? ''} onChange={set(k)} empty="Choose…" options={lookups.brands.map((b) => [b.id, b.name])} />;
        return null;
      case 'products':
        return f.source === 'manual' ? <div key={k} className="ad-wide"><ProductPicker label={label} ids={f.product_ids || []} setIds={set('product_ids')} />{errors.product_ids && <small className="ad-error">{errors.product_ids}</small>}</div> : null;
      default: return null;
    }
  };

  return (
    <Modal title={isNew ? `Add ${cfg.noun}` : `Edit ${cfg.noun}`} onClose={onClose} wide>
      <form className="ad-form" onSubmit={save} noValidate>
        {message && <div className="ad-alert ad-alert--error ad-wide" role="alert">{message}</div>}
        {cfg.fields.map(field)}
        <div className="ad-form__foot">
          <button type="button" className="ad-btn ad-btn--ghost" onClick={onClose}>Cancel</button>
          <button className="ad-btn" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </form>
    </Modal>
  );
}

export default function Content({ kind }) {
  const cfg = KINDS[kind];
  const { user } = useAuth();
  const { notify, confirm } = useUi();
  const { data, error, loading, reload } = useLoad(cfg.path);
  const needs = kind === 'home-sections' || kind === 'categories';
  const brands = useLoad(needs ? 'brands' : null).data || [];
  const categories = useLoad(needs ? 'categories' : null).data || [];
  const lookups = useMemo(() => ({ brands, categories }), [brands, categories]);
  const [editing, setEditing] = useState(null); // null = closed, {} = new, row = edit

  const remove = async (r) => {
    const blocked = cfg.canDelete?.(r, user);
    if (blocked) return notify(blocked, 'error');
    if (!(await confirm(cfg.deleteText || `Delete this ${cfg.noun}?`, { ok: 'Delete', danger: true }))) return;
    try {
      await adminApi(`${cfg.path}/${r.id}`, { method: 'DELETE' });
      notify('Deleted.');
      reload();
    } catch (e) {
      notify(e.message, 'error');
    }
  };

  return (
    <>
      <PageHead title={cfg.title} sub={cfg.sub}>
        <button type="button" className="ad-btn" onClick={() => setEditing({})}>+ Add {cfg.noun}</button>
      </PageHead>
      <ErrorBox error={error} onRetry={reload} />
      {loading && !data ? <Loading /> : data && (data.length === 0 ? <Empty>Nothing here yet.</Empty> : (
        <div className="ad-table-wrap">
          <table className="ad-table" data-testid={`${kind}-table`}>
            <thead><tr>{cfg.columns.map(([h]) => <th key={h}>{h}</th>)}<th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {data.map((r) => (
                <tr key={r.id}>
                  {cfg.columns.map(([h, render]) => <td key={h} data-label={h}>{render(r)}</td>)}
                  <td data-label="" className="ad-row-actions">
                    <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" onClick={() => setEditing(r)}>Edit</button>
                    <button type="button" className="ad-btn ad-btn--sm ad-btn--danger-ghost" onClick={() => remove(r)}>Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
      {editing && <Editor kind={kind} cfg={cfg} row={editing.id ? editing : null} lookups={lookups} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />}
    </>
  );
}

