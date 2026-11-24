import { COUNTRIES } from '../lib/countries';

/** Checkbox list of countries (shipping countries, payment method limits). */
export default function Countries({ label, value, onChange, help, error }) {
  const list = value || [];
  const toggle = (code, on) => onChange(on ? [...list, code] : list.filter((c) => c !== code));
  return (
    <fieldset className="ad-field ad-field--wide ad-countries">
      <legend>{label}</legend>
      <div>
        {Object.entries(COUNTRIES).map(([code, name]) => (
          <label key={code} className="ad-check">
            <input type="checkbox" checked={list.includes(code)} onChange={(e) => toggle(code, e.target.checked)} />
            <span>{name}</span>
          </label>
        ))}
      </div>
      {help && !error && <small className="ad-help">{help}</small>}
      {error && <small className="ad-error">{error}</small>}
    </fieldset>
  );
}
