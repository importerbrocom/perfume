import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useBlocker, useNavigate, useParams } from 'react-router-dom';
import { adminApi, fieldErrors, num, useLoad } from '../lib';
import { Area, Card, Check, ErrorBox, Loading, Num, PageHead, Select, Text, Toggle, useUi } from '../ui';
import { editable } from './Products';
import ProductPicker from '../ProductPicker';

const blankVariant = () => ({ id: null, name: '', sku: '', price: null, sale_price: null, track_stock: true, stock_quantity: 0, stock_status: 'in_stock', low_stock_threshold: null, is_active: true, _key: Math.random() });
const blank = {
  name: '', slug: '', sku: '', brand_id: null, tagline: '', inspired_by: '', short_description: '', description: '', notes_top: '', notes_middle: '', notes_base: '',
  accords: '', fragrance_family: '', perfume_type: '', concentration: '', gender: null, traits: '', longevity: '', is_active: true, is_featured: false,
  is_new_arrival: false, is_best_seller: false, is_combo: false, sort_order: 0, meta_title: '', meta_description: '', category_ids: [], related_ids: [],
  variants: [{ ...blankVariant(), name: '100 ml' }],
};

function Variants({ rows, setRows, errors }) {
  const upd = (i, patch) => setRows(rows.map((r, n) => (n === i ? { ...r, ...patch } : r)));
  const move = (i, d) => {
    const next = [...rows];
    [next[i], next[i + d]] = [next[i + d], next[i]];
    setRows(next);
  };
  return (
    <div className="ad-variants">
      {rows.map((v, i) => {
        const e = (k) => errors[`variants.${i}.${k}`];
        return (
          <fieldset key={v.id || v._key} className={'ad-variant' + (v.is_active ? '' : ' is-off')} data-testid="variant-row">
            <legend>Size {i + 1}</legend>
            <div className="ad-form">
              <Text label="Size name" required value={v.name} onChange={(x) => upd(i, { name: x })} placeholder="50 ml, 100 ml, Gift set…" maxLength={100} error={e('name')} />
              <Text label="SKU" value={v.sku} onChange={(x) => upd(i, { sku: x })} maxLength={64} error={e('sku')} />
              <Num label="Price" value={v.price} onChange={(x) => upd(i, { price: x })} error={e('price')} help="Empty = “price on request” (not orderable)" />
              <Num label="Sale price" value={v.sale_price} onChange={(x) => upd(i, { sale_price: x })} error={e('sale_price')} help="Optional, lower than the price" />
              {v.track_stock ? (
                <Num label="Stock (units)" step="1" value={v.stock_quantity} onChange={(x) => upd(i, { stock_quantity: x })} error={e('stock_quantity')} />
              ) : (
                <Select label="Availability" value={v.stock_status} onChange={(x) => upd(i, { stock_status: x || 'in_stock' })} options={[['in_stock', 'In stock'], ['out_of_stock', 'Out of stock']]} />
              )}
              <Num label="Low-stock alert at" step="1" value={v.low_stock_threshold} onChange={(x) => upd(i, { low_stock_threshold: x })} help="Empty = store default" />
            </div>
            <div className="ad-variant__foot">
              <Check label="Count stock" checked={v.track_stock} onChange={(x) => upd(i, { track_stock: x })} />
              <Check label="Available in shop" checked={v.is_active} onChange={(x) => upd(i, { is_active: x })} />
              <span className="ad-spacer" />
              <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" disabled={i === 0} onClick={() => move(i, -1)} aria-label={`Move size ${i + 1} up`}>↑</button>
              <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" disabled={i === rows.length - 1} onClick={() => move(i, 1)} aria-label={`Move size ${i + 1} down`}>↓</button>
              <button type="button" className="ad-btn ad-btn--sm ad-btn--danger-ghost" disabled={rows.length === 1} onClick={() => setRows(rows.filter((_, n) => n !== i))}>Remove</button>
            </div>
          </fieldset>
        );
      })}
      {rows.length < 20 && <button type="button" className="ad-btn ad-btn--ghost" onClick={() => setRows([...rows, blankVariant()])}>+ Add size</button>}
    </div>
  );
}

function Images({ product, onChange }) {
  const { notify, confirm } = useUi();
  const [busy, setBusy] = useState(false);
  const input = useRef(null);
  const [list, setList] = useState(product.images);
  useEffect(() => setList(product.images), [product.images]);
  const upload = async (files) => {
    if (!files.length) return;
    setBusy(true);
    const fd = new FormData();
    files.forEach((f) => fd.append('images[]', f));
    try {
      onChange(await adminApi(`products/${product.id}/images`, { method: 'POST', body: fd }));
      notify(`${files.length} image${files.length === 1 ? '' : 's'} uploaded.`);
    } catch (e) {
      notify(e.message, 'error');
    } finally {
      setBusy(false);
      if (input.current) input.current.value = '';
    }
  };
  const saveOrder = async (next) => {
    setList(next);
    try {
      onChange(await adminApi(`products/${product.id}/images`, { method: 'PUT', body: { images: next.map((i) => ({ id: i.id, alt: i.alt })) } }));
    } catch (e) {
      notify(e.message, 'error');
    }
  };
  const remove = async (img) => {
    if (!(await confirm('Delete this image?', { ok: 'Delete', danger: true }))) return;
    try {
      await adminApi(`products/${product.id}/images/${img.id}`, { method: 'DELETE' });
      onChange({ ...product, images: list.filter((x) => x.id !== img.id) });
    } catch (e) {
      notify(e.message, 'error');
    }
  };
  const move = (i, d) => {
    const next = [...list];
    [next[i], next[i + d]] = [next[i + d], next[i]];
    saveOrder(next);
  };
  return (
    <>
      {list.length === 0 && <p className="ad-muted">No images yet. The first image is the main photo in the shop.</p>}
      <ul className="ad-images">
        {list.map((img, i) => (
          <li key={img.id}>
            <img src={img.thumb || img.url} alt={img.alt || ''} />
            {i === 0 && <span className="ad-badge">Main</span>}
            <input aria-label="Image description" placeholder="Description (alt text)" value={img.alt || ''} maxLength={200}
              onChange={(e) => setList(list.map((x) => (x.id === img.id ? { ...x, alt: e.target.value } : x)))} onBlur={() => saveOrder(list)} />
            <div className="ad-images__btns">
              <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" disabled={i === 0} onClick={() => move(i, -1)} aria-label="Move left">←</button>
              <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" disabled={i === list.length - 1} onClick={() => move(i, 1)} aria-label="Move right">→</button>
              <button type="button" className="ad-btn ad-btn--sm ad-btn--danger-ghost" onClick={() => remove(img)}>Delete</button>
            </div>
          </li>
        ))}
      </ul>
      <label className="ad-btn ad-btn--ghost ad-upload">
        {busy ? 'Uploading…' : '+ Upload images'}
        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={busy} onChange={(e) => upload([...e.target.files])} />
      </label>
      <p className="ad-help">JPG, PNG or WebP, at least 200×200 px, up to 8 MB each, 15 images per product.</p>
    </>
  );
}

export default function ProductEdit() {
  const { id } = useParams();
  const isNew = !id;
  const navigate = useNavigate();
  const { notify } = useUi();
  const loaded = useLoad(isNew ? null : `products/${id}`);
  const brands = useLoad('brands').data || [];
  const cats = useLoad('categories').data || [];
  const [product, setProduct] = useState(null);
  const [form, setForm] = useState(isNew ? blank : null);
  const [initial, setInitial] = useState(isNew ? JSON.stringify(blank) : null);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (loaded.data) {
      const f = editable(loaded.data);
      f.variants = f.variants.map((v) => ({ ...v, _key: v.id }));
      setProduct(loaded.data);
      setForm(f);
      setInitial(JSON.stringify(f));
    }
  }, [loaded.data]);
  useEffect(() => {
    if (isNew && brands.length && form && !form.brand_id) setForm((f) => ({ ...f, brand_id: brands[0].id }));
  }, [isNew, brands.length]); // eslint-disable-line react-hooks/exhaustive-deps

  const dirty = form && initial && JSON.stringify(form) !== initial;
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && !busy && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    if (blocker.state === 'blocked') {
      // eslint-disable-next-line no-alert
      if (window.confirm('You have unsaved changes. Leave without saving?')) blocker.proceed();
      else blocker.reset();
    }
  }, [blocker]);
  useEffect(() => {
    const f = (e) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', f);
    return () => window.removeEventListener('beforeunload', f);
  }, [dirty]);

  const catGroups = useMemo(() => [['category', 'Categories'], ['collection', 'Collections']].map(([k, l]) => [l, cats.filter((c) => c.kind === k)]), [cats]);
  if (loaded.error) return <ErrorBox error={loaded.error} onRetry={loaded.reload} />;
  if (!form) return <Loading />;
  const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));

  const save = async (e) => {
    e?.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    const body = {
      ...form,
      brand_id: num(form.brand_id),
      sort_order: num(form.sort_order) ?? 0,
      slug: form.slug || null,
      sku: form.sku || null,
      gender: form.gender || null,
      variants: form.variants.map(({ _key, _stock0, ...v }) => ({ // eslint-disable-line no-unused-vars
        ...v, sku: v.sku || null, price: num(v.price), sale_price: num(v.sale_price),
        // Unchanged stock is sent as null so the server keeps the live number (orders may have come in meanwhile).
        stock_quantity: !v.track_stock ? null : v.id && num(v.stock_quantity) === _stock0 ? null : num(v.stock_quantity) ?? 0,
        low_stock_threshold: num(v.low_stock_threshold),
      })),
    };
    try {
      const saved = await adminApi(isNew ? 'products' : `products/${id}`, { method: isNew ? 'POST' : 'PUT', body });
      const f = editable(saved);
      f.variants = f.variants.map((v) => ({ ...v, _key: v.id }));
      setProduct(saved);
      setForm(f);
      setInitial(JSON.stringify(f));
      notify(isNew ? 'Product created. Now add photos.' : 'Product saved.');
      if (isNew) navigate(`/admin/products/${saved.id}`, { replace: true });
    } catch (err) {
      setErrors(fieldErrors(err));
      setMessage(err.message);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={save} noValidate>
      <PageHead title={isNew ? 'Add product' : form.name || 'Edit product'} sub={isNew ? 'Fill in the details and at least one size. You can add photos after saving.' : undefined}>
        <Link className="ad-btn ad-btn--ghost" to="/admin/products">← Products</Link>
        {product && <a className="ad-btn ad-btn--ghost" href={`/product/${product.slug}`} target="_blank" rel="noopener noreferrer">View in shop ↗</a>}
      </PageHead>
      {message && <div className="ad-alert ad-alert--error" role="alert">{message}</div>}

      <div className="ad-grid2 ad-grid2--detail">
        <div className="ad-stack">
          <Card title="Details">
            <div className="ad-form">
              <Text label="Name" required wide value={form.name} onChange={set('name')} maxLength={200} error={errors.name} />
              <Text label="Tagline" wide value={form.tagline} onChange={set('tagline')} maxLength={200} placeholder="Long-lasting eau de parfum" error={errors.tagline} />
              <Text label="Inspired by" value={form.inspired_by} onChange={set('inspired_by')} maxLength={100} placeholder="Dior, Chanel…" error={errors.inspired_by} help="Fixnote perfumes are grouped by this on the Fixnote page." />
              <Area label="Short description" rows={2} value={form.short_description} onChange={set('short_description')} maxLength={2000} error={errors.short_description} help="Shown next to the price." />
              <Area label="Full description" rows={6} value={form.description} onChange={set('description')} maxLength={20000} error={errors.description} help="Plain text or simple HTML (paragraphs, bold, lists, links)." />
            </div>
          </Card>
          <Card title="Sizes, prices & stock">
            {errors.variants && <p className="ad-error">{errors.variants}</p>}
            <Variants rows={form.variants} setRows={set('variants')} errors={errors} />
          </Card>
          <Card title="Fragrance">
            <div className="ad-form">
              <Text label="Top notes" value={form.notes_top} onChange={set('notes_top')} maxLength={500} />
              <Text label="Heart notes" value={form.notes_middle} onChange={set('notes_middle')} maxLength={500} />
              <Text label="Base notes" value={form.notes_base} onChange={set('notes_base')} maxLength={500} />
              <Text label="Main accords" value={form.accords} onChange={set('accords')} maxLength={500} help="Fixnote page: the notes shown on the perfume card." />
              <Text label="Fragrance family" value={form.fragrance_family} onChange={set('fragrance_family')} maxLength={100} placeholder="Woody, Oriental…" />
              <Text label="Concentration" value={form.concentration} onChange={set('concentration')} maxLength={100} placeholder="Eau de Parfum" />
              <Text label="Perfume type" value={form.perfume_type} onChange={set('perfume_type')} maxLength={100} placeholder="Original, Inspired…" />
              <Select label="For" value={form.gender} onChange={set('gender')} empty="—" options={[['men', 'Men'], ['women', 'Women'], ['unisex', 'Unisex']]} />
              <Text label="Longevity" value={form.longevity} onChange={set('longevity')} maxLength={100} placeholder="12–24 hours" />
              <Text label="Traits" value={form.traits} onChange={set('traits')} maxLength={200} placeholder="Long lasting · High projection" />
            </div>
          </Card>
          {product ? <Card title="Photos"><Images product={product} onChange={(p) => setProduct(p)} /></Card> : <Card title="Photos"><p className="ad-muted">Save the product first, then upload photos here.</p></Card>}
        </div>

        <div className="ad-stack">
          <Card title="Shop">
            <Toggle label={form.is_active ? 'Visible in the shop' : 'Hidden from the shop'} checked={form.is_active} onChange={set('is_active')} />
            <div className="ad-checks">
              <Check label="Featured" checked={form.is_featured} onChange={set('is_featured')} />
              <Check label="New arrival" checked={form.is_new_arrival} onChange={set('is_new_arrival')} />
              <Check label="Best seller" checked={form.is_best_seller} onChange={set('is_best_seller')} />
              <Check label="Combo / gift set" checked={form.is_combo} onChange={set('is_combo')} />
            </div>
            <Select label="Brand" value={form.brand_id ?? ''} onChange={set('brand_id')} empty="No brand" options={brands.map((b) => [b.id, b.name])} error={errors.brand_id} />
            <Num label="Position in shop" step="1" value={form.sort_order} onChange={set('sort_order')} help="Lower numbers show first." />
          </Card>
          <Card title="Categories">
            {catGroups.map(([label, list]) => list.length > 0 && (
              <fieldset key={label} className="ad-checks">
                <legend>{label}</legend>
                {list.map((c) => (
                  <Check key={c.id} label={c.name} checked={form.category_ids.includes(c.id)} onChange={(on) => set('category_ids')(on ? [...form.category_ids, c.id] : form.category_ids.filter((x) => x !== c.id))} />
                ))}
              </fieldset>
            ))}
            <Link className="ad-link" to="/admin/categories">Manage categories</Link>
          </Card>
          <Card title="Related products"><ProductPicker label="“You may also like” / combo products" ids={form.related_ids} setIds={set('related_ids')} selfId={product?.id} max={12} /></Card>
          <Card title="Search engines & codes">
            <Text label="Web address (slug)" value={form.slug} onChange={set('slug')} maxLength={200} error={errors.slug} help={product ? `/product/${product.slug}` : 'Made from the name if empty.'} />
            <Text label="Product SKU" value={form.sku} onChange={set('sku')} maxLength={64} error={errors.sku} />
            <Text label="SEO title" value={form.meta_title} onChange={set('meta_title')} maxLength={200} />
            <Area label="SEO description" rows={3} value={form.meta_description} onChange={set('meta_description')} maxLength={500} />
          </Card>
        </div>
      </div>

      <div className="ad-savebar">
        <span>{dirty ? 'Unsaved changes' : isNew ? '' : 'All changes saved'}</span>
        <button className="ad-btn" disabled={busy || (!dirty && !isNew)} data-testid="save-product">{busy ? 'Saving…' : isNew ? 'Create product' : 'Save changes'}</button>
      </div>
    </form>
  );
}
