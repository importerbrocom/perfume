import { createContext, useCallback, useContext, useEffect, useId, useRef, useState } from 'react';
import { paymentLabel, statusLabel } from '../lib/format';

/* ---------- Form fields ---------- */

function Wrap({ label, error, help, children, id, wide, required }) {
  return (
    <div className={'ad-field' + (error ? ' has-error' : '') + (wide ? ' ad-field--wide' : '')}>
      {label && <label htmlFor={id}>{label}{required && <b aria-hidden="true"> *</b>}</label>}
      {children}
      {help && !error && <small className="ad-help">{help}</small>}
      {error && <small className="ad-error" id={id + '-err'}>{error}</small>}
    </div>
  );
}

export function Text({ label, value, onChange, error, help, wide, type = 'text', required, ...rest }) {
  const id = useId();
  return (
    <Wrap {...{ label, error, help, id, wide, required }}>
      <input id={id} type={type} value={value ?? ''} onChange={(e) => onChange(e.target.value)} aria-invalid={!!error} aria-describedby={error ? id + '-err' : undefined} required={required} {...rest} />
    </Wrap>
  );
}

export function Num({ label, value, onChange, error, help, wide, step = 'any', min = 0, ...rest }) {
  const id = useId();
  return (
    <Wrap {...{ label, error, help, id, wide }}>
      <input id={id} type="number" inputMode="decimal" step={step} min={min} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)} aria-invalid={!!error} {...rest} />
    </Wrap>
  );
}

export function Area({ label, value, onChange, error, help, rows = 4, wide = true, ...rest }) {
  const id = useId();
  return (
    <Wrap {...{ label, error, help, id, wide }}>
      <textarea id={id} rows={rows} value={value ?? ''} onChange={(e) => onChange(e.target.value)} aria-invalid={!!error} {...rest} />
    </Wrap>
  );
}

export function Select({ label, value, onChange, options, error, help, wide, empty, ...rest }) {
  const id = useId();
  return (
    <Wrap {...{ label, error, help, id, wide }}>
      <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)} aria-invalid={!!error} {...rest}>
        {empty !== undefined && <option value="">{empty}</option>}
        {options.map((o) => (Array.isArray(o) ? <option key={o[0]} value={o[0]}>{o[1]}</option> : <option key={o} value={o}>{o}</option>))}
      </select>
    </Wrap>
  );
}

export function Check({ label, checked, onChange, help, ...rest }) {
  return (
    <label className="ad-check">
      <input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} {...rest} />
      <span>{label}{help && <small>{help}</small>}</span>
    </label>
  );
}

/** Switch-style toggle for "visible / enabled" flags. */
export function Toggle({ label, checked, onChange, disabled }) {
  return (
    <label className={'ad-toggle' + (checked ? ' is-on' : '')}>
      <input type="checkbox" role="switch" checked={!!checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
      <span className="ad-toggle__track" aria-hidden="true"><span /></span>
      <span>{label}</span>
    </label>
  );
}

export function FileField({ label, accept = 'image/jpeg,image/png,image/webp', current, onChange, error, help, multiple, video }) {
  const id = useId();
  const [preview, setPreview] = useState(null);
  useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview]);
  const shown = preview || current;
  return (
    <Wrap {...{ label, error, help, id, wide: true }}>
      <div className="ad-file">
        {shown && !multiple && (video ? <video src={shown} muted playsInline className="ad-file__preview" /> : <img src={shown} alt="" className="ad-file__preview" />)}
        <input
          id={id} type="file" accept={accept} multiple={multiple}
          onChange={(e) => {
            const files = [...e.target.files];
            if (!multiple && files[0] && !video) setPreview(URL.createObjectURL(files[0]));
            onChange(multiple ? files : files[0] || null);
          }}
        />
      </div>
    </Wrap>
  );
}

/* ---------- Layout bits ---------- */

export function PageHead({ title, sub, children }) {
  return (
    <div className="ad-head">
      <div>
        <h1>{title}</h1>
        {sub && <p>{sub}</p>}
      </div>
      {children && <div className="ad-head__actions">{children}</div>}
    </div>
  );
}

export const Card = ({ title, children, actions, className = '' }) => (
  <section className={'ad-card ' + className}>
    {(title || actions) && (
      <div className="ad-card__head">
        {title && <h2>{title}</h2>}
        {actions}
      </div>
    )}
    {children}
  </section>
);

export const Loading = ({ text = 'Loading…' }) => <p className="ad-muted" role="status">{text}</p>;
export const ErrorBox = ({ error, onRetry }) =>
  error ? (
    <div className="ad-alert ad-alert--error" role="alert">
      {error.message || String(error)}
      {onRetry && <button type="button" className="ad-btn ad-btn--sm ad-btn--ghost" onClick={onRetry}>Try again</button>}
    </div>
  ) : null;
export const Empty = ({ children }) => <div className="ad-empty">{children}</div>;

export function Pager({ meta, onPage }) {
  if (!meta || meta.pages <= 1) return meta ? <p className="ad-muted ad-pager">{meta.total} total</p> : null;
  return (
    <nav className="ad-pager" aria-label="Pages">
      <button type="button" className="ad-btn ad-btn--ghost ad-btn--sm" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>‹ Previous</button>
      <span>Page {meta.page} of {meta.pages} · {meta.total} total</span>
      <button type="button" className="ad-btn ad-btn--ghost ad-btn--sm" disabled={meta.page >= meta.pages} onClick={() => onPage(meta.page + 1)}>Next ›</button>
    </nav>
  );
}

export function Tabs({ value, onChange, items }) {
  return (
    <div className="ad-tabs" role="tablist">
      {items.map(([v, label, count]) => (
        <button key={v || 'all'} type="button" role="tab" aria-selected={value === v} className={value === v ? 'is-on' : ''} onClick={() => onChange(v)}>
          {label}{count !== undefined && count !== null && <span className="ad-count">{count}</span>}
        </button>
      ))}
    </div>
  );
}

export const StatusPill = ({ status }) => <span className={'ad-pill ad-pill--' + status}>{statusLabel(status)}</span>;
export const PayPill = ({ status }) => <span className={'ad-pill ad-pill--pay-' + status}>{paymentLabel(status)}</span>;

/* ---------- Modal ---------- */

export function Modal({ title, onClose, children, footer, wide }) {
  const box = useRef(null);
  useEffect(() => {
    const prev = document.activeElement;
    const first = box.current?.querySelector('input,select,textarea,button:not(.ad-modal__x)');
    (first || box.current)?.focus();
    const f = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', f);
    document.documentElement.classList.add('ad-lock');
    return () => {
      document.removeEventListener('keydown', f);
      document.documentElement.classList.remove('ad-lock');
      prev?.focus?.();
    };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className="ad-overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={'ad-modal' + (wide ? ' ad-modal--wide' : '')} role="dialog" aria-modal="true" aria-label={title} ref={box} tabIndex={-1}>
        <div className="ad-modal__head">
          <h2>{title}</h2>
          <button type="button" className="ad-modal__x" aria-label="Close" onClick={onClose}>×</button>
        </div>
        <div className="ad-modal__body">{children}</div>
        {footer && <div className="ad-modal__foot">{footer}</div>}
      </div>
    </div>
  );
}

/* ---------- Confirm + notices (one provider for the whole admin) ---------- */

const UiContext = createContext(null);

export function UiProvider({ children }) {
  const [confirmState, setConfirm] = useState(null);
  const [notes, setNotes] = useState([]);
  const notify = useCallback((text, type = 'ok') => {
    const id = Math.random().toString(36).slice(2);
    setNotes((l) => [...l, { id, text, type }]);
    setTimeout(() => setNotes((l) => l.filter((n) => n.id !== id)), type === 'error' ? 6000 : 3000);
  }, []);
  const confirm = useCallback((message, { ok = 'Yes, continue', danger = false } = {}) => new Promise((resolve) => setConfirm({ message, ok, danger, resolve })), []);
  const close = (v) => {
    confirmState?.resolve(v);
    setConfirm(null);
  };
  return (
    <UiContext.Provider value={{ notify, confirm }}>
      {children}
      {confirmState && (
        <Modal
          title="Please confirm" onClose={() => close(false)}
          footer={<>
            <button type="button" className="ad-btn ad-btn--ghost" onClick={() => close(false)}>Cancel</button>
            <button type="button" className={'ad-btn' + (confirmState.danger ? ' ad-btn--danger' : '')} onClick={() => close(true)}>{confirmState.ok}</button>
          </>}
        >
          <p>{confirmState.message}</p>
        </Modal>
      )}
      <div className="ad-notes" role="status" aria-live="polite">
        {notes.map((n) => <div key={n.id} className={'ad-note' + (n.type === 'error' ? ' ad-note--error' : '')}>{n.text}</div>)}
      </div>
    </UiContext.Provider>
  );
}

export const useUi = () => useContext(UiContext);
